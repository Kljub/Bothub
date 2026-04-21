// PFAD: /core/installer/src/commands/general/verification.js

const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../../db');
const {
    getSettings,
    buildVerificationEmbed,
    buildVerifyButton,
} = require('../../services/verification-service');

module.exports = {
    key: 'verification',
    data: new SlashCommandBuilder()
        .setName('verification')
        .setDescription('Verification Management')
        .setDefaultMemberPermissions(PermissionFlagsBits.ManageGuild)
        .addSubcommand(sub =>
            sub.setName('setup')
                .setDescription('Sendet das Verification-Embed in den konfigurierten Channel.')
        )
        .addSubcommand(sub =>
            sub.setName('add')
                .setDescription('Verifiziert einen User manuell und vergibt die konfigurierte Rolle.')
                .addUserOption(o =>
                    o.setName('user')
                        .setDescription('Der User der verifiziert werden soll.')
                        .setRequired(true)
                )
        ),

    async execute(interaction, botId) {
        const sub = interaction.options.getSubcommand();

        // Runtime check: is this subcommand enabled?
        const cmdKey = `verification-${sub}`;
        const cmdRow = (await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [botId, cmdKey]
        ))[0];
        if (cmdRow && !cmdRow.is_enabled) {
            return interaction.reply({ content: '❌ Dieser Command ist deaktiviert.', ephemeral: true });
        }

        const guildId = interaction.guildId;

        // ── /verification setup ───────────────────────────────────────────
        if (sub === 'setup') {
            await interaction.deferReply({ ephemeral: true });

            const settings = await getSettings(botId, guildId);
            if (!settings) {
                return interaction.editReply({ content: '❌ Verification ist nicht konfiguriert. Bitte richte das Modul im Dashboard ein.' });
            }

            if (!settings.channel_id) {
                return interaction.editReply({ content: '❌ Kein Standard-Channel konfiguriert. Bitte setze einen Channel im Dashboard.' });
            }

            const channel = await interaction.client.channels.fetch(String(settings.channel_id)).catch(() => null);
            if (!channel) {
                return interaction.editReply({ content: `❌ Channel <#${settings.channel_id}> nicht gefunden oder nicht zugänglich.` });
            }

            const embed = buildVerificationEmbed(settings);
            const row   = buildVerifyButton(settings, guildId);

            try {
                await channel.send({ embeds: [embed], components: [row] });
                return interaction.editReply({ content: `✅ Verification-Embed wurde in <#${settings.channel_id}> gesendet.` });
            } catch (err) {
                return interaction.editReply({ content: `❌ Fehler beim Senden: ${err.message}` });
            }
        }

        // ── /verification add <user> ──────────────────────────────────────
        if (sub === 'add') {
            const targetUser = interaction.options.getUser('user', true);
            await interaction.deferReply({ ephemeral: true });

            const settings = await getSettings(botId, guildId);
            if (!settings) {
                return interaction.editReply({ content: '❌ Verification ist nicht konfiguriert.' });
            }

            if (!settings.verified_role_id) {
                return interaction.editReply({ content: '❌ Keine Verified-Rolle konfiguriert.' });
            }

            const member = await interaction.guild.members.fetch(targetUser.id).catch(() => null);
            if (!member) {
                return interaction.editReply({ content: `❌ User ${targetUser.tag} ist nicht auf diesem Server.` });
            }

            // Check if already has role
            if (member.roles.cache.has(String(settings.verified_role_id))) {
                return interaction.editReply({ content: `ℹ️ ${targetUser.tag} ist bereits verifiziert.` });
            }

            try {
                await member.roles.add(String(settings.verified_role_id));
            } catch (err) {
                return interaction.editReply({ content: `❌ Fehler beim Vergeben der Rolle: ${err.message}` });
            }

            // Remove from pending
            await dbQuery(
                'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                [Number(botId), guildId, targetUser.id]
            ).catch(() => {});

            // Log
            if (settings.log_channel_id) {
                try {
                    const logChannel = await interaction.client.channels.fetch(String(settings.log_channel_id)).catch(() => null);
                    if (logChannel) {
                        const { EmbedBuilder } = require('discord.js');
                        const logEmbed = new EmbedBuilder()
                            .setTitle('Manuelle Verifikation')
                            .setColor('#4caf7d')
                            .setDescription(`<@${targetUser.id}> (${targetUser.id}) wurde manuell von <@${interaction.user.id}> verifiziert.`)
                            .setTimestamp();
                        await logChannel.send({ embeds: [logEmbed] });
                    }
                } catch (_) {}
            }

            return interaction.editReply({ content: `✅ ${targetUser.tag} wurde erfolgreich verifiziert und die Rolle wurde vergeben.` });
        }
    },
};
