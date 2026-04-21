// PFAD: /core/installer/src/commands/pokemia/pokemia.js
const {
    SlashCommandBuilder, EmbedBuilder,
    ActionRowBuilder, ButtonBuilder, ButtonStyle,
} = require('discord.js');
const { getSpecies, randomWildSpecies } = require('../../data/pokemia-species');
const { getMove, randomNature, NATURES }  = require('../../data/pokemia-moves');
const {
    buildBattlePokemon, aiPickMove, executeTurn, hpBar,
    createBattle, getBattle, endBattle, getBattleByUserId,
    createChallenge, getChallenge, endChallenge,
} = require('../../services/pokemia-battle-engine');
const {
    getOrCreatePkmUser, setSelectedPokemon, getSelectedPokemon,
    catchPokemon, getUserPokemon, getUserPokemonCount, getPokemonByIndex,
    xpToNextLevel, addXp, getActiveSpawn, markSpawnCaught,
    calcTrainingXp, startTraining, isTraining, trainingMsLeft,
    formatTrainingTime, checkAndFinishTraining,
} = require('../../services/pokemia-service');

// ── Starters ───────────────────────────────────────────────────────────────────
const STARTERS = [
    { id: 1,  emoji: '🌿', name: 'Bisasam'   },
    { id: 4,  emoji: '🔥', name: 'Glumanda'  },
    { id: 7,  emoji: '💧', name: 'Schiggy'   },
];

// ── Battle helpers ─────────────────────────────────────────────────────────────
function battleEmbed(battle, title = '⚔️ Pokemia Battle') {
    const [t0, t1] = battle.trainers;
    const p0 = t0.pokemon;
    const p1 = t1.pokemon;

    const row0 = `${p0.shiny ? '✨ ' : ''}**${p0.name}** Lv.${p0.level}`;
    const row1 = `${p1.shiny ? '✨ ' : ''}**${p1.name}** Lv.${p1.level}`;

    const ailment = (p) => [...p.ailments].map(a => ({
        burn: '🔥', poison: '☠️', paralysis: '⚡', freeze: '❄️', sleep: '💤',
    }[a] || '')).join('') || '';

    const embed = new EmbedBuilder()
        .setTitle(title)
        .setColor(0xe63946)
        .addFields(
            {
                name: `🔴 <@${t0.userId}>`,
                value: `${row0} ${ailment(p0)}\n${hpBar(p0.hp, p0.maxHp)}`,
                inline: true,
            },
            { name: '\u200b', value: 'VS', inline: true },
            {
                name: `🔵 <@${t1.userId}>`,
                value: `${row1} ${ailment(p1)}\n${hpBar(p1.hp, p1.maxHp)}`,
                inline: true,
            }
        );
    return embed;
}

function moveButtons(pokemon, userId, disabled = false) {
    const buttons = pokemon.moves.map((m, i) => {
        const md = getMove(m.id);
        return new ButtonBuilder()
            .setCustomId(`pkm_move_${i}_${userId}`)
            .setLabel(md ? `${md.name} (${m.pp} PP)` : '???')
            .setStyle(ButtonStyle.Primary)
            .setDisabled(disabled || m.pp <= 0);
    });

    // Pad to at least 1 button if no moves
    if (buttons.length === 0) {
        buttons.push(
            new ButtonBuilder()
                .setCustomId(`pkm_move_0_${userId}`)
                .setLabel('Tackle (struggle)')
                .setStyle(ButtonStyle.Secondary)
                .setDisabled(disabled)
        );
    }

    const rows = [];
    // Up to 4 moves in one row
    rows.push(new ActionRowBuilder().addComponents(...buttons.slice(0, 4)));
    // Flee button
    rows.push(
        new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId(`pkm_flee_${userId}`)
                .setLabel('🏃 Aufgeben')
                .setStyle(ButtonStyle.Danger)
                .setDisabled(disabled)
        )
    );
    return rows;
}

