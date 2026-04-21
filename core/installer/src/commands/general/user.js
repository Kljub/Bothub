// PFAD: /core/installer/src/commands/user.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'user',

    data: new SlashCommandBuilder()
        .setName('user')
        .setDescription('User moderation commands')
        .addSubcommand(sub =>
            sub.setName('history')
                .setDescription('View user history')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('clear-history')
                .setDescription('Clear user history')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('nick')
                .setDescription('Change nickname')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
                .addStringOption(opt => opt.setName('nickname').setDescription('Nickname').setRequired(true))
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();

        if (sub === 'history') {
            const user = interaction.options.getUser('user', true);
            await interaction.reply({ content: `History for ${user.tag}: not implemented yet.`, ephemeral: true });
            return;
        }

        if (sub === 'clear-history') {
            const user = interaction.options.getUser('user', true);
            await interaction.reply({ content: `History cleared for ${user.tag} (placeholder).`, ephemeral: true });
            return;
        }

        if (sub === 'nick') {
            const user = interaction.options.getUser('user', true);
            const nickname = interaction.options.getString('nickname', true);
            const member = await interaction.guild.members.fetch(user.id).catch(() => null);

            if (!member) {
                await interaction.reply({ content: 'User not found.', ephemeral: true });
                return;
            }

            try {
                await member.setNickname(nickname);
                await interaction.reply({ content: `Nickname updated for ${user.tag}.`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not change nickname.', ephemeral: true });
            }
        }
    }
};
