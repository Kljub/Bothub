const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { createGiveaway } = require('../../services/giveaway-service');
const { dbQuery } = require('../../db');

module.exports = {
    key: 'giveaway',
    data: new SlashCommandBuilder()
        .setName('giveaway')
        .setDescription('Giveaway Management System')
        .setDefaultMemberPermissions(PermissionFlagsBits.ManageMessages)
        .addSubcommand(sub => 
            sub.setName('create')
                .setDescription('Startet ein neues Giveaway.')
                .addStringOption(o => o.setName('preis').setDescription('Was gibt es zu gewinnen?').setRequired(true))
                .addIntegerOption(o => o.setName('dauer').setDescription('Dauer in Minuten').setRequired(true).setMinValue(1))
                .addIntegerOption(o => o.setName('gewinner').setDescription('Anzahl der Gewinner').setRequired(false).setMinValue(1))
        )
        .addSubcommand(sub =>
            sub.setName('end')
                .setDescription('Beendet ein aktives Giveaway vorzeitig.')
                .addStringOption(o => o.setName('id').setDescription('Die ID des Giveaways (aus dem Dashboard)').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('list')
                .setDescription('Zeigt alle aktiven Giveaways auf diesem Server.')
        ),

    async execute(interaction, botId) {
        const sub = interaction.options.getSubcommand();
        
        // Runtime check if the specific subcommand is enabled in dashboard
        const cmdKey = `giveaway-${sub}`;
        const cmdRow = (await dbQuery('SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ?', [botId, cmdKey]))[0];
        if (cmdRow && !cmdRow.is_enabled) {
            return interaction.reply({ content: '❌ Dieses Modul ist deaktiviert.', ephemeral: true });
        }

        if (sub === 'create') {
            const prize = interaction.options.getString('preis');
            const durationMin = interaction.options.getInteger('dauer');
            const winnerCount = interaction.options.getInteger('gewinner') || 1;

            await interaction.deferReply({ ephemeral: true });

            try {
                await createGiveaway(
                    interaction.client, botId, interaction.guildId, 
                    interaction.channelId, prize, durationMin * 60000, 
                    winnerCount, interaction.user.id
                );
                return interaction.editReply({ content: `✅ Giveaway für **${prize}** erfolgreich gestartet!` });
            } catch (error) {
                return interaction.editReply({ content: `❌ Fehler: ${error.message}` });
            }
        }

        if (sub === 'end') {
            const giveawayId = interaction.options.getString('id');
            // Wir setzen einfach die Endzeit auf "jetzt", der Poller im giveaway-service erledigt den Rest beim nächsten Check
            await dbQuery(
                'UPDATE bot_giveaways SET ends_at = NOW() WHERE id = ? AND bot_id = ? AND is_active = 1',
                [giveawayId, botId]
            );
            return interaction.reply({ content: `✅ Giveaway #${giveawayId} wird beim nächsten System-Check beendet.`, ephemeral: true });
        }

        if (sub === 'list') {
            const rows = await dbQuery(
                'SELECT id, prize, ends_at FROM bot_giveaways WHERE bot_id = ? AND guild_id = ? AND is_active = 1',
                [botId, interaction.guildId]
            );

            if (!rows.length) {
                return interaction.reply({ content: 'ℹ️ Keine aktiven Giveaways auf diesem Server.', ephemeral: true });
            }

            const list = rows.map(r => `• **#${r.id}**: ${r.prize} (Endet: <t:${Math.floor(new Date(r.ends_at).getTime() / 1000)}:R>)`).join('\n');
            return interaction.reply({ content: `🎁 **Aktive Giveaways:**\n${list}`, ephemeral: true });
        }
    }
};