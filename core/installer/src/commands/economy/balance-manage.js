// PFAD: /core/installer/src/commands/balance-manage.js
const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet, formatMoney
} = require('../../services/economy-service');

module.exports = {
    key: 'balance-manage',

    data: new SlashCommandBuilder()
        .setName('balance-manage')
        .setDescription('Guthaben eines Users verwalten. (Admin)')
        .setDefaultMemberPermissions(PermissionFlagsBits.ManageGuild)
        .addSubcommand(sub =>
            sub.setName('add')
                .setDescription('Guthaben zu einem User hinzufügen.')
                .addUserOption(o =>
                    o.setName('user').setDescription('Ziel-User').setRequired(true)
                )
                .addIntegerOption(o =>
                    o.setName('amount').setDescription('Betrag').setRequired(true).setMinValue(1)
                )
        )
        .addSubcommand(sub =>
            sub.setName('remove')
                .setDescription('Guthaben von einem User abziehen.')
                .addUserOption(o =>
                    o.setName('user').setDescription('Ziel-User').setRequired(true)
                )
                .addIntegerOption(o =>
                    o.setName('amount').setDescription('Betrag').setRequired(true).setMinValue(1)
                )
        ),

    async execute(interaction, botId) {
        const sub = interaction.options.getSubcommand();
        if (!await isCommandEnabled(botId, `balance-manage-${sub}`)) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }
        if (!interaction.memberPermissions?.has(PermissionFlagsBits.ManageGuild)) {
            return interaction.reply({ content: '❌ Du benötigst die Berechtigung **Server verwalten**.', ephemeral: true });
        }

        const target   = interaction.options.getUser('user');
        const amount   = interaction.options.getInteger('amount');
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);

        if (target.bot) {
            return interaction.reply({ content: '❌ Bots können kein Guthaben besitzen.', ephemeral: true });
        }

        await getOrCreateWallet(botId, guildId, target.id);

        if (sub === 'remove') {
            const wallet = await getOrCreateWallet(botId, guildId, target.id);
            if (wallet.wallet < amount) {
                return interaction.reply({
                    content: `❌ ${target.username} hat nur **${formatMoney(wallet.wallet, settings)}** in der Wallet.`,
                    ephemeral: true,
                });
            }
        }

        await adjustWallet(botId, guildId, target.id, sub === 'add' ? amount : -amount);

        const verb   = sub === 'add' ? 'hinzugefügt' : 'abgezogen';
        const symbol = sub === 'add' ? '➕' : '➖';

        return interaction.reply({
            content: `${symbol} **${formatMoney(amount, settings)}** wurden ${target.username} ${verb}.`,
            ephemeral: true,
        });
    },
};
