// PFAD: /core/installer/src/services/kick-service.js
//
// Kick uses a public REST API — no credentials required.
// Endpoint: https://kick.com/api/v2/channels/{slug}
// The response contains a "livestream" key (null when offline).

const https = require('https');
const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

// Per-bot interval handles  Map<botId, Timeout>
const timers = new Map();

// ── HTTP helper ───────────────────────────────────────────────────────────────

function httpsGet(url) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(url);
        const options = {
            hostname: urlObj.hostname,
            path:     urlObj.pathname + urlObj.search,
            method:   'GET',
            headers: {
                'Accept':     'application/json',
                'User-Agent': 'BotHub/1.0 (+https://github.com)',
            },
        };
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data',  c => { data += c; });
            res.on('end', () => {
                try { resolve({ status: res.statusCode, body: JSON.parse(data) }); }
                catch (_) { resolve({ status: res.statusCode, body: data }); }
            });
        });
        req.on('error', reject);
        req.end();
    });
}

// ── Kick API call ─────────────────────────────────────────────────────────────

/**
 * Returns stream info or null if offline/error.
 * @returns {{ title: string, category: string, viewers: number } | null}
 */
async function getKickStreamInfo(slug) {
    const url = `https://kick.com/api/v2/channels/${encodeURIComponent(slug)}`;
    const res = await httpsGet(url);

    if (res.status !== 200 || !res.body || typeof res.body !== 'object') return null;

    const livestream = res.body.livestream;
    if (!livestream || !livestream.is_live) return null;

    return {
        title:    String(livestream.session_title || ''),
        category: String(livestream.categories?.[0]?.name || res.body.category?.name || ''),
        viewers:  Number(livestream.viewer_count   || 0),
        thumbnail: typeof livestream.thumbnail === 'string' ? livestream.thumbnail
                 : typeof livestream.thumbnail?.url === 'string' ? livestream.thumbnail.url : null,
    };
}

// ── Send Discord notification ─────────────────────────────────────────────────

async function sendNotification(client, botId, row, streamInfo) {
    const channelId = String(row.channel_id || '');
    if (!channelId) return;

    let channel = null;
    try {
        channel = await client.channels.fetch(channelId);
    } catch (_) {
        console.warn(`[KickSvc] Bot ${botId}: channel ${channelId} not found`);
        return;
    }
    if (!channel || !channel.isTextBased()) return;

    const slug      = String(row.streamer_slug || '');
    const kickUrl   = `https://kick.com/${slug}`;
    const pingRole  = String(row.ping_role_id || '').trim();

    // Build custom message with variable substitution
    let text = String(row.custom_message || '').trim();
    if (text) {
        text = text
            .replaceAll('{streamer}',  slug)
            .replaceAll('{title}',     streamInfo.title    || '')
            .replaceAll('{category}',  streamInfo.category || '')
            .replaceAll('{url}',       kickUrl);
    }

    const embed = new EmbedBuilder()
        .setColor(0x53fc18)
        .setTitle(`🟢 ${slug} ist jetzt live auf Kick!`)
        .setURL(kickUrl)
        .setFooter({ text: 'Kick Notification' })
        .setTimestamp();

    if (streamInfo.title)    embed.addFields({ name: 'Titel',     value: streamInfo.title,    inline: true });
    if (streamInfo.category) embed.addFields({ name: 'Kategorie', value: streamInfo.category, inline: true });
    if (streamInfo.thumbnail) embed.setThumbnail(streamInfo.thumbnail);

    const payload = { embeds: [embed] };

    if (text) payload.content = text;
    else if (pingRole) payload.content = `<@&${pingRole}>`;

    try {
        await channel.send(payload);
    } catch (e) {
        console.warn(`[KickSvc] Bot ${botId}: send failed for ${slug}:`, e?.message);
        return;
    }

    try {
        await dbQuery(
            'UPDATE kick_notifications SET is_live = 1, last_notified_at = NOW() WHERE id = ?',
            [Number(row.id)]
        );
    } catch (_) {}
}

// ── Poll tick ─────────────────────────────────────────────────────────────────

async function pollKickNotifications(client, botId) {
    let rows;
    try {
        rows = await dbQuery(
            'SELECT * FROM kick_notifications WHERE bot_id = ? AND is_enabled = 1',
            [Number(botId)]
        );
    } catch (e) {
        console.warn(`[KickSvc] Bot ${botId}: DB query failed:`, e?.message);
        return;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;

    // Deduplicate slugs to avoid multiple API calls for the same channel
    const checkedSlugs = new Map(); // slug → streamInfo | null

    for (const row of rows) {
        const slug = String(row.streamer_slug || '').toLowerCase();
        if (!slug) continue;

        let streamInfo;
        if (checkedSlugs.has(slug)) {
            streamInfo = checkedSlugs.get(slug);
        } else {
            try {
                streamInfo = await getKickStreamInfo(slug);
                checkedSlugs.set(slug, streamInfo);
            } catch (e) {
                console.warn(`[KickSvc] Bot ${botId}: API check failed for ${slug}:`, e?.message);
                continue;
            }
        }

        const wasLive = Number(row.is_live) === 1;
        const isLive  = streamInfo !== null;

        if (isLive && !wasLive) {
            await sendNotification(client, botId, row, streamInfo);
        } else if (!isLive && wasLive) {
            try {
                await dbQuery(
                    'UPDATE kick_notifications SET is_live = 0 WHERE id = ?',
                    [Number(row.id)]
                );
            } catch (_) {}
        }
    }
}

// ── Public API ────────────────────────────────────────────────────────────────

const CHECK_INTERVAL_MS = 2 * 60 * 1000; // 2 minutes

function startKickService(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return;

    const handle = setInterval(async () => {
        try {
            await pollKickNotifications(client, numericId);
        } catch (e) {
            console.warn(`[KickSvc] Bot ${numericId}: tick error:`, e?.message);
        }
    }, CHECK_INTERVAL_MS);

    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Initial check 15s after bot start to avoid hammering at startup
    setTimeout(async () => {
        try { await pollKickNotifications(client, numericId); } catch (_) {}
    }, 15_000);
}

function stopKickService(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) {
        clearInterval(handle);
        timers.delete(numericId);
    }
}

module.exports = { startKickService, stopKickService };
