// PFAD: /core/installer/src/commands/leveling/rank.js
const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    getOrCreateSettings, getOrCreateUser, getUserRank,
    xpToNextLevel, makeXpBar, isCommandEnabled,
} = require('../../services/leveling-service');

const data = new SlashCommandBuilder()
    .setName('rank')
    .setDescription('Get your or another user\'s experience progress')
    .addUserOption(opt =>
        opt.setName('user').setDescription('Target user').setRequired(false)
    );

async function execute(interaction, botId) {
    if (!(await isCommandEnabled(botId, 'rank'))) {
        return interaction.reply({ content: '❌ Dieser Command ist deaktiviert.', ephemeral: true });
    }

    if (!interaction.guildId) {
        return interaction.reply({ content: 'Nur in Servern nutzbar.', ephemeral: true });
    }

    const target   = interaction.options.getUser('user') || interaction.user;
    const guildId  = interaction.guildId;
    const settings = await getOrCreateSettings(botId);
    const user     = await getOrCreateUser(botId, guildId, target.id);
    const rank     = await getUserRank(botId, guildId, target.id);

    const level    = Number(user.level);
    const xp       = Number(user.xp);
    const totalXp  = Number(user.total_xp);
    const xpNeeded = xpToNextLevel(level, Number(settings?.xp_per_level) || 50);
    const color    = parseInt((settings?.embed_color || 'f45142').replace('#', ''), 16);

    const embed = new EmbedBuilder()
        .setColor(color)
        .setAuthor({ name: target.username, iconURL: target.displayAvatarURL() })
        .setTitle(`Rank #${rank}`)
        .addFields(
            { name: 'Level',    value: `**${level}**`,               inline: true },
            { name: 'XP',       value: `${xp} / ${xpNeeded}`,        inline: true },
            { name: 'Total XP', value: String(totalXp),               inline: true },
            { name: 'Progress', value: `\`${makeXpBar(xp, xpNeeded)}\`` },
        );

    await interaction.reply({ embeds: [embed] });
}

module.exports = { key: 'rank', data, execute };