function logToText(log) {
    return log.map(entry => {
        switch (entry.type) {
            case 'move': {
                let line = `**${entry.moveName}**`;
                if (entry.missed) return `${line} — danebengegangen!`;
                if (entry.blocked) {
                    const bl = { freeze: '❄️ eingefroren', sleep: '💤 schläft', paralysis: '⚡ gelähmt' };
                    return `${bl[entry.blocked] || entry.blocked} — kann nicht angreifen!`;
                }
                if (entry.damage) line += ` trifft für **${entry.damage}** Schaden`;
                if (entry.effText) line += ` — ${entry.effText}`;
                if (entry.heal)    line += `, heilt **${entry.heal}** HP`;
                if (entry.recoil)  line += `, **${entry.recoil}** Rückstoß`;
                if (entry.ailment) line += ` — ${entry.ailment}!`;
                if (entry.selfHeal) line += `, heilt sich um **${entry.selfHeal}** HP`;
                if (entry.statChange) {
                    const sc = entry.statChange;
                    const dir = sc.change > 0 ? '↑' : '↓';
                    line += ` — ${sc.stat} ${dir}${Math.abs(sc.change)}`;
                }
                if (entry.messages?.length) line += '\n' + entry.messages.join('\n');
                return line;
            }
            case 'faint':
                return `💀 **${entry.pokemon}** ist besiegt!`;
            case 'flee':
                return `🏃 <@${entry.user}> hat aufgegeben!`;
            case 'ailment_dmg': {
                const icons = { burn: '🔥', poison: '☠️' };
                return `${icons[entry.ailment] || ''} **${entry.pokemon}** erleidet ${entry.damage} ${entry.ailment}-Schaden`;
            }
            default:
                return '';
        }
    }).filter(Boolean).join('\n');
}

// ── Main command ───────────────────────────────────────────────────────────────
module.exports = {
    key: 'pokemia',

    data: new SlashCommandBuilder()
        .setName('pokemia')
        .setDescription('Pokemia — fange, trainiere und kämpfe mit Pokémon!')
        .addSubcommand(sc =>
            sc.setName('start')
                .setDescription('Starte dein Abenteuer und wähle dein Starter-Pokémon.')
        )
        .addSubcommand(sc =>
            sc.setName('catch')
                .setDescription('Fange das wild erschienene Pokémon in diesem Channel.')
        )
        .addSubcommand(sc =>
            sc.setName('list')
                .setDescription('Zeige deine gefangenen Pokémon.')
                .addIntegerOption(o =>
                    o.setName('seite').setDescription('Seite').setRequired(false).setMinValue(1)
                )
        )
        .addSubcommand(sc =>
            sc.setName('info')
                .setDescription('Zeige Details eines Pokémon aus deiner Liste.')
                .addIntegerOption(o =>
                    o.setName('nummer').setDescription('Listenposition').setRequired(false).setMinValue(1)
                )
        )
        .addSubcommand(sc =>
            sc.setName('select')
                .setDescription('Wähle dein aktives Pokémon.')
                .addIntegerOption(o =>
                    o.setName('nummer').setDescription('Listenposition').setRequired(true).setMinValue(1)
                )
        )
        .addSubcommand(sc =>
            sc.setName('train')
                .setDescription('Ein Pokémon trainieren — es ist dabei im Cooldown und kann nicht kämpfen.')
                .addIntegerOption(o =>
                    o.setName('pokemon')
                        .setDescription('Welches Pokémon soll trainieren? (Nummer aus /pokemia list)')
                        .setRequired(true)
                        .setMinValue(1)
                )
                .addIntegerOption(o =>
                    o.setName('dauer')
                        .setDescription('Dauer des Trainings')
                        .setRequired(true)
                        .setMinValue(1)
                )
                .addStringOption(o =>
                    o.setName('einheit')
                        .setDescription('Zeiteinheit')
                        .setRequired(true)
                        .addChoices(
                            { name: 'Sekunden', value: 'sekunden' },
                            { name: 'Minuten',  value: 'minuten'  },
                            { name: 'Stunden',  value: 'stunden'  },
                        )
                )
        )
        .addSubcommandGroup(grp =>
            grp.setName('battle')
                .setDescription('Pokémon-Kampf')
                .addSubcommand(sc =>
                    sc.setName('user')
                        .setDescription('Fordere einen anderen User zum 1v1-Kampf heraus.')
                        .addUserOption(o =>
                            o.setName('ziel').setDescription('Herausgeforderter User').setRequired(true)
                        )
                )
                .addSubcommand(sc =>
                    sc.setName('bot')
                        .setDescription('Kämpfe gegen den Bot.')
                        .addIntegerOption(o =>
                            o.setName('schwierigkeit')
                                .setDescription('Schwierigkeitsgrad (1–10, Standard: 5)')
                                .setRequired(false)
                                .setMinValue(1).setMaxValue(10)
                        )
                )
        ),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const sub   = interaction.options.getSubcommand(false);
        const group = interaction.options.getSubcommandGroup(false);

        if (sub === 'start')              return cmdStart(interaction, botId);
        if (sub === 'catch')              return cmdCatch(interaction, botId);
        if (sub === 'list')               return cmdList(interaction, botId);
        if (sub === 'info')               return cmdInfo(interaction, botId);
        if (sub === 'select')             return cmdSelect(interaction, botId);
        if (sub === 'train')              return cmdTrain(interaction, botId);
        if (group === 'battle' && sub === 'user') return cmdBattleUser(interaction, botId);
        if (group === 'battle' && sub === 'bot')  return cmdBattleBot(interaction, botId);

        return interaction.reply({ content: '❌ Unbekannter Subcommand.', ephemeral: true });
    },
};

