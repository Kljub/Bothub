// PFAD: /core/installer/src/services/free-games-service.js
//
// Fetches free game giveaways from:
//   - Epic Games Store (direct API, no key needed)
//   - Steam (via GamerPower public API, no key needed)
// Checks every hour and posts Discord embeds when new games appear.

const https = require('https');
const { dbQuery } = require('../db');
const { EmbedBuilder } = require('discord.js');

const timers      = new Map(); // Map<botId, Interval>  — hourly new-game check
const schedTimers = new Map(); // Map<botId, Interval>  — per-minute schedule check
const schedLastFired = new Map(); // Map<botId, 'YYYY-MM-DDTHH:MM'>

const CHECK_INTERVAL_MS  = 60 * 60 * 1000; // 1 hour
const SCHED_INTERVAL_MS  = 60 * 1000;       // 1 minute
const INITIAL_DELAY_MS   = 45_000;           // 45s after bot start

const DAY_ABBREVS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// ── HTTP helper ───────────────────────────────────────────────────────────────

function httpsGet(url, headers = {}) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(url);
        const options = {
            hostname: urlObj.hostname,
            path:     urlObj.pathname + urlObj.search,
            method:   'GET',
            headers: {
                'Accept':     'application/json',
                'User-Agent': 'BotHub/1.0 FreeGamesService',
                ...headers,
            },
        };
        const req = https.request(options, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                resolve(httpsGet(res.headers.location, headers));
                return;
            }
            let data = '';
            res.on('data', c => { data += c; });
            res.on('end', () => resolve({ status: res.statusCode, body: data }));
        });
        req.on('error', reject);
        req.setTimeout(12000, () => { req.destroy(new Error('timeout')); });
        req.end();
    });
}

// ── Epic Games API ────────────────────────────────────────────────────────────

/**
 * Returns currently free Epic Games Store games.
 * @returns {Array<{ id: string, title: string, description: string, image: string, url: string, endDate: string|null }>}
 */
async function fetchEpicFreeGames() {
    const url = 'https://store-site-backend-static.ak.epicgames.com/freeGamesPromotions?locale=en-US&country=US&allowCountries=US';
    let res;
    try {
        res = await httpsGet(url);
    } catch (e) {
        console.warn('[FGSvc] Epic fetch error:', e?.message);
        return [];
    }

    if (res.status !== 200) return [];

    let json;
    try { json = JSON.parse(res.body); } catch { return []; }

    const elements = json?.data?.Catalog?.searchStore?.elements;
    if (!Array.isArray(elements)) return [];

    const now = Date.now();
    const games = [];

    for (const item of elements) {
        // Only include items with an active free promotion (discountPercentage === 0)
        const promos = item?.promotions?.promotionalOffers;
        if (!Array.isArray(promos) || promos.length === 0) continue;

        let isCurrentlyFree = false;
        let endDate = null;

        for (const promoGroup of promos) {
            const offers = promoGroup?.promotionalOffers;
            if (!Array.isArray(offers)) continue;
            for (const offer of offers) {
                const start = new Date(offer.startDate).getTime();
                const end   = new Date(offer.endDate).getTime();
                const pct   = offer?.discountSetting?.discountPercentage ?? 100;
                if (pct === 0 && now >= start && now <= end) {
                    isCurrentlyFree = true;
                    endDate = offer.endDate;
                    break;
                }
            }
            if (isCurrentlyFree) break;
        }

        if (!isCurrentlyFree) continue;

        // Thumbnail image
        const images = item?.keyImages || [];
        const thumb = (
            images.find(i => i.type === 'Thumbnail')      ||
            images.find(i => i.type === 'DieselStoreFront') ||
            images.find(i => i.type === 'OfferImageWide')  ||
            images[0]
        );

        // Slug / URL
        const slug = item?.catalogNs?.mappings?.[0]?.pageSlug
            || item?.productSlug
            || item?.urlSlug
            || '';

        const gameUrl = slug
            ? `https://store.epicgames.com/en-US/p/${slug}`
            : 'https://store.epicgames.com/en-US/free-games';

        games.push({
            id:          `epic_${String(item.id || item.title)}`,
            title:       String(item.title || ''),
            description: String(item.description || ''),
            image:       thumb ? String(thumb.url) : '',
            url:         gameUrl,
            endDate:     endDate,
            platform:    'epic',
        });
    }

    return games;
}

// ── GamerPower API (Steam giveaways) ─────────────────────────────────────────

/**
 * Returns active Steam free games/giveaways from GamerPower.
 * @returns {Array<{ id: string, title: string, description: string, image: string, url: string, endDate: string|null }>}
 */
