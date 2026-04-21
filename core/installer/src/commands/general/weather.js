'use strict';

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const https = require('https');

const CMD_KEY = 'weather';

// ── HTTP helper ───────────────────────────────────────────────────────────────
function httpGet(url) {
    return new Promise((resolve, reject) => {
        const req = https.get(url, (res) => {
            let raw = '';
            res.on('data', chunk => { raw += chunk; });
            res.on('end', () => {
                try { resolve(JSON.parse(raw)); }
                catch (e) { reject(new Error('Invalid JSON from weather API')); }
            });
        });
        req.on('error', reject);
        req.setTimeout(8000, () => { req.destroy(); reject(new Error('Weather API timeout')); });
    });
}

// ── Weather helpers ───────────────────────────────────────────────────────────
function windDirection(deg) {
    const dirs = ['N', 'NO', 'O', 'SO', 'S', 'SW', 'W', 'NW'];
    return dirs[Math.round(deg / 45) % 8];
}

function weatherEmoji(id) {
    if (id >= 200 && id < 300) return '⛈️';
    if (id >= 300 && id < 400) return '🌦️';
    if (id >= 500 && id < 600) return '🌧️';
    if (id >= 600 && id < 700) return '❄️';
    if (id >= 700 && id < 800) return '🌫️';
    if (id === 800) return '☀️';
    if (id === 801) return '🌤️';
    if (id === 802) return '⛅';
    if (id >= 803) return '☁️';
    return '🌡️';
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : str;
}

// ── Settings loader ───────────────────────────────────────────────────────────
async function loadSettings(db, botId) {
    try {
        const [rows] = await db.execute(
            'SELECT settings_json FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [botId, CMD_KEY]
        );
        if (rows.length > 0 && rows[0].settings_json) {
            return JSON.parse(rows[0].settings_json);
        }
    } catch (_) { /* ignore */ }
    return {};
}

// ── Weather fetcher ───────────────────────────────────────────────────────────
async function fetchWeather(apiKey, location, units) {
    // German/Austrian/Swiss PLZ: 4-5 digits
    const isZip = /^\d{4,5}$/.test(location.trim());
    const enc   = encodeURIComponent(location.trim());
    const base  = 'https://api.openweathermap.org/data/2.5/weather';

    const url = isZip
        ? `${base}?zip=${enc},DE&appid=${apiKey}&units=${units}&lang=de`
        : `${base}?q=${enc}&appid=${apiKey}&units=${units}&lang=de`;

    return httpGet(url);
}

// ── Build Discord embed ───────────────────────────────────────────────────────
function buildEmbed(data, units) {
    const unitSymbol = units === 'imperial' ? '°F' : '°C';
    const windUnit   = units === 'imperial' ? 'mph' : 'm/s';

    const weather    = data.weather?.[0] || {};
    const main       = data.main       || {};
    const wind       = data.wind       || {};
    const sys        = data.sys        || {};

    const emoji      = weatherEmoji(weather.id ?? 800);
    const cityName   = data.name || '?';
    const country    = sys.country ? `, ${sys.country}` : '';
    const desc       = capitalize(weather.description || '—');
    const temp       = main.temp       != null ? `${main.temp.toFixed(1)}${unitSymbol}`      : '—';
    const feelsLike  = main.feels_like != null ? `${main.feels_like.toFixed(1)}${unitSymbol}` : '—';
    const humidity   = main.humidity   != null ? `${main.humidity}%`                          : '—';
    const windSpeed  = wind.speed      != null ? `${wind.speed.toFixed(1)} ${windUnit}`       : '—';
    const windDir    = wind.deg        != null ? ` ${windDirection(wind.deg)}`                 : '';
    const visibility = data.visibility != null ? `${(data.visibility / 1000).toFixed(1)} km`  : '—';
    const pressure   = main.pressure   != null ? `${main.pressure} hPa`                       : '—';
    const sunrise    = sys.sunrise     ? `<t:${sys.sunrise}:t>`  : '—';
    const sunset     = sys.sunset      ? `<t:${sys.sunset}:t>`   : '—';

    // Pick embed colour by weather condition
    let colour = 0x5865F2;
    if (weather.id >= 200 && weather.id < 300) colour = 0x6B46C1; // thunder
    else if (weather.id >= 500 && weather.id < 600) colour = 0x3B82F6; // rain
    else if (weather.id >= 600 && weather.id < 700) colour = 0xE0F2FE; // snow
    else if (weather.id === 800) colour = 0xF59E0B;                    // clear

    return new EmbedBuilder()
        .setColor(colour)
        .setTitle(`${emoji}  Wetter für ${cityName}${country}`)
        .setDescription(`**${desc}**`)
        .addFields(
            {
                name:   '🌡️ Temperatur',
                value:  `${temp}  (gefühlt ${feelsLike})`,
                inline: true,
            },
            {
                name:   '💧 Luftfeuchtigkeit',
                value:  humidity,
                inline: true,
            },
            {
                name:   '💨 Wind',
                value:  `${windSpeed}${windDir}`,
                inline: true,
            },
            {
                name:   '👁️ Sichtweite',
                value:  visibility,
                inline: true,
            },
            {
                name:   '🔵 Luftdruck',
                value:  pressure,
                inline: true,
            },
            {
                name:   '\u200B',
                value:  '\u200B',
                inline: true,
            },
            {
                name:   '🌅 Sonnenaufgang',
                value:  sunrise,
                inline: true,
            },
            {
                name:   '🌇 Sonnenuntergang',
                value:  sunset,
                inline: true,
            },
        )
        .setFooter({ text: 'Daten von OpenWeatherMap' })
        .setTimestamp();
}

