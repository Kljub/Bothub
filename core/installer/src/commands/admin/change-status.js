// PFAD: /core/installer/src/commands/change-status.js

const { SlashCommandBuilder } = require('discord.js');
const { loadStatusSettings, applyStatus } = require('../../services/status-service');

module.exports = {
    key: 'change-status',

    data: new SlashCommandBuilder()
        .setName('change-status')
        .setDescription('Ändert den Status des Bots (nur im Command-Modus verfügbar).')
        .addStringOption((o) =>
            o.setName('type')
                .setDescription('Status-Typ')
                .setRequired(true)
                .addChoices(
                    { name: 'Playing',   value: 'playing'   },
                    { name: 'Watching',  value: 'watching'  },
                    { name: 'Listening', value: 'listening' }
                )
        )
        .addStringOption((o) =>
            o.setName('text')
                .setDescription('Status-Text (max. 128 Zeichen)')
                .setRequired(true)
                .setMaxLength(128)
        ),

    async execute(interaction, botId, botManager) {
        const settings = await loadStatusSettings(botId);

        if (!settings || settings.mode !== 'command' || Number(settings.cmd_change_status) !== 1) {
            await interaction.reply({
                content: '❌ Der `/change-status` Command ist für diesen Bot nicht aktiviert.',
                flags: 64,
            });
            return;
        }

        const type = interaction.options.getString('type', true);
        const text = interaction.options.getString('text', true).trim();

        const client = botManager.getClient(botId);
        applyStatus(client, type, text);

        await interaction.reply({
            content: `✅ Status gesetzt: **${type}** – ${text}`,
            flags: 64,
        });
    },
};
