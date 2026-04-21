const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');

const ANSWERS = [
    'Ja!', 'Nein.', 'Absolut!', 'Eher nicht.', 'Definitiv.',
    'Ich bezweifle es.', 'Ohne Zweifel.', 'Meine Quellen sagen nein.',
    'Sehr wahrscheinlich.', 'Unwahrscheinlich.', 'Es ist sicher.',
    'Frag später nochmal.', 'Keine Ahnung!', 'Alles deutet auf Ja.',
    'Keine Antwort jetzt.',
];

module.exports = {
    key: '8ball',

    data: new SlashCommandBuilder()
        .setName('8ball')
        .setDescription('Stell der magischen 8-Ball eine Frage.')
        .addStringOption(o =>
            o.setName('question').setDescription('Deine Frage').setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, '8ball')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }

        const question = interaction.options.getString('question');
        const answer   = ANSWERS[Math.floor(Math.random() * ANSWERS.length)];

        await interaction.reply({
            content: `🎱 **Frage:** ${question}\n🤖 **Antwort:** ${answer}`,
        });
    },
};
