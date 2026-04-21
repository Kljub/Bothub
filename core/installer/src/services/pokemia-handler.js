// PFAD: /core/installer/src/services/pokemia-handler.js
// Handles all button interactions for Pokemia (battles, challenges, starters).
const {
    EmbedBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle,
} = require('discord.js');
const { getSpecies }          = require('../data/pokemia-species');
const { getMove, randomNature } = require('../data/pokemia-moves');
const {
    buildBattlePokemon, aiPickMove, executeTurn, hpBar,
    createBattle, getBattle, endBattle, getBattleByUserId,
    getChallenge, endChallenge,
} = require('./pokemia-battle-engine');
const {
    getOrCreatePkmUser, setSelectedPokemon, getSelectedPokemon,
    catchPokemon, getPokemonByIndex, addXp, xpToNextLevel,
} = require('./pokemia-service');

// ── Re-used from pokemia.js (duplicated for independence) ─────────────────────
function hpEmoji(pct) {
    return pct > 0.5 ? '🟩' : pct > 0.25 ? '🟨' : '🟥';
}

function battleEmbed(battle, title = '⚔️ Pokemia Battle', extraText = '') {
    const [t0, t1] = battle.trainers;
    const p0 = t0.pokemon;
    const p1 = t1.pokemon;

    const ailmentIcon = (p) => [...p.ailments].map(a => ({
        burn: '🔥', poison: '☠️', paralysis: '⚡', freeze: '❄️', sleep: '💤',
    }[a] || '')).join('');

    const embed = new EmbedBuilder()
        .setTitle(title)
        .setColor(battle.ended ? 0x95a5a6 : 0xe63946)
        .addFields(
            {
                name:   `🔴 <@${t0.userId}>`,
                value:  `${p0.shiny ? '✨ ' : ''}**${p0.name}** Lv.${p0.level} ${ailmentIcon(p0)}\n${hpBar(p0.hp, p0.maxHp)}`,
                inline: true,
            },
            { name: '\u200b', value: 'VS', inline: true },
            {
                name:   `🔵 <@${t1.userId}>`,
                value:  `${p1.shiny ? '✨ ' : ''}**${p1.name}** Lv.${p1.level} ${ailmentIcon(p1)}\n${hpBar(p1.hp, p1.maxHp)}`,
                inline: true,
            }
        );

    if (extraText) embed.setDescription(extraText);
    return embed;
}

function moveButtons(pokemon, userId, disabled = false) {
    const buttons = pokemon.moves.slice(0, 4).map((m, i) => {
        const md = getMove(m.id);
        return new ButtonBuilder()
            .setCustomId(`pkm_move_${i}_${userId}`)
            .setLabel(md ? `${md.name} (${m.pp})` : '???')
            .setStyle(ButtonStyle.Primary)
            .setDisabled(disabled || m.pp <= 0);
    });

    if (buttons.length === 0) {
        buttons.push(
            new ButtonBuilder()
                .setCustomId(`pkm_move_0_${userId}`)
                .setLabel('Tackle (Verzweifler)')
                .setStyle(ButtonStyle.Secondary)
                .setDisabled(disabled)
        );
    }

    return [
        new ActionRowBuilder().addComponents(...buttons),
        new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId(`pkm_flee_${userId}`)
                .setLabel('🏃 Aufgeben')
                .setStyle(ButtonStyle.Danger)
                .setDisabled(disabled)
        ),
    ];
}