async function fetchSteamFreeGames() {
    const url = 'https://www.gamerpower.com/api/giveaways?platform=steam&type=game&sort-by=date';
    let res;
    try {
        res = await httpsGet(url);
    } catch (e) {
        console.warn('[FGSvc] GamerPower/Steam fetch error:', e?.message);
        return [];
    }

    if (res.status !== 200) return [];

    let json;
    try { json = JSON.parse(res.body); } catch { return []; }

    if (!Array.isArray(json)) return [];

    return json.slice(0, 8).map(item => ({
        id:          `steam_${String(item.id || item.title)}`,
        title:       String(item.title || ''),
        description: String(item.description || '').slice(0, 200),
        image:       String(item.thumbnail || item.image || ''),
        url:         String(item.open_giveaway_url || item.giveaway_url || 'https://store.steampowered.com/'),
        endDate:     item.end_date && item.end_date !== 'N/A' ? item.end_date : null,
        platform:    'steam',
    }));
}

// ── Send Discord notification ─────────────────────────────────────────────────

async function sendFreeGamesEmbed(client, settings, games, platform) {
    const channelId = String(settings.channel_id || '');
    if (!channelId) return;

    let channel;
    try {
        channel = await client.channels.fetch(channelId);
    } catch (_) {
        console.warn(`[FGSvc] Bot ${settings.bot_id}: channel ${channelId} not found`);
        return;
    }
    if (!channel || !channel.isTextBased()) return;

    const pingRole = String(settings.ping_role_id || '').trim();
    const isEpic   = platform === 'epic';

    const color   = isEpic ? 0x0078f2 : 0x1b2838;
    const icon    = isEpic ? '🎮' : '🎲';
    const brand   = isEpic ? 'Epic Games Store' : 'Steam';
    const brandUrl = isEpic ? 'https://store.epicgames.com/en-US/free-games' : 'https://store.steampowered.com/specials#p=0&tab=TopRated';

    for (const game of games) {
        const embed = new EmbedBuilder()
            .setColor(color)
            .setTitle(`${icon} Kostenloses Spiel: ${game.title}`)
            .setURL(game.url)
            .setFooter({ text: brand, iconURL: isEpic
                ? 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/31/Epic_Games_logo.svg/120px-Epic_Games_logo.svg.png'
                : 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/83/Steam_icon_logo.svg/120px-Steam_icon_logo.svg.png'
            })
            .setTimestamp();

        if (game.description) embed.setDescription(game.description.slice(0, 350));
        if (game.image)       embed.setImage(game.image);

        if (game.endDate) {
            try {
                const ts = Math.floor(new Date(game.endDate).getTime() / 1000);
                embed.addFields({ name: '⏳ Kostenlos bis', value: `<t:${ts}:F>`, inline: true });
            } catch (_) {}
        }

        embed.addFields({ name: '🔗 Jetzt holen', value: `[${brand}](${game.url})`, inline: true });

        const payload = { embeds: [embed] };
        if (pingRole && games.indexOf(game) === 0) payload.content = `<@&${pingRole}>`;

        try {
            await channel.send(payload);
            await new Promise(r => setTimeout(r, 800)); // small delay between messages
        } catch (e) {
            console.warn(`[FGSvc] Bot ${settings.bot_id}: send failed:`, e?.message);
        }
    }
}

// ── Poll tick ─────────────────────────────────────────────────────────────────

