// PFAD: /core/installer/src/commands/jobs.js
const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled, getJobs } = require('../../services/economy-service');

module.exports = {
    key: 'jobs',

    data: new SlashCommandBuilder()
        .setName('jobs')
        .setDescription('Zeigt alle verfügbaren Jobs an.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'jobs')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const jobs = await getJobs(botId, interaction.guildId);

        if (jobs.length === 0) {
            return interaction.reply({
                content: '💼 Keine Jobs verfügbar. Ein Admin kann Jobs im Dashboard hinzufügen.',
                ephemeral: true,
            });
        }

        const lines = ['**💼 Verfügbare Jobs**', ''];
        for (const job of jobs) {
            const cd = job.cooldown_seconds >= 3600
                ? `${Math.round(job.cooldown_seconds / 3600)}h`
                : `${Math.round(job.cooldown_seconds / 60)}m`;
            lines.push(`${job.emoji} **${job.name}** *(ID: ${job.id})* — ${job.min_wage}–${job.max_wage} 🪙 | CD: ${cd}`);
            if (job.description) lines.push(`   *${job.description}*`);
        }
        lines.push('', 'Wähle einen Job mit `/job id:<ID>`');

        await interaction.reply({ content: lines.join('\n'), ephemeral: true });
    },
};
