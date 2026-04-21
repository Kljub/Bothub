// PFAD: /core/installer/src/commands/inventory.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getInventory,
} = require('../../services/economy-service');

module.exports = {
    key: 'inventory',

    data: new SlashCommandBuilder()
        .setName('inventory')
        .setDescription('Zeigt dein Inventar an.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'inventory')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId = interaction.user.id;
        const guildId = interaction.guildId;
        const items   = await getInventory(botId, guildId, userId);

        if (items.length === 0) {
            return interaction.reply({
                content: '🎒 Dein Inventar ist leer. Kaufe Items mit `/buy`.',
                ephemeral: true,
            });
        }

        const lines = ['**🎒 Inventar**', ''];
        for (const item of items) {
            lines.push(`${item.emoji} **${item.name}** × ${item.quantity} *(ID: ${item.item_id})*`);
            if (item.description) lines.push(`   *${item.description}*`);
        }
        lines.push('', 'Benutze ein Item mit `/use item_id:<ID>`');

        await interaction.reply({ content: lines.join('\n'), ephemeral: true });
    },
};