// ── Command export ────────────────────────────────────────────────────────────
module.exports = {
    key: CMD_KEY,

    data: new SlashCommandBuilder()
        .setName('weather')
        .setDescription('Zeigt das aktuelle Wetter für einen Ort an.')
        .addStringOption(opt =>
            opt.setName('ort')
                .setDescription('Stadtname oder Postleitzahl – z. B. "Berlin" oder "10115" (optional)')
                .setRequired(false)
                .setMaxLength(128)
        ),

    async execute(interaction, botId, botManager) {
        await interaction.deferReply();

        // DB is exposed on botManager; fall back to the shared db module
        const db = botManager?.db ?? require('../../db');

        // Load per-bot settings
        const settings = await loadSettings(db, botId);
        const apiKey   = settings.api_key || process.env.WEATHER_API_KEY || '';
        const units    = settings.units   || 'metric';

        if (!apiKey) {
            return interaction.editReply({
                content: [
                    '❌ **Kein API-Key konfiguriert.**',
                    'Bitte im BotHub-Dashboard unter **Weather → Einstellungen** einen kostenlosen',
                    '[OpenWeatherMap API-Key](https://openweathermap.org/api) eintragen.',
                ].join('\n'),
            });
        }

        // Determine location: command arg → per-bot default → error
        const inputLoc  = interaction.options.getString('ort')?.trim() ?? '';
        const location  = inputLoc !== '' ? inputLoc : (settings.default_location ?? '');

        if (!location) {
            return interaction.editReply({
                content: [
                    '❌ Bitte einen Ort angeben:',
                    '`/weather Berlin`  oder  `/weather 10115`',
                    '',
                    'Du kannst im Dashboard auch einen **Standard-Ort** hinterlegen.',
                ].join('\n'),
            });
        }

        // Fetch weather
        let data;
        try {
            data = await fetchWeather(apiKey, location, units);
        } catch (err) {
            console.error(`[weather] fetch error: ${err.message}`);
            return interaction.editReply({
                content: '❌ Die Wetterdaten konnten nicht abgerufen werden. Bitte später erneut versuchen.',
            });
        }

        // API-level errors (wrong city, invalid key, …)
        if (data.cod !== 200) {
            const msg = typeof data.message === 'string' ? data.message : 'Unbekannter Fehler.';

            // User-friendly messages for common codes
            if (data.cod === 404) {
                return interaction.editReply({
                    content: `❌ Ort **${location}** nicht gefunden. Versuche z. B. \`Berlin\`, \`München\` oder eine PLZ wie \`10115\`.`,
                });
            }
            if (data.cod === 401) {
                return interaction.editReply({
                    content: '❌ Ungültiger API-Key. Bitte im Dashboard einen gültigen OpenWeatherMap API-Key eintragen.',
                });
            }
            return interaction.editReply({ content: `❌ Fehler: ${capitalize(msg)}` });
        }

        const embed = buildEmbed(data, units);
        await interaction.editReply({ embeds: [embed] });
    },
};
