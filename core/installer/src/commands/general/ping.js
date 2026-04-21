// PFAD: /core/installer/src/commands/ping.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'ping',

    data: new SlashCommandBuilder()
        .setName('ping')
        .setDescription('Shows bot latency'),

    async execute(interaction) {

        const start = Date.now();

        const reply = await interaction.reply({
            content: '🏓 Pinging...',
            fetchReply: true
        });

        const apiLatency = Date.now() - start;
        const gatewayPing = interaction.client.ws.ping;

        await interaction.editReply(
`🏓 **Pong!**
API Latency: **${apiLatency}ms**
Gateway Ping: **${gatewayPing}ms**`
        );

    }
};