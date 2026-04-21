const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');

module.exports = {
    key: 'lovemeter',

    data: new SlashCommandBuilder()
        .setName('lovemeter')
        .setDescription('Misst die Liebeskompatibilität zwischen zwei Usern.')
        .addUserOption(o =>
            o.setName('user1').setDescription('Erster User').setRequired(true)
        )
        .addUserOption(o =>
            o.setName('user2').setDescription('Zweiter User').setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'lovemeter')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }

        const u1 = interaction.options.getUser('user1');
        const u2 = interaction.options.getUser('user2');

        if (u1.id === u2.id) {
            return interaction.reply({ content: '❌ Du kannst nicht denselben User zweimal wählen.', ephemeral: true });
        }

        const percent = Math.ceil(Math.random() * 100);
        const bar     = '█'.repeat(Math.round(percent / 10)) + '░'.repeat(10 - Math.round(percent / 10));

        let verdict;
        if (percent >= 80)      verdict = '💑 Perfektes Match!';
        else if (percent >= 60) verdict = '❤️ Sehr gute Chancen!';
        else if (percent >= 40) verdict = '💛 Es könnte klappen.';
        else if (percent >= 20) verdict = '💙 Eher schwierig.';
        else                    verdict = '💔 Nicht viel Hoffnung.';

        await interaction.reply({
            content: `💘 **Love Meter**\n👤 ${u1} × ${u2}\n\`${bar}\` **${percent}%**\n${verdict}`,
        });
    },
};