// ── /pokemia start ─────────────────────────────────────────────────────────────
async function cmdStart(interaction, botId) {
    const userId = interaction.user.id;
    const user   = await getOrCreatePkmUser(botId, userId);

    if (user.selected_id) {
        return interaction.reply({ content: '⚠️ Du hast dein Abenteuer bereits begonnen!', ephemeral: true });
    }

    const count = await getUserPokemonCount(botId, userId);
    if (count > 0) {
        return interaction.reply({ content: '⚠️ Du hast bereits Pokémon. Nutze `/pokemia select` um dein aktives zu wählen.', ephemeral: true });
    }

    const embed = new EmbedBuilder()
        .setTitle('🌟 Wähle dein Starter-Pokémon!')
        .setDescription('Klicke auf einen der Knöpfe um dein erstes Pokémon zu wählen.')
        .setColor(0xf4d03f)
        .addFields(STARTERS.map(s => ({
            name: `${s.emoji} ${s.name}`,
            value: `Pokémon #${String(s.id).padStart(3, '0')}`,
            inline: true,
        })));

    const row = new ActionRowBuilder().addComponents(
        STARTERS.map(s =>
            new ButtonBuilder()
                .setCustomId(`pkm_starter_${s.id}_${userId}`)
                .setLabel(`${s.emoji} ${s.name}`)
                .setStyle(ButtonStyle.Primary)
        )
    );

    return interaction.reply({ embeds: [embed], components: [row] });
}

