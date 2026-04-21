// PFAD: /core/installer/src/commands/case.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'case',

    data: new SlashCommandBuilder()
        .setName('case')
        .setDescription('Case commands')
        .addSubcommand(sub =>
            sub.setName('view')
                .setDescription('View a moderation case')
                .addIntegerOption(opt => opt.setName('id').setDescription('Case ID').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('remove')
                .setDescription('Remove a moderation case')
                .addIntegerOption(opt => opt.setName('id').setDescription('Case ID').setRequired(true))
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();
        const id = interaction.options.getInteger('id', true);

        await interaction.reply({
            content: sub === 'view'
                ? `Case ${id}: not implemented yet.`
                : `Case ${id} removed (placeholder).`,
            ephemeral: true
        });
    }
};
