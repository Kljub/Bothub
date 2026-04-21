// PFAD: /core/installer/src/commands/job.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getUserJob, assignJob,
    getOrCreateWallet, adjustWallet, getCooldownExpiry, setCooldown,
    formatMoney, formatRemaining, randInt,
} = require('../../services/economy-service');

module.exports = {
    key: 'job',

    data: new SlashCommandBuilder()
        .setName('job')
        .setDescription('Job auswählen oder mit aktuellem Job arbeiten.')
        .addIntegerOption(o =>
            o.setName('id')
             .setDescription('Job-ID zum Auswählen (leer = mit aktuellem Job arbeiten)')
             .setRequired(false)
             .setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'job')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId    = interaction.user.id;
        const guildId   = interaction.guildId;
        const jobIdArg  = interaction.options.getInteger('id');
        const settings  = await getEcoSettings(botId, guildId);

        // ── Assign mode ───────────────────────────────────────────────────────
        if (jobIdArg !== null) {
            try {
                await assignJob(botId, guildId, userId, jobIdArg);
                const userJob = await getUserJob(botId, guildId, userId);
                return interaction.reply({
                    content: `✅ Du bist jetzt **${userJob.emoji} ${userJob.name}**! Arbeite mit \`/job\` (ohne ID).`,
                    ephemeral: true,
                });
            } catch (err) {
                return interaction.reply({ content: `❌ ${err.message}`, ephemeral: true });
            }
        }

        // ── Work mode ─────────────────────────────────────────────────────────
        const userJob = await getUserJob(botId, guildId, userId);
        if (!userJob) {
            return interaction.reply({
                content: '❌ Du hast keinen Job. Sieh dir Jobs mit `/jobs` an und wähle einen mit `/job id:<ID>`.',
                ephemeral: true,
            });
        }

        const cooldownKey = `job_${userJob.job_id}`;
        const cooldown    = await getCooldownExpiry(botId, guildId, userId, cooldownKey);
        if (cooldown) {
            return interaction.reply({
                content: `⏳ Du kannst erst in **${formatRemaining(cooldown)}** als **${userJob.emoji} ${userJob.name}** wieder arbeiten.`,
                ephemeral: true,
            });
        }

        const earned = randInt(Number(userJob.min_wage), Number(userJob.max_wage));
        await getOrCreateWallet(botId, guildId, userId);
        await adjustWallet(botId, guildId, userId, earned);
        await setCooldown(botId, guildId, userId, cooldownKey, Number(userJob.cooldown_seconds));

        await interaction.reply({
            content: `${userJob.emoji} Du hast als **${userJob.name}** gearbeitet und **${formatMoney(earned, settings)}** verdient!`,
        });
    },
};
