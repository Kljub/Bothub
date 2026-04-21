// PFAD: /core/installer/src/commands/general/ai.js
const { SlashCommandBuilder } = require('discord.js');
const { dbQuery } = require('../../db');
const { askAI, clearSession } = require('../../services/ai-service');

module.exports = {
    key: 'ai',

    data: new SlashCommandBuilder()
        .setName('ask')
        .setDescription('Stelle der KI eine Frage.')
        .addSubcommand(sub =>
            sub.setName('frage')
                .setDescription('Stelle der KI eine Frage.')
                .addStringOption(o =>
                    o.setName('text')
                        .setDescription('Deine Frage an die KI')
                        .setRequired(true)
                        .setMaxLength(1000)
                )
                .addBooleanOption(o =>
                    o.setName('web')
                        .setDescription('Aktuelle Websuche in die Antwort einbeziehen')
                        .setRequired(false)
                )
        )
        .addSubcommand(sub =>
            sub.setName('reset')
                .setDescription('Setzt deinen Gesprächsverlauf mit der KI zurück.')
        ),

    async execute(interaction, botId) {
        const cmdRow = (await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [botId, 'ai']
        ))[0];
        if (cmdRow && !cmdRow.is_enabled) {
            return interaction.reply({ content: '❌ Dieses Modul ist deaktiviert.', ephemeral: true });
        }

        const sub    = interaction.options.getSubcommand();
        const userId = interaction.user.id;

        // ── /ask reset ──────────────────────────────────────────────────────────
        if (sub === 'reset') {
            clearSession(botId, userId);
            return interaction.reply({ content: '🔄 Dein Gesprächsverlauf wurde zurückgesetzt.', ephemeral: true });
        }

        // ── /ask frage ──────────────────────────────────────────────────────────
        const question = interaction.options.getString('text', true);
        const useWeb   = interaction.options.getBoolean('web') ?? false;

        await interaction.deferReply();

        try {
            const answer = await askAI(botId, userId, question, { useWeb });

            const webTag = useWeb ? ' 🌐' : '';
            const text   = answer.length > 1850 ? answer.slice(0, 1850) + '…' : answer;
            await interaction.editReply(`💬 **${interaction.user.username}:**${webTag} ${question}\n\n🤖 ${text}`);
        } catch (err) {
            console.error(`[AI] Bot ${botId}: askAI error:`, err.message);
            await interaction.editReply(`❌ Fehler beim Abrufen der KI-Antwort: ${err.message}`);
        }
    },
};
