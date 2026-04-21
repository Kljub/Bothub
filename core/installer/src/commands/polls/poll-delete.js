// PFAD: /core/installer/src/commands/polls/poll-delete.js
const { SlashCommandBuilder, EmbedBuilder, PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../../db');

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return Array.isArray(arr) ? arr : [];
    } catch (_) { return []; }
}

module.exports = {
    key: 'poll-delete',

    data: new SlashCommandBuilder()
        .setName('poll-delete')
        .setDescription('Delete a poll and remove its embed message.')
        .addIntegerOption(o =>
            o.setName('poll_id').setDescription('The ID of the poll to delete').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Only usable in a server.', ephemeral: true });
        }

        const cmdEnabled = await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), 'poll-delete']
        ).then(r => (!Array.isArray(r) || r.length === 0) ? true : Number(r[0].is_enabled) === 1).catch(() => true);

        if (!cmdEnabled) {
            return interaction.reply({ content: '❌ Der `/poll-delete` Befehl ist deaktiviert.', ephemeral: true });
        }

        // Permission check: ManageGuild or manager role
        const pollSettings = await dbQuery(
            'SELECT manager_roles FROM bot_polls_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        ).then(r => (Array.isArray(r) && r[0]) ? r[0] : null).catch(() => null);
        const managerRoles = parseJsonArray(pollSettings?.manager_roles);
        const member = interaction.member;
        const isAdmin = member?.permissions?.has(PermissionFlagsBits.ManageGuild) ?? false;
        const memberRoles = member?.roles?.cache ? [...member.roles.cache.keys()] : [];
        const hasRole = managerRoles.length > 0 && managerRoles.some(r => memberRoles.includes(r));

        if (!isAdmin && !hasRole) {
            return interaction.reply({ content: '❌ Du hast keine Berechtigung Polls zu löschen.', ephemeral: true });
        }

        const pollId = interaction.options.getInteger('poll_id', true);

        const rows = await dbQuery(
            'SELECT * FROM bot_polls WHERE id = ? AND bot_id = ? AND guild_id = ? LIMIT 1',
            [pollId, Number(botId), interaction.guildId]
        );

        if (!Array.isArray(rows) || rows.length === 0) {
            return interaction.reply({ content: `❌ Poll #${pollId} wurde nicht gefunden.`, ephemeral: true });
        }

        const poll = rows[0];

        // Try to delete the embed message
        if (poll.channel_id && poll.message_id) {
            try {
                const channel = await interaction.guild.channels.fetch(poll.channel_id).catch(() => null);
                if (channel && channel.isTextBased()) {
                    const msg = await channel.messages.fetch(poll.message_id).catch(() => null);
                    if (msg) await msg.delete().catch(() => null);
                }
            } catch (_) { /* ignore */ }
        }

        // Delete votes and poll row
        await dbQuery('DELETE FROM bot_poll_votes WHERE poll_id = ?', [pollId]).catch(() => null);
        await dbQuery('DELETE FROM bot_polls WHERE id = ?', [pollId]).catch(() => null);

        const embed = new EmbedBuilder()
            .setColor('#ef4444')
            .setTitle('🗑️ Poll gelöscht')
            .setDescription(`**Poll #${pollId}** wurde erfolgreich gelöscht.\n> ${poll.question}`)
            .setTimestamp();

        return interaction.reply({ embeds: [embed], ephemeral: true });
    },
};