function logToText(log) {
    return log.map(e => {
        switch (e.type) {
            case 'move': {
                let line = `**${e.moveName}**`;
                if (e.missed)   return `${line} — danebengegangen!`;
                if (e.blocked)  return ({ freeze: '❄️ eingefroren', sleep: '💤 schläft', paralysis: '⚡ gelähmt' }[e.blocked] || e.blocked) + ' — kann nicht angreifen!';
                if (e.damage)   line += ` → ${e.damage} Schaden`;
                if (e.effText)  line += ` ${e.effText}`;
                if (e.heal)     line += `, heilt ${e.heal} HP`;
                if (e.recoil)   line += `, ${e.recoil} Rückstoß`;
                if (e.ailment)  line += ` — ${e.ailment}!`;
                if (e.selfHeal) line += `, +${e.selfHeal} HP`;
                if (e.statChange) {
                    const sc = e.statChange;
                    line += ` — ${sc.stat}${sc.change > 0 ? '↑' : '↓'}`;
                }
                if (e.messages?.length) line += '\n' + e.messages.join('\n');
                return line;
            }
            case 'faint':      return `💀 **${e.pokemon}** besiegt!`;
            case 'flee':       return `🏃 <@${e.user}> aufgegeben!`;
            case 'ailment_dmg': return `${{ burn: '🔥', poison: '☠️' }[e.ailment] || ''} **${e.pokemon}** −${e.damage} (${e.ailment})`;
            default: return '';
        }
    }).filter(Boolean).join('\n');
}

// ── Battle finish ──────────────────────────────────────────────────────────────
async function finishBattle(interaction, battle, botId) {
    const winnerId = battle.winner;
    const [t0, t1] = battle.trainers;

    // XP gain for winner's pokemon
    const winner = battle.trainers.find(t => t.userId === winnerId);
    const loser  = battle.trainers.find(t => t.userId !== winnerId);

    let xpMessages = [];
    if (winner && !battle.isBot || (battle.isBot && winnerId === t0.userId)) {
        // Give XP to the player's pokemon
        const playerTrainer = battle.isBot ? t0 : winner;
        if (battle.playerRow || playerTrainer) {
            try {
                const baseXp = loser ? Math.floor(loser.pokemon.level * 5) : 10;
                const pkRow  = battle.playerRow
                    || await getPokemonByIndex(botId, playerTrainer.userId, 1);
                if (pkRow) {
                    const xpResult = await addXp(botId, pkRow, baseXp);
                    xpMessages = xpResult.messages.map(m =>
                        `${playerTrainer.pokemon.name}: ${m}`
                    );
                }
            } catch (_) {}
        }
    }

    const endEmbed = battleEmbed(
        battle,
        '⚔️ Kampf beendet!',
        winnerId
            ? `🏆 <@${winnerId}> hat gewonnen!\n${xpMessages.join('\n')}`
            : '⚖️ Unentschieden!'
    );

    endBattle(battle.key);

    try {
        await interaction.update({
            embeds:     [endEmbed],
            components: [],
        });
    } catch (_) {
        try { await interaction.message.edit({ embeds: [endEmbed], components: [] }); } catch (_) {}
    }
}

// ── Main dispatcher ────────────────────────────────────────────────────────────
async function handlePkmButton(interaction, botId) {
    const id = interaction.customId;

    if (id.startsWith('pkm_starter_'))  return handleStarter(interaction, botId, id);
    if (id.startsWith('pkm_accept_'))   return handleAccept(interaction, botId, id);
    if (id.startsWith('pkm_decline_'))  return handleDecline(interaction, botId, id);
    if (id.startsWith('pkm_move_'))     return handleMove(interaction, botId, id);
    if (id.startsWith('pkm_flee_'))     return handleFlee(interaction, botId, id);
}