// ── /pokemia catch ─────────────────────────────────────────────────────────────
async function cmdCatch(interaction, botId) {
    const userId  = interaction.user.id;
    const guildId = interaction.guildId;

    const spawn = await getActiveSpawn(botId, guildId);
    if (!spawn) {
        return interaction.reply({ content: '🌿 Kein wildes Pokémon ist gerade erschienen. Warte auf das nächste Spawn!', ephemeral: true });
    }

    // Random catch chance (70% base, reduced by spawn level)
    const catchRate = 0.7;
    if (Math.random() > catchRate) {
        return interaction.reply({ content: '🎣 Das wilde Pokémon ist entkommen! Versuch es nochmal.' });
    }

    await markSpawnCaught(spawn.id);
    const pkmLevel = Math.max(2, Math.floor(Math.random() * 10) + 3);
    const caught = await catchPokemon(botId, userId, spawn.species_id, { level: pkmLevel });
    const species = getSpecies(spawn.species_id);

    const shinyText = caught.shiny ? ' ✨ **Shiny!**' : '';
    const embed = new EmbedBuilder()
        .setTitle(`🎉 Pokémon gefangen!${shinyText}`)
        .setDescription(`<@${userId}> hat **${species?.name || '???'}** (Lv. ${pkmLevel}) gefangen!`)
        .setColor(caught.shiny ? 0xf1c40f : 0x2ecc71)
        .setFooter({ text: `Natur: ${caught.nature} | Geschlecht: ${caught.gender === 'female' ? '♀' : '♂'}` });

    return interaction.reply({ embeds: [embed] });
}

// ── /pokemia list ──────────────────────────────────────────────────────────────
async function cmdList(interaction, botId) {
    const userId = interaction.user.id;
    const page   = (interaction.options.getInteger('seite') || 1) - 1;
    const limit  = 10;

    const total  = await getUserPokemonCount(botId, userId);
    if (total === 0) {
        return interaction.reply({ content: '📭 Du hast noch keine Pokémon. Starte mit `/pokemia start`!', ephemeral: true });
    }

    const user    = await getOrCreatePkmUser(botId, userId);
    const pokemon = await getUserPokemon(botId, userId, limit, page * limit);

    const lines = pokemon.map((row, i) => {
        const idx     = page * limit + i + 1;
        const species = getSpecies(row.species_id);
        const active  = row.id === user.selected_id ? ' ◄' : '';
        const shiny   = row.shiny ? '✨ ' : '';
        const gender  = row.gender === 'female' ? '♀' : '♂';
        return `\`${String(idx).padStart(3)}\` ${shiny}**${row.nickname || species?.name || '???'}** Lv.${row.level} ${gender}${active}`;
    });

    const totalPages = Math.ceil(total / limit);
    const embed = new EmbedBuilder()
        .setTitle(`📦 Pokémon von ${interaction.user.displayName}`)
        .setDescription(lines.join('\n'))
        .setColor(0x3498db)
        .setFooter({ text: `Seite ${page + 1}/${totalPages} | ${total} Pokémon gesamt` });

    return interaction.reply({ embeds: [embed], ephemeral: true });
}