async function pollFreeGames(client, botId) {
    let rows;
    try {
        rows = await dbQuery(
            'SELECT * FROM free_games_settings WHERE bot_id = ? AND is_enabled = 1 LIMIT 1',
            [Number(botId)]
        );
    } catch (e) {
        // Table doesn't exist yet — silently skip
        return;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;
    const settings = rows[0];

    // Load previously known game IDs
    let knownIds = new Set();
    try {
        const stored = JSON.parse(String(settings.last_game_ids || '[]'));
        if (Array.isArray(stored)) knownIds = new Set(stored);
    } catch (_) {}

    const allNewGames = [];

    // Epic Games
    if (Number(settings.epic_enabled) === 1) {
        try {
            const epicGames = await fetchEpicFreeGames();
            const newEpic   = epicGames.filter(g => !knownIds.has(g.id));
            if (newEpic.length > 0) {
                // First run: just seed, don't notify
                if (knownIds.size === 0) {
                    epicGames.forEach(g => knownIds.add(g.id));
                } else {
                    await sendFreeGamesEmbed(client, settings, newEpic, 'epic');
                    newEpic.forEach(g => allNewGames.push(g));
                    epicGames.forEach(g => knownIds.add(g.id));
                }
            }
        } catch (e) {
            console.warn(`[FGSvc] Bot ${botId}: Epic poll error:`, e?.message);
        }
    }

    // Steam
    if (Number(settings.steam_enabled) === 1) {
        try {
            const steamGames = await fetchSteamFreeGames();
            const newSteam   = steamGames.filter(g => !knownIds.has(g.id));
            if (newSteam.length > 0) {
                if (knownIds.size === 0) {
                    steamGames.forEach(g => knownIds.add(g.id));
                } else {
                    await sendFreeGamesEmbed(client, settings, newSteam, 'steam');
                    newSteam.forEach(g => allNewGames.push(g));
                    steamGames.forEach(g => knownIds.add(g.id));
                }
            }
        } catch (e) {
            console.warn(`[FGSvc] Bot ${botId}: Steam poll error:`, e?.message);
        }
    }

    // Save updated known IDs
    try {
        await dbQuery(
            'UPDATE free_games_settings SET last_game_ids = ?, last_checked_at = NOW() WHERE bot_id = ?',
            [JSON.stringify([...knownIds]), Number(botId)]
        );
    } catch (_) {}
}

// ── Schedule: force-post current games at a configured time ──────────────────

async function forceSendScheduled(client, settings) {
    if (Number(settings.epic_enabled) === 1) {
        try {
            const games = await fetchEpicFreeGames();
            if (games.length > 0) await sendFreeGamesEmbed(client, settings, games, 'epic');
        } catch (e) {
            console.warn(`[FGSvc] Schedule Bot ${settings.bot_id}: Epic error:`, e?.message);
        }
    }
    if (Number(settings.steam_enabled) === 1) {
        try {
            const games = await fetchSteamFreeGames();
            if (games.length > 0) await sendFreeGamesEmbed(client, settings, games, 'steam');
        } catch (e) {
            console.warn(`[FGSvc] Schedule Bot ${settings.bot_id}: Steam error:`, e?.message);
        }
    }
}

async function checkScheduleTick(client, botId) {
    let rows;
    try {
        rows = await dbQuery(
            'SELECT * FROM free_games_settings WHERE bot_id = ? AND is_enabled = 1 AND schedule_enabled = 1 LIMIT 1',
            [Number(botId)]
        );
    } catch (_) { return; }
    if (!Array.isArray(rows) || rows.length === 0) return;

    const settings  = rows[0];
    const schedTime = String(settings.schedule_time || '09:00').trim(); // HH:MM
    const schedDays = String(settings.schedule_days || '')
        .split(',').map(d => d.trim()).filter(Boolean);

    const now        = new Date();
    const nowHH      = String(now.getHours()).padStart(2, '0');
    const nowMM      = String(now.getMinutes()).padStart(2, '0');
    const nowTime    = `${nowHH}:${nowMM}`;
    const todayAbbr  = DAY_ABBREVS[now.getDay()];

    if (nowTime !== schedTime) return;
    if (!schedDays.includes(todayAbbr)) return;

    // Prevent double-fire within the same minute
    const fireKey = `${botId}:${now.toISOString().slice(0, 16)}`;
    if (schedLastFired.get(Number(botId)) === fireKey) return;
    schedLastFired.set(Number(botId), fireKey);

    console.log(`[FGSvc] Bot ${botId}: schedule fired at ${nowTime} (${todayAbbr})`);
    await forceSendScheduled(client, settings);
}

// ── Public API ────────────────────────────────────────────────────────────────

function startFreeGamesService(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return;

    // Hourly new-game detection
    const handle = setInterval(async () => {
        try { await pollFreeGames(client, numericId); } catch (e) {
            console.warn(`[FGSvc] Bot ${numericId}: tick error:`, e?.message);
        }
    }, CHECK_INTERVAL_MS);
    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Per-minute schedule check
    const schedHandle = setInterval(async () => {
        try { await checkScheduleTick(client, numericId); } catch (e) {
            console.warn(`[FGSvc] Bot ${numericId}: schedule tick error:`, e?.message);
        }
    }, SCHED_INTERVAL_MS);
    if (schedHandle.unref) schedHandle.unref();
    schedTimers.set(numericId, schedHandle);

    // Initial check after a short delay
    setTimeout(async () => {
        try { await pollFreeGames(client, numericId); } catch (_) {}
    }, INITIAL_DELAY_MS);
}

function stopFreeGamesService(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) { clearInterval(handle); timers.delete(numericId); }
    const schedHandle = schedTimers.get(numericId);
    if (schedHandle) { clearInterval(schedHandle); schedTimers.delete(numericId); }
    schedLastFired.delete(numericId);
}

module.exports = { startFreeGamesService, stopFreeGamesService, fetchEpicFreeGames, fetchSteamFreeGames };
