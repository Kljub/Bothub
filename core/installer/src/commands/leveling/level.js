// PFAD: /core/installer/src/commands/leveling/level.js
const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    getOrCreateSettings, getOrCreateUser, getUserRank,
    getLeaderboard, editXp, resetLeaderboard,
    xpToNextLevel, makeXpBar, isCommandEnabled,
} = require('../../services/leveling-service');

const data = new SlashCommandBuilder()
    .setName('level')
    .setDescription('Leveling commands')
    .addSubcommand(sub => sub
        .setName('leaderboard')
        .setDescription('Check the leveling leaderboard of this server'))
    .addSubcommand(sub => sub
        .setName('edit-xp')
        .setDescription('Edit someone\'s experience or level')
        .addUserOption(opt =>
            opt.setName('user').setDescription('Target user').setRequired(true))
        .addIntegerOption(opt =>
            opt.setName('amount').setDescription('XP amount').setRequired(true))
        .addStringOption(opt =>
            opt.setName('action')
                .setDescription('Action')
                .setRequired(true)
                .addChoices(
                    { name: 'Add', value: 'add' },
                    { name: 'Remove', value: 'remove' },
                    { name: 'Set', value: 'set' },
                )))
    .addSubcommand(sub => sub
        .setName('reset-leaderboard')
        .setDescription('Reset this server\'s leveling leaderboard'));

async function cmdLeaderboard(interaction, botId) {
    if (!(await isCommandEnabled(botId, 'level_leaderboard'))) {
        return interaction.reply({ content: '❌ Dieser Command ist deaktiviert.', ephemeral: true });
    }

    const guildId  = interaction.guildId;
    const settings = await getOrCreateSettings(botId);
    const rows     = await getLeaderboard(botId, guildId, 10);
    const color    = parseInt((settings?.embed_color || 'f45142').replace('#', ''), 16);

    if (!rows || rows.length === 0) {
        return interaction.reply({ content: 'Noch keine Leveling-Daten. Fang an zu schreiben!', ephemeral: true });
    }

    const lines = rows.map((row, i) =>
        `**#${i + 1}** <@${row.user_id}> — Level **${row.level}** (${row.total_xp} XP)`
    );

    const embed = new EmbedBuilder()
        .setColor(color)
        .setTitle(`🏆 Leaderboard — ${interaction.guild?.name || 'Server'}`)
        .setDescription(lines.join('\n'));

    await interaction.reply({ embeds: [embed] });
}

async function cmdEditXp(interaction, botId) {
    if (!(await isCommandEnabled(botId, 'level_editxp'))) {
        return interaction.reply({ content: '❌ Dieser Command ist deaktiviert.', ephemeral: true });
    }

    if (!interaction.member?.permissions?.has('ManageGuild')) {
        return interaction.reply({ content: '❌ Du benötigst die Berechtigung "Server verwalten".', ephemeral: true });
    }

    const target = interaction.options.getUser('user');
    const amount = interaction.options.getInteger('amount');
    const action = interaction.options.getString('action');
    const guildId = interaction.guildId;

    const result = await editXp(botId, guildId, target.id, amount, action);
    const xpNeeded = xpToNextLevel(result.level, (await getOrCreateSettings(botId))?.xp_per_level || 50);

    await interaction.reply({
        content: `✅ XP von <@${target.id}> aktualisiert — Level **${result.level}**, ${result.xp}/${xpNeeded} XP (Gesamt: ${result.total_xp})`,
        ephemeral: true,
    });
}

async function cmdResetLeaderboard(interaction, botId) {
    if (!(await isCommandEnabled(botId, 'level_reset'))) {
        return interaction.reply({ content: '❌ Dieser Command ist deaktiviert.', ephemeral: true });
    }

    if (!interaction.member?.permissions?.has('ManageGuild')) {
        return interaction.reply({ content: '❌ Du benötigst die Berechtigung "Server verwalten".', ephemeral: true });
    }

    await resetLeaderboard(botId, interaction.guildId);
    await interaction.reply({ content: '✅ Leaderboard wurde zurückgesetzt.', ephemeral: true });
}

async function execute(interaction, botId) {
    if (!interaction.guildId) {
        return interaction.reply({ content: 'Nur in Servern nutzbar.', ephemeral: true });
    }

    const sub = interaction.options.getSubcommand(false);
    if (sub === 'leaderboard')     return cmdLeaderboard(interaction, botId);
    if (sub === 'edit-xp')         return cmdEditXp(interaction, botId);
    if (sub === 'reset-leaderboard') return cmdResetLeaderboard(interaction, botId);
}

module.exports = { key: 'level', data, execute };
