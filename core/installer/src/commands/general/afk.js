// PFAD: /core/installer/src/commands/afk.js

const { SlashCommandBuilder } = require('discord.js');
const { dbQuery } = require('../../db');

module.exports = {
    key: 'afk',

    data: new SlashCommandBuilder()
        .setName('afk')
        .setDescription('Setzt deinen AFK-Status.')
        .addStringOption((option) =>
            option
                .setName('grund')
                .setDescription('Optionaler AFK-Grund')
                .setRequired(false)
        ),

    async execute(interaction, botId) {
        const guildId = interaction.guildId ? String(interaction.guildId) : '';
        const userId = interaction.user ? String(interaction.user.id) : '';
        const reasonRaw = interaction.options.getString('grund');
        const reason = typeof reasonRaw === 'string' && reasonRaw.trim() !== ''
            ? reasonRaw.trim()
            : 'AFK';

        if (guildId === '' || userId === '') {
            await interaction.reply({
                content: 'AFK kann nur innerhalb eines Servers genutzt werden.',
                ephemeral: true
            });
            return;
        }

        await dbQuery(
            `
            INSERT INTO bot_afk_users
            (
                bot_id,
                guild_id,
                user_id,
                reason,
                created_at,
                updated_at
            )
            VALUES
            (
                ?, ?, ?, ?, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                updated_at = NOW()
            `,
            [
                Number(botId),
                guildId,
                userId,
                reason
            ]
        );

        await interaction.reply({
            content: `💤 Du bist jetzt AFK${reason !== 'AFK' ? `: **${reason}**` : '.'}`,
            ephemeral: true
        });
    }
};