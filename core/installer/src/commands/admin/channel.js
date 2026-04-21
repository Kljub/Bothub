// PFAD: /core/installer/src/commands/channel.js
const { SlashCommandBuilder, PermissionsBitField } = require('discord.js');

module.exports = {
    key: 'channel',

    data: new SlashCommandBuilder()
        .setName('channel')
        .setDescription('Channel moderation commands')
        .addSubcommand(sub =>
            sub.setName('lock')
                .setDescription('Lock the current channel')
        )
        .addSubcommand(sub =>
            sub.setName('unlock')
                .setDescription('Unlock the current channel')
        )
        .addSubcommand(sub =>
            sub.setName('slowmode')
                .setDescription('Set slowmode')
                .addIntegerOption(opt => opt.setName('seconds').setDescription('Seconds').setRequired(true).setMinValue(0).setMaxValue(21600))
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();
        const channel = interaction.channel;

        try {
            if (sub === 'lock') {
                await channel.permissionOverwrites.edit(interaction.guild.roles.everyone, {
                    SendMessages: false
                });

                await interaction.reply({ content: 'Channel locked.', ephemeral: true });
                return;
            }

            if (sub === 'unlock') {
                await channel.permissionOverwrites.edit(interaction.guild.roles.everyone, {
                    SendMessages: null
                });

                await interaction.reply({ content: 'Channel unlocked.', ephemeral: true });
                return;
            }

            if (sub === 'slowmode') {
                const seconds = interaction.options.getInteger('seconds', true);
                await channel.setRateLimitPerUser(seconds);
                await interaction.reply({ content: `Slowmode set to ${seconds}s.`, ephemeral: true });
            }
        } catch {
            await interaction.reply({ content: 'Could not update channel.', ephemeral: true });
        }
    }
};
