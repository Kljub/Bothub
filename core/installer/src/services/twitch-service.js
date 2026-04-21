// PFAD: /core/installer/src/services/twitch-service.js

const https = require('https');
const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

// Per-bot interval handles
const timers = new Map(); // botId → NodeJS.Timeout

// Shared token cache keyed by "client_id:client_secret"
const tokenCache = new Map(); // cacheKey → { access_token, expires_at }

// ── HTTP helpers ──────────────────────────────────────────────────────────────

function httpsPost(url, params) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(url);
        const body = new URLSearchParams(params).toString();
        const options = {
            hostname: urlObj.hostname,
            path: urlObj.pathname + urlObj.search,
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(body),
            },
        };
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    resolve({ status: res.statusCode, body: JSON.parse(data) });
                } catch (_) {
                    resolve({ status: res.statusCode, body: data });
                }
            });
        });
        req.on('error', reject);
        req.write(body);
        req.end();
    });
}

function httpsGet(url, headers) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(url);
        const options = {
            hostname: urlObj.hostname,
            path: urlObj.pathname + urlObj.search,
            method: 'GET',
            headers: headers || {},
        };
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    resolve({ status: res.statusCode, body: JSON.parse(data) });
                } catch (_) {
                    resolve({ status: res.statusCode, body: data });
                }
            });
        });
        req.on('error', reject);
        req.end();
    });
}

// ── Twitch credentials & token ────────────────────────────────────────────────

async function loadTwitchConfig() {
    try {
        const rows = await dbQuery(
            `SELECT config_key, config_value FROM twitch_app_config WHERE config_key IN ('client_id', 'client_secret')`,
            []
        );
        if (!Array.isArray(rows) || rows.length < 2) return null;
        const cfg = {};
        for (const row of rows) {
            cfg[row.config_key] = row.config_value;
        }
        if (!cfg.client_id || !cfg.client_secret) return null;
        return cfg;
    } catch (_) {
        return null;
    }
}

async function getAccessToken(clientId, clientSecret) {
    const cacheKey = `${clientId}:${clientSecret}`;
    const cached = tokenCache.get(cacheKey);

    if (cached && cached.expires_at > Date.now() + 60_000) {
        return cached.access_token;
    }

    const result = await httpsPost(
        `https://id.twitch.tv/oauth2/token?client_id=${encodeURIComponent(clientId)}&client_secret=${encodeURIComponent(clientSecret)}&grant_type=client_credentials`,
        {}
    );

    if (result.status !== 200 || !result.body || !result.body.access_token) {
        throw new Error(`Twitch token fetch failed: HTTP ${result.status}`);
    }

    const expiresIn = Number(result.body.expires_in || 3600);
    tokenCache.set(cacheKey, {
        access_token: result.body.access_token,
        expires_at: Date.now() + expiresIn * 1000,
    });

    return result.body.access_token;
}

// ── Stream check ──────────────────────────────────────────────────────────────

async function isStreamLive(clientId, accessToken, streamerLogin) {
    const url = `https://api.twitch.tv/helix/streams?user_login=${encodeURIComponent(streamerLogin)}`;
    const result = await httpsGet(url, {
        'Client-Id': clientId,
        'Authorization': `Bearer ${accessToken}`,
    });

    if (result.status !== 200 || !result.body || !Array.isArray(result.body.data)) {
        throw new Error(`Twitch stream check failed for ${streamerLogin}: HTTP ${result.status}`);
    }

    return result.body.data.length > 0;
}

// ── Notification ──────────────────────────────────────────────────────────────

async function sendNotification(client, botId, row) {
    const channelId = String(row.channel_id || '');
    if (!channelId) return;

    let channel = null;
    try {
        channel = await client.channels.fetch(channelId);
    } catch (_) {
        console.warn(`[TwitchSvc] Bot ${botId}: channel ${channelId} not found`);
        return;
    }

    if (!channel || !channel.isTextBased()) return;

    const login = String(row.streamer_login || '');
    const customMsg = row.custom_message ? String(row.custom_message).trim() : '';

    const embed = new EmbedBuilder()
        .setTitle(`🔴 ${login} ist jetzt live!`)
        .setDescription(customMsg || `${login} streamt gerade auf Twitch!`)
        .setColor('#9147ff')
        .setURL(`https://twitch.tv/${login}`)
        .setFooter({ text: 'Twitch Notification' })
        .setTimestamp();

    try {
        await channel.send({ embeds: [embed] });
    } catch (e) {
        console.warn(`[TwitchSvc] Bot ${botId}: failed to send notification for ${login}:`, e?.message);
        return;
    }

    try {
        await dbQuery(
            `UPDATE twitch_notifications SET is_live = 1, last_notified_at = NOW()
             WHERE id = ?`,
            [Number(row.id)]
        );
    } catch (_) {}
}

// ── Poll tick ─────────────────────────────────────────────────────────────────

async function pollTwitchNotifications(client, botId) {
    const cfg = await loadTwitchConfig();
    if (!cfg) {
        // No credentials configured — skip silently
        return;
    }

    let accessToken;
    try {
        accessToken = await getAccessToken(cfg.client_id, cfg.client_secret);
    } catch (e) {
        console.warn(`[TwitchSvc] Bot ${botId}: could not get Twitch access token:`, e?.message);
        return;
    }

    let rows;
    try {
        rows = await dbQuery(
            `SELECT * FROM twitch_notifications WHERE bot_id = ? AND is_enabled = 1`,
            [Number(botId)]
        );
    } catch (e) {
        console.warn(`[TwitchSvc] Bot ${botId}: DB query failed:`, e?.message);
        return;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;

    // Deduplicate by streamer_login to avoid multiple API calls for the same streamer
    const checkedLogins = new Map(); // login → isLive boolean

    for (const row of rows) {
        const login = String(row.streamer_login || '').toLowerCase();
        if (!login) continue;

        let live;
        if (checkedLogins.has(login)) {
            live = checkedLogins.get(login);
        } else {
            try {
                live = await isStreamLive(cfg.client_id, accessToken, login);
                checkedLogins.set(login, live);
            } catch (e) {
                console.warn(`[TwitchSvc] Bot ${botId}: stream check failed for ${login}:`, e?.message);
                continue;
            }
        }

        const wasLive = Number(row.is_live) === 1;

        if (live && !wasLive) {
            // Stream just went live — notify
            await sendNotification(client, botId, row);
        } else if (!live && wasLive) {
            // Stream went offline — reset state
            try {
                await dbQuery(
                    `UPDATE twitch_notifications SET is_live = 0 WHERE id = ?`,
                    [Number(row.id)]
                );
            } catch (_) {}
        }
    }
}

// ── Public API ────────────────────────────────────────────────────────────────

const CHECK_INTERVAL_MS = 2 * 60 * 1000; // 2 minutes

function startTwitchService(client, botId) {
    const numericId = Number(botId);

    if (timers.has(numericId)) return; // already running

    const handle = setInterval(async () => {
        try {
            await pollTwitchNotifications(client, numericId);
        } catch (e) {
            console.warn(`[TwitchSvc] Bot ${numericId}: tick error:`, e?.message);
        }
    }, CHECK_INTERVAL_MS);

    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Initial check after 10s on startup
    setTimeout(async () => {
        try {
            await pollTwitchNotifications(client, numericId);
        } catch (_) {}
    }, 10_000);
}

function stopTwitchService(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) {
        clearInterval(handle);
        timers.delete(numericId);
    }
}

module.exports = { startTwitchService, stopTwitchService };