// ── /pokemia info ──────────────────────────────────────────────────────────────
async function cmdInfo(interaction, botId) {
    const userId = interaction.user.id;
    const nummer = interaction.options.getInteger('nummer');

    let pkRow;
    if (nummer) {
        pkRow = await getPokemonByIndex(botId, userId, nummer);
    } else {
        pkRow = await getSelectedPokemon(botId, userId);
    }

    if (!pkRow) {
        return interaction.reply({ content: '❌ Pokémon nicht gefunden. Nutze `/pokemia list` um deine Liste zu sehen.', ephemeral: true });
    }

    // Apply finished training if applicable, then re-fetch
    await checkAndFinishTraining(botId, pkRow);
    pkRow = await (nummer ? getPokemonByIndex(botId, userId, nummer) : getSelectedPokemon(botId, userId)) || pkRow;

    const species = getSpecies(pkRow.species_id);
    const moves   = (typeof pkRow.moves_json === 'string' ? JSON.parse(pkRow.moves_json) : pkRow.moves_json) || [];
    const nat     = NATURES[pkRow.nature] || NATURES.Hardy;
    const xpNeed  = xpToNextLevel(pkRow.level);
    const shiny   = pkRow.shiny ? '✨ ' : '';
    const gender  = pkRow.gender === 'female' ? '♀' : '♂';

    const types = [species?.t1, species?.t2].filter(Boolean).join(' / ');
    const evoText = species?.evo ? `→ ${getSpecies(species.evo.into)?.name || '???'} (Lv. ${species.evo.lv})` : 'Keine';

    const moveList = moves.map(id => {
        const md = getMove(id);
        return md ? `**${md.name}** (${md.type}, ${md.cat})` : `???`;
    }).join('\n') || '—';

    // Training status
    const trainingActive = isTraining(pkRow);
    const trainingField  = trainingActive
        ? `🏋️ Trainiert noch **${formatTrainingTime(trainingMsLeft(pkRow))}** (+${pkRow.training_xp} XP)`
        : '—';

    const embed = new EmbedBuilder()
        .setTitle(`${shiny}**${pkRow.nickname || species?.name || '???'}** ${gender} — #${String(pkRow.species_id).padStart(3, '0')}`)
        .setColor(trainingActive ? 0xe67e22 : 0x9b59b6)
        .addFields(
            { name: 'Typ',       value: types || '—',       inline: true },
            { name: 'Level',     value: `${pkRow.level}`,   inline: true },
            { name: 'Natur',     value: pkRow.nature,       inline: true },
            { name: 'XP',        value: `${pkRow.xp} / ${xpNeed}`, inline: true },
            { name: 'Entwicklung', value: evoText,          inline: true },
            { name: 'Training',  value: trainingField,      inline: true },
            { name: 'IVs',
              value: `HP:${pkRow.iv_hp} ATK:${pkRow.iv_atk} DEF:${pkRow.iv_def} SATK:${pkRow.iv_spatk} SDEF:${pkRow.iv_spdef} SPD:${pkRow.iv_spd}`,
              inline: false },
            { name: 'Attacken',  value: moveList,           inline: false },
        )
        .setFooter({ text: `ID: ${pkRow.id}` });

    return interaction.reply({ embeds: [embed], ephemeral: true });
}

// ── /pokemia select ────────────────────────────────────────────────────────────
async function cmdSelect(interaction, botId) {
    const userId = interaction.user.id;
    const nummer = interaction.options.getInteger('nummer');

    const pkRow = await getPokemonByIndex(botId, userId, nummer);
    if (!pkRow) {
        return interaction.reply({ content: `❌ Kein Pokémon an Position ${nummer} gefunden.`, ephemeral: true });
    }

    await setSelectedPokemon(botId, userId, pkRow.id);
    const species = getSpecies(pkRow.species_id);
    return interaction.reply({
        content: `✅ **${pkRow.nickname || species?.name || '???'}** (Lv. ${pkRow.level}) ist jetzt dein aktives Pokémon.`,
        ephemeral: true,
    });
}

