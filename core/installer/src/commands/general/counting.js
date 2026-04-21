// /core/installer/src/commands/general/counting.js
const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../../db');
const { invalidateSettingsCache } = require('../../services/counting-service');

module.exports = {
    key: 'counting',

    data: new SlashCommandBuilder()
        .setName('counting-set')
        .setDescription('Counting Management')
        .setDefaultMemberPermissions(PermissionFlagsBits.ManageGuild)
        .addSubcommand(sub =>
            sub.setName('number')
                .setDescription('Setzt den aktuellen Count auf eine beliebige Zahl.')
                .addIntegerOption(o =>
                    o.setName('number')
                        .setDescription('Der neue Count (mind. 0)')
                        .setRequired(true)
                        .setMinValue(0)
                )
        ),

    async execute(interaction, botId) {
        const sub = interaction.options.getSubcommand();

        // Runtime check
        const cmdRow = (await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ?',
            [botId, 'counting']
        ))[0];
        if (cmdRow && !cmdRow.is_enabled) {
            return interaction.reply({ content: '❌ Dieses Modul ist deaktiviert.', ephemeral: true });
        }

        if (sub === 'number') {
            const number  = interaction.options.getInteger('number');
            const guildId = interaction.guildId;

            if (!guildId) {
                return interaction.reply({ content: '❌ Dieser Befehl kann nur auf einem Server verwendet werden.', ephemeral: true });
            }

            try {
                await dbQuery(
                    `INSERT INTO bot_counting_state (bot_id, guild_id, current_count, last_user_id, last_message_id, last_count_at)
                     VALUES (?, ?, ?, NULL, NULL, NULL)
                     ON DUPLICATE KEY UPDATE current_count = VALUES(current_count), last_user_id = NULL, last_message_id = NULL, last_count_at = NULL`,
                    [Number(botId), guildId, number]
                );

                invalidateSettingsCache(Number(botId), guildId);

                return interaction.reply({
                    content: `✅ Der Count wurde auf **${number}** gesetzt. Die nächste erwartete Zahl ist **${number + 1}**.`,
                    ephemeral: true,
                });
            } catch (err) {
                console.error(`[Counting] counting-set error:`, err.message);
                return interaction.reply({ content: '❌ Fehler beim Setzen des Counts.', ephemeral: true });
            }
        }
    },
};
