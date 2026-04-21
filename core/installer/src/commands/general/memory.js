// PFAD: /core/installer/src/commands/memory.js

const { SlashCommandBuilder } = require('discord.js');

function formatBytes(bytes) {
    const value = Number(bytes || 0);

    if (!Number.isFinite(value) || value <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    const decimals = unitIndex === 0 ? 0 : 2;
    return `${size.toFixed(decimals)} ${units[unitIndex]}`;
}

function formatUptime(secondsTotal) {
    const total = Number(secondsTotal || 0);

    if (!Number.isFinite(total) || total <= 0) {
        return '0s';
    }

    const seconds = Math.floor(total % 60);
    const minutes = Math.floor((total / 60) % 60);
    const hours = Math.floor((total / 3600) % 24);
    const days = Math.floor(total / 86400);

    const parts = [];

    if (days > 0) {
        parts.push(`${days}d`);
    }
    if (hours > 0 || days > 0) {
        parts.push(`${hours}h`);
    }
    if (minutes > 0 || hours > 0 || days > 0) {
        parts.push(`${minutes}m`);
    }

    parts.push(`${seconds}s`);

    return parts.join(' ');
}

module.exports = {
    key: 'memory',

    data: new SlashCommandBuilder()
        .setName('memory')
        .setDescription('Zeigt RAM- und Laufzeitdaten des Bots an.'),

    async execute(interaction) {
        const mem = process.memoryUsage();
        const uptimeSeconds = process.uptime();
        const gatewayPing = interaction.client && interaction.client.ws
            ? Number(interaction.client.ws.ping || 0)
            : 0;

        const lines = [
            '🧠 **Bot Memory**',
            `RSS: **${formatBytes(mem.rss)}**`,
            `Heap Used: **${formatBytes(mem.heapUsed)}**`,
            `Heap Total: **${formatBytes(mem.heapTotal)}**`,
            `External: **${formatBytes(mem.external)}**`,
            `Gateway Ping: **${gatewayPing}ms**`,
            `Uptime: **${formatUptime(uptimeSeconds)}**`,
        ];

        await interaction.reply({
            content: lines.join('\n'),
            ephemeral: true
        });
    }
};