// ── Starter selection ──────────────────────────────────────────────────────────
async function handleStarter(interaction, botId, id) {
    // pkm_starter_<speciesId>_<userId>
    const parts     = id.split('_');
    const speciesId = Number(parts[2]);
    const targetId  = parts[3];

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht deine Auswahl.', ephemeral: true });
    }

    const user = await getOrCreatePkmUser(botId, targetId);
    if (user.selected_id) {
        return interaction.update({ content: '⚠️ Du hast bereits ein Starter-Pokémon gewählt.', embeds: [], components: [] });
    }

    const caught  = await catchPokemon(botId, targetId, speciesId, { level: 5 });
    const species = getSpecies(speciesId);
    const shiny   = caught.shiny ? ' ✨ Shiny!' : '';

    const embed = new EmbedBuilder()
        .setTitle(`🎉 Willkommen in der Welt von Pokemia!${shiny}`)
        .setDescription(
            `Du hast **${species?.name || '???'}** als dein Starter-Pokémon gewählt!\n\n` +
            `Nutze \`/pokemia catch\` um wilde Pokémon zu fangen, wenn sie in einem Channel erscheinen.\n` +
            `Kämpfe mit \`/pokemia battle bot\` oder \`/pokemia battle user\`.`
        )
        .setColor(0x27ae60);

    return interaction.update({ embeds: [embed], components: [] });
}

// ── Challenge: Accept ──────────────────────────────────────────────────────────
async function handleAccept(interaction, botId, id) {
    // pkm_accept_<challengeKey>_<targetId>
    // challengeKey = pkm_chal_<botId>_<challengerId>_<targetId>
    const lastUnderscore = id.lastIndexOf('_');
    const targetId       = id.slice(lastUnderscore + 1);
    const challengeKey   = id.slice('pkm_accept_'.length, lastUnderscore);

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Diese Herausforderung gilt nicht für dich.', ephemeral: true });
    }

    const challenge = getChallenge(challengeKey);
    if (!challenge) {
        return interaction.update({ content: '⏰ Diese Herausforderung ist abgelaufen.', embeds: [], components: [] });
    }

    endChallenge(challengeKey);

    const { challengerId } = challenge;

    if (getBattleByUserId(challengerId) || getBattleByUserId(targetId)) {
        return interaction.update({ content: '⚠️ Einer der Spieler ist bereits in einem Kampf.', embeds: [], components: [] });
    }

    const [challengerRow, targetRow] = await Promise.all([
        getSelectedPokemon(botId, challengerId),
        getSelectedPokemon(botId, targetId),
    ]);

    if (!challengerRow) {
        return interaction.update({ content: `❌ <@${challengerId}> hat kein aktives Pokémon.`, embeds: [], components: [] });
    }
    if (!targetRow) {
        return interaction.update({ content: '❌ Du hast kein aktives Pokémon. Starte mit `/pokemia start`!', embeds: [], components: [] });
    }

    const challengerPkm = buildBattlePokemon(challengerRow);
    const targetPkm     = buildBattlePokemon(targetRow);

    const battleKey = `pvp_${botId}_${challengerId}_${targetId}`;
    const battle    = createBattle(battleKey, 'pvp', [
        { userId: challengerId, pokemon: challengerPkm },
        { userId: targetId,     pokemon: targetPkm     },
    ]);
    battle.started      = true;
    battle.botId        = botId;
    battle.currentTurn  = challengerId; // challenger goes first
    battle.playerRows   = {
        [challengerId]: challengerRow,
        [targetId]:     targetRow,
    };

    const embed   = battleEmbed(battle, '⚔️ Pokemia 1v1!',
        `Erster Zug: <@${challengerId}>\nWähle deinen Zug:`);
    const buttons = moveButtons(challengerPkm, challengerId);

    return interaction.update({ embeds: [embed], components: buttons });
}

// ── Challenge: Decline ─────────────────────────────────────────────────────────
async function handleDecline(interaction, botId, id) {
    const lastUnderscore = id.lastIndexOf('_');
    const targetId       = id.slice(lastUnderscore + 1);

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht deine Entscheidung.', ephemeral: true });
    }

    const challengeKey = id.slice('pkm_decline_'.length, lastUnderscore);
    endChallenge(challengeKey);

    return interaction.update({
        content:    `❌ <@${targetId}> hat die Herausforderung abgelehnt.`,
        embeds:     [],
        components: [],
    });
}

