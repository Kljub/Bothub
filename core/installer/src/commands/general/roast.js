const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');

const ROASTS = [
    'Du bist so langsam, du hast wahrscheinlich einen Rückgang des Internets verursacht.',
    'Dein IQ ist tiefer als der Meeresgrund.',
    'Selbst ein Taschenrechner wäre smarter.',
    'Entschuldige, ich kann dich nicht hören — du klingst zu sehr nach jemandem, dem ich nicht zuhören will.',
    'Dein Gesicht hat mehr Bugs als schlechter Code.',
    'Du bist der Grund, warum Anweisungen nötig sind.',
    'Sogar dein Schatten läuft manchmal weg.',
    'Ich würde dir eine schmutzige Bemerkung geben, aber du hast bereits eine.',
    'Deine Familie muss sich freuen, wenn du das Haus verlässt.',
    'Du bist der lebende Beweis, dass Evolution auch rückwärts gehen kann.',
    'Ich wollte mich mit dir anlegen, aber ich kämpfe nicht mit Unbewaffneten.',
    'Du hast zwei Gehirnhälften — eine ist verloren und die andere sucht sie.',
    'Ich wäre beleidigt, aber dafür müsste mir deine Meinung wichtig sein.',
    'Du bist das Beste daran, wenn du gehst.',
    'Sogar Stille ist lauter als deine Argumente.',
    'Man kann dich nicht ignorieren, aber man kann es versuchen.',
    'Du sprichst viel und sagst wenig.',
    'Ich habe schon bessere Ideen in einem Keksautomaten gehört.',
    'Du bist der Grund, warum es keinen Knopf "Alle zustimmen" im Internet gibt.',
    'Dein Selbstbewusstsein ist beeindruckend — so falsch und doch so sicher.',
];

module.exports = {
    key: 'roast',

    data: new SlashCommandBuilder()
        .setName('roast')
        .setDescription('Einen User rösten.')
        .addUserOption(o =>
            o.setName('user').setDescription('Zu röstender User').setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'roast')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }

        const target = interaction.options.getUser('user');
        const roast  = ROASTS[Math.floor(Math.random() * ROASTS.length)];

        await interaction.reply({
            content: `🔥 ${target}, ${roast}`,
        });
    },
};
