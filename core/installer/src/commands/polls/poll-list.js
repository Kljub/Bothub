// PFAD: /core/installer/src/commands/polls/poll-list.js
const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../../db');

module.exports = {
    key: 'poll-list',

    data: new SlashCommandBuilder()
        .setName('poll-list')
        .setDescription('Show all active polls in this server.'),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Only usable in a server.', ephemeral: true });
        }

        const enabled = await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), 'poll-list']
        ).then(r => (!Array.isArray(r) || r.length === 0) ? true : Number(r[0].is_enabled) === 1).catch(() => true);

        if (!enabled) {
            return interaction.reply({ content: '❌ Der `/poll-list` Befehl ist deaktiviert.', ephemeral: true });
        }

        const polls = await dbQuery(
            'SELECT id, question, channel_id, creator_username, created_at FROM bot_polls WHERE bot_id = ? AND guild_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 25',
            [Number(botId), interaction.guildId]
        );

        if (!Array.isArray(polls) || polls.length === 0) {
            return interaction.reply({ content: '📭 Es gibt keine aktiven Polls in diesem Server.', ephemeral: true });
        }

        const lines = polls.map(p => {
            const ts = Math.floor(new Date(p.created_at).getTime() / 1000);
            return `**#${p.id}** — ${p.question}\n> <#${p.channel_id}> · erstellt von **${p.creator_username}** · <t:${ts}:R>`;
        });

        const embed = new EmbedBuilder()
            .setColor('#6366f1')
            .setTitle(`🗳️ Aktive Polls (${polls.length})`)
            .setDescription(lines.join('\n\n'))
            .setTimestamp();

        return interaction.reply({ embeds: [embed] });
    },
};