// ── Move ──────────────────────────────────────────────────────────────────────
async function handleMove(interaction, botId, id) {
    // pkm_move_<index>_<userId>
    const parts    = id.split('_');
    const moveIdx  = Number(parts[2]);
    const targetId = parts[3];

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Zug.', ephemeral: true });
    }

    const battle = getBattleByUserId(targetId);
    if (!battle) {
        return interaction.update({ content: '⏰ Kein laufender Kampf gefunden.', embeds: [], components: [] });
    }

    const playerTrainer   = battle.trainers.find(t => t.userId === targetId);
    const opponentTrainer = battle.trainers.find(t => t.userId !== targetId);

    if (!playerTrainer) {
        return interaction.reply({ content: '❌ Du bist nicht Teil dieses Kampfes.', ephemeral: true });
    }

    // PvP: check if it's this player's turn
    if (battle.type === 'pvp' && battle.currentTurn !== targetId) {
        return interaction.reply({ content: '⏳ Warte auf deinen Zug.', ephemeral: true });
    }

    // Get move from player's pokemon
    const playerPkm = playerTrainer.pokemon;
    const move      = playerPkm.moves[moveIdx];
    if (!move || !getMove(move.id)) {
        return interaction.reply({ content: '❌ Ungültiger Zug.', ephemeral: true });
    }

    const playerAction = {
        type:     'move',
        moveId:   move.id,
        priority: getMove(move.id)?.pri ?? 0,
    };
    move.pp = Math.max(0, move.pp - 1);

    let log;

    if (battle.type === 'pve') {
        // Bot picks a move
        const aiAction = aiPickMove(opponentTrainer.pokemon, playerPkm, battle.difficulty);
        if (opponentTrainer.pokemon.moves[0]) {
            const aiMoveEntry = opponentTrainer.pokemon.moves.find(m => m.id === aiAction.moveId);
            if (aiMoveEntry) aiMoveEntry.pp = Math.max(0, aiMoveEntry.pp - 1);
        }
        log = executeTurn(battle, playerAction, aiAction);
    } else {
        // PvP: store action, switch turn
        battle.pendingActions = battle.pendingActions || {};
        battle.pendingActions[targetId] = playerAction;

        // Switch turn to opponent
        battle.currentTurn = opponentTrainer.userId;

        const waitEmbed = battleEmbed(battle, '⚔️ Pokemia 1v1!',
            `<@${targetId}> hat seinen Zug gewählt.\nJetzt ist <@${opponentTrainer.userId}> dran!`
        );
        const oppButtons = moveButtons(opponentTrainer.pokemon, opponentTrainer.userId);
        return interaction.update({ embeds: [waitEmbed], components: oppButtons });
    }

    // Render result
    const logText = logToText(log);

    if (battle.ended) {
        await finishBattle(interaction, battle, botId);
        return;
    }

    const embed = battleEmbed(battle, '⚔️ Pokemia Battle', logText);
    const buttons = moveButtons(playerPkm, targetId);
    return interaction.update({ embeds: [embed], components: buttons });
}

// ── Flee ──────────────────────────────────────────────────────────────────────
async function handleFlee(interaction, botId, id) {
    // pkm_flee_<userId>
    const targetId = id.slice('pkm_flee_'.length);

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Kampf.', ephemeral: true });
    }

    const battle = getBattleByUserId(targetId);
    if (!battle) {
        return interaction.update({ content: '⏰ Kein laufender Kampf.', embeds: [], components: [] });
    }

    const opponentTrainer = battle.trainers.find(t => t.userId !== targetId);
    const fleeAction = { type: 'flee', priority: 99 };
    const dummyAction = { type: 'move', moveId: null, priority: 0 };

    const isFirst = battle.trainers[0].userId === targetId;
    executeTurn(battle, isFirst ? fleeAction : dummyAction, isFirst ? dummyAction : fleeAction);
    battle.winner = opponentTrainer?.userId || null;
    battle.ended  = true;

    await finishBattle(interaction, battle, botId);
}

module.exports = { handlePkmButton };
