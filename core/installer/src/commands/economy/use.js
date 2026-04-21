// PFAD: /core/installer/src/commands/use.js
const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');
const { dbQuery }          = require('../../db');

module.exports = {
    key: 'use',

    data: new SlashCommandBuilder()
        .setName('use')
        .setDescription('Benutze ein Item aus deinem Inventar.')
        .addIntegerOption(o =>
            o.setName('item_id').setDescription('ID des Items (aus /inventory)').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'use')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId  = interaction.user.id;
        const guildId = interaction.guildId;
        const itemId  = interaction.options.getInteger('item_id');

        const rows = await dbQuery(
            `SELECT i.quantity, s.name, s.emoji
             FROM eco_inventory i
             JOIN eco_shop_items s ON s.id = i.item_id
             WHERE i.bot_id = ? AND i.guild_id = ? AND i.user_id = ? AND i.item_id = ?
             LIMIT 1`,
            [botId, guildId, userId, itemId]
        );

        if (!Array.isArray(rows) || rows.length === 0) {
            return interaction.reply({
                content: '❌ Dieses Item befindet sich nicht in deinem Inventar.',
                ephemeral: true,
            });
        }

        const item = rows[0];
        if (Number(item.quantity) <= 1) {
            await dbQuery(
                'DELETE FROM eco_inventory WHERE bot_id = ? AND guild_id = ? AND user_id = ? AND item_id = ?',
                [botId, guildId, userId, itemId]
            );
        } else {
            await dbQuery(
                'UPDATE eco_inventory SET quantity = quantity - 1 WHERE bot_id = ? AND guild_id = ? AND user_id = ? AND item_id = ?',
                [botId, guildId, userId, itemId]
            );
        }

        await interaction.reply({
            content: `✨ Du hast **${item.emoji} ${item.name}** benutzt!`,
            ephemeral: true,
        });
    },
};