// ── /pokemia train ─────────────────────────────────────────────────────────────
async function cmdTrain(interaction, botId) {
    const userId  = interaction.user.id;
    const nummer  = interaction.options.getInteger('pokemon'); // 1-based index
    const dauerRaw = interaction.options.getInteger('dauer');
    const einheit  = interaction.options.getString('einheit'); // sekunden | minuten | stunden

    // Convert to minutes (decimal)
    let durationMinutes;
    if (einheit === 'sekunden')    durationMinutes = dauerRaw / 60;
    else if (einheit === 'stunden') durationMinutes = dauerRaw * 60;
    else                           durationMinutes = dauerRaw; // minuten

    if (durationMinutes < (1 / 60)) {
        return interaction.reply({ content: '❌ Mindestdauer ist 1 Sekunde.', ephemeral: true });
    }

    // Load the target pokemon by list index
    let pkRow = await getPokemonByIndex(botId, userId, nummer);
    if (!pkRow) {
        return interaction.reply({
            content: `❌ Pokémon #${nummer} nicht gefunden. Nutze \`/pokemia list\` um deine Liste zu sehen.`,
            ephemeral: true,
        });
    }

    const species = getSpecies(pkRow.species_id);
    const name    = pkRow.nickname || species?.name || '???';

    // Finish previous training first if it's done
    const finishResult = await checkAndFinishTraining(botId, pkRow);

    // Re-fetch
    pkRow = await getPokemonByIndex(botId, userId, nummer) || pkRow;

    // Already training?
    if (isTraining(pkRow)) {
        const ms     = trainingMsLeft(pkRow);
        const xpGain = Number(pkRow.training_xp || 0);
        return interaction.reply({
            content: [
                `🏋️ **${name}** trainiert bereits!`,
                `⏳ Noch **${formatTrainingTime(ms)}** bis das Training abgeschlossen ist.`,
                `📈 Erwartete XP bei Abschluss: **${xpGain} XP**`,
            ].join('\n'),
            ephemeral: true,
        });
    }

    // Can't train a pokemon that's in a battle (check active pokemon in battles)
    if (getBattleByUserId(userId)) {
        return interaction.reply({ content: '⚠️ Du bist gerade im Kampf — beende zuerst den Kampf.', ephemeral: true });
    }

    const level  = Number(pkRow.level);
    const xpGain = calcTrainingXp(durationMinutes, level);

    await startTraining(botId, pkRow.id, durationMinutes, xpGain);

    // Human-readable duration label
    let dauerLabel;
    if (einheit === 'sekunden')     dauerLabel = `${dauerRaw} Sekunde(n)`;
    else if (einheit === 'stunden') dauerLabel = `${dauerRaw} Stunde(n)`;
    else                            dauerLabel = `${dauerRaw} Minute(n)`;

    const lines = [`🏋️ **${name}** (Lv. ${level}) hat das Training begonnen!`];
    if (finishResult?.messages?.length) {
        lines.unshift('✅ Vorheriges Training abgeschlossen: ' + finishResult.messages.join(', '));
    }
    lines.push(
        `⏱️ Dauer: **${dauerLabel}**`,
        `📈 XP bei Abschluss: **+${xpGain} XP**`,
        `🚫 Das Pokémon kann während des Trainings **nicht kämpfen**.`,
    );

    return interaction.reply({ content: lines.join('\n'), ephemeral: true });
}

// ── Training-Check Hilfsfunktion ───────────────────────────────────────────────
async function replyIfTraining(interaction, botId, pkRow) {
    // First finish any completed training
    await checkAndFinishTraining(botId, pkRow);
    // Re-fetch to get current state
    const fresh = await getSelectedPokemon(botId, pkRow.owner_id || interaction.user.id);
    if (!fresh || !isTraining(fresh)) return false;

    const species = getSpecies(fresh.species_id);
    const name    = fresh.nickname || species?.name || '???';
    const ms      = trainingMsLeft(fresh);

    await interaction.reply({
        content: [
            `🏋️ **${name}** trainiert noch und kann nicht kämpfen!`,
            `⏳ Noch **${formatTrainingTime(ms)}** bis das Training abgeschlossen ist.`,
            `💡 Nutze \`/pokemia train\` um den Status zu prüfen.`,
        ].join('\n'),
        ephemeral: true,
    });
    return true;
}

