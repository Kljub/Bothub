const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');

const CHOICES = ['rock', 'paper', 'scissors'];
const NAMES   = { rock: '🪨 Stein', paper: '📄 Papier', scissors: '✂️ Schere' };

// result[player][bot] = outcome
const OUTCOME = {
    rock:     { rock: 'draw', paper: 'lose', scissors: 'win' },
    paper:    { rock: 'win',  paper: 'draw', scissors: 'lose' },
    scissors: { rock: 'lose', paper: 'win',  scissors: 'draw' },
};

module.exports = {
    key: 'rps',

    data: new SlashCommandBuilder()
        .setName('rps')
        .setDescription('Stein, Papier, Schere spielen.')
        .addStringOption(o =>
            o.setName('choice')
             .setDescription('Deine Wahl')
             .setRequired(true)
             .addChoices(
                 { name: '🪨 Stein',  value: 'rock'     },
                 { name: '📄 Papier', value: 'paper'    },
                 { name: '✂️ Schere', value: 'scissors' },
             )
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'rps')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }

        const player = interaction.options.getString('choice');
        const bot    = CHOICES[Math.floor(Math.random() * CHOICES.length)];
        const result = OUTCOME[player][bot];

        const lines = {
            win:  `🎉 **Du gewinnst!** Ich hatte ${NAMES[bot]}.`,
            lose: `😔 **Du verlierst!** Ich hatte ${NAMES[bot]}.`,
            draw: `🤝 **Unentschieden!** Wir hatten beide ${NAMES[bot]}.`,
        };

        await interaction.reply({
            content: `✊ **Stein, Papier, Schere**\nDu: ${NAMES[player]} | Ich: ${NAMES[bot]}\n${lines[result]}`,
        });
    },
};
