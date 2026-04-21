// PFAD: /core/installer/src/commands/poll-find.js
const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../../db');

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return Array.isArray(arr) ? arr : [];
    } catch (_) { return []; }
}

async function isPollCommandEnabled(botId, commandKey) {
    try {
        const rows = await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), commandKey]
        );
        if (!Array.isArray(rows) || rows.length === 0) return true;
        return Number(rows[0].is_enabled) === 1;
    } catch (_) {
        return true;
    }
}

module.exports = {
    key: 'poll-find',

    data: new SlashCommandBuilder()
        .setName('poll-find')
        .setDescription('Show results of a poll.')
        .addIntegerOption(o =>
            o.setName('poll_id').setDescription('The poll ID to look up').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Only usable in a server.', ephemeral: true });
        }

        if (!await isPollCommandEnabled(botId, 'poll-find')) {
            return interaction.reply({ content: '❌ Der `/poll-find` Befehl ist deaktiviert.', ephemeral: true });
        }

        const pollId = interaction.options.getInteger('poll_id', true);

        const polls = await dbQuery(
            'SELECT * FROM bot_polls WHERE id = ? AND bot_id = ? LIMIT 1',
            [pollId, Number(botId)]
        );

        if (!Array.isArray(polls) || polls.length === 0) {
            return interaction.reply({ content: `❌ Poll #${pollId} not found.`, ephemeral: true });
        }

        const poll = polls[0];
        const choices = parseJsonArray(poll.choices);

        // Count votes per emoji
        const voteCounts = await dbQuery(
            'SELECT emoji, COUNT(*) AS cnt FROM bot_poll_votes WHERE poll_id = ? GROUP BY emoji',
            [pollId]
        );

        const countMap = {};
        if (Array.isArray(voteCounts)) {
            for (const row of voteCounts) {
                countMap[String(row.emoji)] = Number(row.cnt || 0);
            }
        }

        const totalVotes = Object.values(countMap).reduce((a, b) => a + b, 0);

        const lines = choices.map((c) => {
            const emoji = typeof c === 'object' ? String(c.emoji || '') : '';
            const text  = typeof c === 'object' ? String(c.text  || c) : String(c);
            const cnt   = countMap[emoji] || 0;
            const pct   = totalVotes > 0 ? Math.round((cnt / totalVotes) * 100) : 0;
            const bar   = '█'.repeat(Math.round(pct / 10)) + '░'.repeat(10 - Math.round(pct / 10));
            return `${emoji} **${text}**\n${bar} ${pct}% (${cnt} votes)`;
        });

        const status = Number(poll.is_active) === 1 ? '🟢 Active' : '🔴 Closed';

        const embed = new EmbedBuilder()
            .setColor('#6366f1')
            .setTitle(`📊 Poll #${pollId} Results`)
            .setDescription(`**${poll.question}**\n\n${lines.join('\n\n')}`)
            .addFields(
                { name: 'Total Votes', value: String(totalVotes), inline: true },
                { name: 'Status',      value: status,             inline: true },
                { name: 'Created',     value: `<t:${Math.floor(new Date(poll.created_at).getTime() / 1000)}:R>`, inline: true },
            )
            .setFooter({ text: `Poll by ${poll.creator_username}` })
            .setTimestamp();

        return interaction.reply({ embeds: [embed] });
    },
};