// ── /pokemia battle bot ────────────────────────────────────────────────────────
async function cmdBattleBot(interaction, botId) {
    const userId     = interaction.user.id;
    const difficulty = interaction.options.getInteger('schwierigkeit') || 5;

    if (getBattleByUserId(userId)) {
        return interaction.reply({ content: '⚠️ Du bist bereits in einem Kampf.', ephemeral: true });
    }

    const pkRow = await getSelectedPokemon(botId, userId);
    if (!pkRow) {
        return interaction.reply({ content: '❌ Du hast kein aktives Pokémon. Starte mit `/pokemia start`!', ephemeral: true });
    }

    // Block if currently training
    if (await replyIfTraining(interaction, botId, pkRow)) return;

    // Build bot's pokemon: random wild species, similar level
    const botLevel   = Math.max(2, pkRow.level + Math.floor(Math.random() * 5) - 2);
    const botSpecies = randomWildSpecies();
    const botNature  = randomNature();
    const botMoves   = (botSpecies.learnset || [])
        .filter(([lv]) => lv <= botLevel)
        .map(([, id]) => id);
    const botUniq = [...new Set(botMoves)].slice(-4);

    const botDbRow = {
        id: 0, species_id: botSpecies.id, level: botLevel, nature: botNature,
        shiny: 0, gender: 'male', nickname: null,
        moves_json: JSON.stringify(botUniq),
        iv_hp: 15, iv_atk: 15, iv_def: 15, iv_spatk: 15, iv_spdef: 15, iv_spd: 15,
    };

    const BOT_ID  = `bot_${interaction.guild.members.me?.id || '0'}`;
    const userPkm = buildBattlePokemon(pkRow);
    const botPkm  = buildBattlePokemon(botDbRow);

    const battleKey = `bot_${botId}_${userId}_${Date.now()}`;
    const battle = createBattle(battleKey, 'pve', [
        { userId, pokemon: userPkm },
        { userId: BOT_ID, pokemon: botPkm },
    ], difficulty);
    battle.started   = true;
    battle.isBot     = true;
    battle.botId     = botId;
    battle.playerRow = pkRow;

    const embed   = battleEmbed(battle, `⚔️ Kampf gegen Bot (Stufe ${difficulty})`);
    const buttons = moveButtons(userPkm, userId);

    await interaction.reply({ embeds: [embed], components: buttons });
}

// ── /pokemia battle user ───────────────────────────────────────────────────────
async function cmdBattleUser(interaction, botId) {
    const userId   = interaction.user.id;
    const target   = interaction.options.getUser('ziel');

    if (target.id === userId) {
        return interaction.reply({ content: '❌ Du kannst nicht gegen dich selbst kämpfen.', ephemeral: true });
    }
    if (target.bot) {
        return interaction.reply({ content: '❌ Du kannst nicht gegen Bots kämpfen. Nutze `/pokemia battle bot`.', ephemeral: true });
    }
    if (getBattleByUserId(userId) || getBattleByUserId(target.id)) {
        return interaction.reply({ content: '⚠️ Du oder dein Gegner bist bereits in einem Kampf.', ephemeral: true });
    }

    const myRow = await getSelectedPokemon(botId, userId);
    if (!myRow) {
        return interaction.reply({ content: '❌ Du hast kein aktives Pokémon!', ephemeral: true });
    }

    // Block if currently training
    if (await replyIfTraining(interaction, botId, myRow)) return;

    const challengeKey = `pkm_chal_${botId}_${userId}_${target.id}`;
    createChallenge(challengeKey, {
        challengerId: userId,
        targetId:     target.id,
        botId,
    });

    const mySpecies = getSpecies(myRow.species_id);
    const embed = new EmbedBuilder()
        .setTitle('⚔️ Pokemia Herausforderung!')
        .setDescription(
            `<@${userId}> fordert <@${target.id}> zum Pokémon-Kampf heraus!\n\n` +
            `**${interaction.user.displayName}** kämpft mit **${myRow.nickname || mySpecies?.name || '???'}** (Lv. ${myRow.level})`
        )
        .setColor(0xe74c3c)
        .setFooter({ text: 'Die Herausforderung läuft 5 Minuten ab.' });

    const row = new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId(`pkm_accept_${challengeKey}_${target.id}`)
            .setLabel('✅ Annehmen')
            .setStyle(ButtonStyle.Success),
        new ButtonBuilder()
            .setCustomId(`pkm_decline_${challengeKey}_${target.id}`)
            .setLabel('❌ Ablehnen')
            .setStyle(ButtonStyle.Danger)
    );

    return interaction.reply({ embeds: [embed], components: [row] });
}
