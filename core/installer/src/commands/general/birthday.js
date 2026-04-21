// PFAD: /core/installer/src/commands/general/birthday.js

const { SlashCommandBuilder } = require('discord.js');
const { dbQuery } = require('../../db');

const MONTH_NAMES = [
    'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
];

module.exports = {
    key: 'birthday',

    data: new SlashCommandBuilder()
        .setName('birthday')
        .setDescription('Verwalte deinen Geburtstag auf diesem Server.')

        // ── /birthday add ────────────────────────────────────────────────
        .addSubcommand((sub) =>
            sub
                .setName('add')
                .setDescription('Trage deinen Geburtstag ein oder aktualisiere ihn.')
                .addIntegerOption((opt) =>
                    opt
                        .setName('monat')
                        .setDescription('Geburtsmonat (1 = Januar … 12 = Dezember)')
                        .setMinValue(1)
                        .setMaxValue(12)
                        .setRequired(true)
                )
                .addIntegerOption((opt) =>
                    opt
                        .setName('tag')
                        .setDescription('Geburtstag (1 – 31)')
                        .setMinValue(1)
                        .setMaxValue(31)
                        .setRequired(true)
                )
        )

        // ── /birthday delete ─────────────────────────────────────────────
        .addSubcommand((sub) =>
            sub
                .setName('delete')
                .setDescription('Entferne deinen eingetragenen Geburtstag von diesem Server.')
        ),

    async execute(interaction, botId) {
        const sub     = interaction.options.getSubcommand();
        const guildId = interaction.guildId ? String(interaction.guildId) : '';
        const userId  = interaction.user    ? String(interaction.user.id) : '';

        if (guildId === '' || userId === '') {
            await interaction.reply({
                content: 'Dieser Befehl kann nur auf einem Server verwendet werden.',
                ephemeral: true,
            });
            return;
        }

        // ── add ──────────────────────────────────────────────────────────
        if (sub === 'add') {
            const month    = interaction.options.getInteger('monat');
            const day      = interaction.options.getInteger('tag');
            const username = interaction.user.username || '';

            // Basic day-in-month validation (catches obvious impossible dates)
            const maxDays = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            if (day > maxDays[month - 1]) {
                await interaction.reply({
                    content: `❌ Der ${day}. ${MONTH_NAMES[month - 1]} existiert nicht.`,
                    ephemeral: true,
                });
                return;
            }

            await dbQuery(
                `INSERT INTO bot_birthdays (bot_id, guild_id, user_id, username, birth_day, birth_month)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   username    = VALUES(username),
                   birth_day   = VALUES(birth_day),
                   birth_month = VALUES(birth_month),
                   updated_at  = NOW()`,
                [Number(botId), guildId, userId, username, day, month]
            );

            await interaction.reply({
                content: `🎂 Geburtstag gespeichert: **${day}. ${MONTH_NAMES[month - 1]}**`,
                ephemeral: true,
            });
            return;
        }

        // ── delete ───────────────────────────────────────────────────────
        if (sub === 'delete') {
            const result = await dbQuery(
                `DELETE FROM bot_birthdays WHERE bot_id = ? AND guild_id = ? AND user_id = ?`,
                [Number(botId), guildId, userId]
            );

            const deleted = result && typeof result.affectedRows === 'number'
                ? result.affectedRows
                : 0;

            await interaction.reply({
                content: deleted > 0
                    ? '🗑️ Dein Geburtstag wurde von diesem Server entfernt.'
                    : 'ℹ️ Kein Geburtstag von dir gefunden.',
                ephemeral: true,
            });
        }
    },
};
