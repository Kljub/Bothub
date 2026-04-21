'use strict';

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../../db');
const { fetchEpicFreeGames, fetchSteamFreeGames } = require('../../services/free-games-service');

const CMD_KEY = 'free-games';

// ── Load bot settings ─────────────────────────────────────────────────────────
async function loadSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM free_games_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
    } catch (_) {
        return null;
    }
}

// ── Build platform embed ──────────────────────────────────────────────────────
function buildPlatformEmbed(platform, games) {
    const isEpic  = platform === 'epic';
    const color   = isEpic ? 0x0078f2 : 0x1b2838;
    const icon    = isEpic ? '🎮' : '🎲';
    const brand   = isEpic ? 'Epic Games Store' : 'Steam';
    const storeUrl = isEpic
        ? 'https://store.epicgames.com/en-US/free-games'
        : 'https://store.steampowered.com/specials';

    const embed = new EmbedBuilder()
        .setColor(color)
        .setAuthor({ name: `${icon} ${brand} — Kostenlose Spiele` })
        .setTimestamp()
        .setFooter({ text: 'Daten live abgerufen · BotHub Free Games' });

    if (games.length === 0) {
        embed.setDescription('Aktuell keine kostenlosen Spiele verfügbar.');
        return embed;
    }

    for (const game of games.slice(0, 8)) {
        let value = `[Jetzt holen →](${game.url})`;

        if (game.endDate) {
            try {
                const ts = Math.floor(new Date(game.endDate).getTime() / 1000);
                value += `\n⏳ Kostenlos bis <t:${ts}:D>`;
            } catch (_) {}
        }

        if (game.description) {
            const short = game.description.length > 80
                ? game.description.slice(0, 77) + '…'
                : game.description;
            value += `\n*${short}*`;
        }

        embed.addFields({ name: game.title || '?', value, inline: false });
    }

    // Thumbnail from first game image
    const firstImg = games.find(g => g.image)?.image;
    if (firstImg) embed.setImage(firstImg);

    embed.setDescription(
        `**${games.length} Spiel${games.length !== 1 ? 'e' : ''}** gerade kostenlos` +
        (games.length > 1 ? ` — [Alle anzeigen](${storeUrl})` : '')
    );

    return embed;
}

// ── Command export ────────────────────────────────────────────────────────────
module.exports = {
    key: CMD_KEY,

    data: new SlashCommandBuilder()
        .setName('free-games')
        .setDescription('Zeigt aktuell kostenlose Spiele von den aktivierten Plattformen.'),

    async execute(interaction, botId) {
        await interaction.deferReply();

        const settings = await loadSettings(botId);

        if (!settings) {
            return interaction.editReply({
                content: [
                    '⚙️ **Free Games nicht konfiguriert.**',
                    'Bitte im BotHub-Dashboard unter **Free Games → Einstellungen** einen Discord-Kanal eintragen und das Modul aktivieren.',
                ].join('\n'),
            });
        }

        if (Number(settings.is_enabled) !== 1) {
            return interaction.editReply({
                content: '❌ Das Free Games Modul ist derzeit **deaktiviert**. Aktiviere es im BotHub-Dashboard.',
            });
        }

        const epicEnabled  = Number(settings.epic_enabled)  === 1;
        const steamEnabled = Number(settings.steam_enabled) === 1;

        if (!epicEnabled && !steamEnabled) {
            return interaction.editReply({
                content: '⚠️ Keine Plattform aktiviert. Bitte im BotHub-Dashboard mindestens **Epic Games** oder **Steam** aktivieren.',
            });
        }

        const embeds = [];
        const errors = [];

        if (epicEnabled) {
            try {
                const games = await fetchEpicFreeGames();
                embeds.push(buildPlatformEmbed('epic', games));
            } catch (e) {
                console.warn(`[free-games cmd] Epic fetch error: ${e?.message}`);
                errors.push('Epic Games Store');
            }
        }

        if (steamEnabled) {
            try {
                const games = await fetchSteamFreeGames();
                embeds.push(buildPlatformEmbed('steam', games));
            } catch (e) {
                console.warn(`[free-games cmd] Steam fetch error: ${e?.message}`);
                errors.push('Steam');
            }
        }

        if (embeds.length === 0) {
            return interaction.editReply({
                content: '❌ Die Spieldaten konnten gerade nicht abgerufen werden. Bitte später erneut versuchen.',
            });
        }

        const payload = { embeds };

        if (errors.length > 0) {
            payload.content = `⚠️ Fehler beim Abruf von: **${errors.join(', ')}**`;
        }

        await interaction.editReply(payload);
    },
};
