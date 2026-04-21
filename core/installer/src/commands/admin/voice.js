// PFAD: /core/installer/src/commands/voice.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'voice',

    data: new SlashCommandBuilder()
        .setName('voice')
        .setDescription('Voice moderation commands')
        .addSubcommand(sub =>
            sub.setName('deaf')
                .setDescription('Deafen a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('undeaf')
                .setDescription('Undeafen a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();
        const user = interaction.options.getUser('user', true);
        const member = await interaction.guild.members.fetch(user.id).catch(() => null);

        if (!member || !member.voice) {
            await interaction.reply({ content: 'User not found in voice.', ephemeral: true });
            return;
        }

        try {
            if (sub === 'deaf') {
                await member.voice.setDeaf(true);
                await interaction.reply({ content: `${user.tag} has been deafened.`, ephemeral: true });
                return;
            }

            if (sub === 'undeaf') {
                await member.voice.setDeaf(false);
                await interaction.reply({ content: `${user.tag} has been undeafened.`, ephemeral: true });
            }
        } catch {
            await interaction.reply({ content: 'Could not update voice state.', ephemeral: true });
        }
    }
};
