// PFAD: /core/installer/src/commands/warn.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'warn',

    data: new SlashCommandBuilder()
        .setName('warn')
        .setDescription('Warns a user')
        .addUserOption(opt =>
            opt.setName('user')
                .setDescription('User to warn')
                .setRequired(true)
        )
        .addStringOption(opt =>
            opt.setName('reason')
                .setDescription('Warning reason')
                .setRequired(false)
        ),

    async execute(interaction) {
        const user = interaction.options.getUser('user', true);
        const reason = interaction.options.getString('reason') || 'No reason provided';

        await interaction.reply({
            content: `Warned ${user.tag}. Reason: ${reason}`,
            ephemeral: true
        });
    }
};
