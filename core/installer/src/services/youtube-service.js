// PFAD: /core/installer/src/services/youtube-service.js
//
// YouTube uses public RSS feed — no API key required.
// Feed URL: https://www.youtube.com/feeds/videos.xml?channel_id={channelId}
// Checks for new videos by comparing the latest video ID against last_video_id in DB.

const https = require('https');
const { dbQuery } = require('../db');
const { EmbedBuilder } = require('discord.js');

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
                'Accept':     'application/xml, text/xml, */*',
                'User-Agent': 'BotHub/1.0 (+https://github.com)',
            },
        };
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data',  c => { data += c; });
            res.on('end', () => resolve({ status: res.statusCode, body: data }));
        });
        req.on('error', reject);
        req.end();
    });
}

// ── YouTube RSS parser ────────────────────────────────────────────────────────

/**
 * Fetches RSS feed and returns the latest video, or null.
 * @returns {{ videoId: string, title: string, url: string, thumbnail: string, channelName: string } | null}
 */
async function getLatestVideo(ytChannelId) {
    const url = `https://www.youtube.com/feeds/videos.xml?channel_id=${encodeURIComponent(ytChannelId)}`;
    const res = await httpsGet(url);

    if (res.status !== 200 || !res.body) return null;

    const xml = res.body;

    // Extract channel name
    const chanMatch = xml.match(/<title>([^<]*)<\/title>/);
    const channelName = chanMatch ? chanMatch[1].trim() : ytChannelId;

    // Find first <entry> block
    const entryMatch = xml.match(/<entry>([\s\S]*?)<\/entry>/);
    if (!entryMatch) return null;

    const entry = entryMatch[1];

    // Video ID from <yt:videoId>
    const vidIdMatch = entry.match(/<yt:videoId>([^<]+)<\/yt:videoId>/);
    if (!vidIdMatch) return null;
    const videoId = vidIdMatch[1].trim();

    // Title
    const titleMatch = entry.match(/<title>([^<]*)<\/title>/);
    const title = titleMatch ? titleMatch[1].trim() : '';

    // Thumbnail from <media:thumbnail url="...">
    const thumbMatch = entry.match(/<media:thumbnail[^>]*url="([^"]+)"/);
    const thumbnail = thumbMatch ? thumbMatch[1] : `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`;

    return {
        videoId,
        title,
        url: `https://www.youtube.com/watch?v=${videoId}`,
        thumbnail,
        channelName,
    };
}

// ── Send Discord notification ─────────────────────────────────────────────────

async function sendNotification(client, botId, row, video) {
    const channelId = String(row.channel_id || '');
    if (!channelId) return;

    let channel = null;
    try {
        channel = await client.channels.fetch(channelId);
    } catch (_) {
        console.warn(`[YTSvc] Bot ${botId}: channel ${channelId} not found`);
        return;
    }
    if (!channel || !channel.isTextBased()) return;

    const ytChannelId   = String(row.yt_channel_id   || '');
    const ytChannelName = String(row.yt_channel_name || video.channelName || ytChannelId);
    const pingRole      = String(row.ping_role_id    || '').trim();

    // Build custom message with variable substitution
    let text = String(row.custom_message || '').trim();
    if (text) {
        text = text
            .replaceAll('{channel}',  ytChannelName)
            .replaceAll('{title}',    video.title || '')
            .replaceAll('{url}',      video.url);
    }

    const embed = new EmbedBuilder()
        .setColor(0xFF0000)
        .setTitle(`🔴 ${ytChannelName} hat ein neues Video hochgeladen!`)
        .setURL(video.url)
        .setFooter({ text: 'YouTube Notification' })
        .setTimestamp();

    if (video.title)     embed.addFields({ name: 'Titel', value: video.title, inline: false });
    if (video.thumbnail) embed.setThumbnail(video.thumbnail);

    const payload = { embeds: [embed] };

    if (text) payload.content = text;
    else if (pingRole) payload.content = `<@&${pingRole}>`;

    try {
        await channel.send(payload);
    } catch (e) {
        console.warn(`[YTSvc] Bot ${botId}: send failed for ${ytChannelId}:`, e?.message);
        return;
    }

    try {
        await dbQuery(
            'UPDATE youtube_notifications SET last_video_id = ?, last_notified_at = NOW() WHERE id = ?',
            [video.videoId, Number(row.id)]
        );
    } catch (_) {}
}

// ── Poll tick ─────────────────────────────────────────────────────────────────

async function pollYoutubeNotifications(client, botId) {
    let rows;
    try {
        rows = await dbQuery(
            'SELECT * FROM youtube_notifications WHERE bot_id = ? AND is_enabled = 1',
            [Number(botId)]
        );
    } catch (e) {
        console.warn(`[YTSvc] Bot ${botId}: DB query failed:`, e?.message);
        return;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;

    // Deduplicate channel IDs to avoid multiple API calls for the same channel
    const checkedChannels = new Map(); // ytChannelId → video | null

    for (const row of rows) {
        const ytChannelId = String(row.yt_channel_id || '').trim();
        if (!ytChannelId) continue;

        let video;
        if (checkedChannels.has(ytChannelId)) {
            video = checkedChannels.get(ytChannelId);
        } else {
            try {
                video = await getLatestVideo(ytChannelId);
                checkedChannels.set(ytChannelId, video);
            } catch (e) {
                console.warn(`[YTSvc] Bot ${botId}: RSS fetch failed for ${ytChannelId}:`, e?.message);
                continue;
            }
        }

        if (!video) continue;

        const lastVideoId = String(row.last_video_id || '').trim();
        const isNew = video.videoId !== lastVideoId;

        if (isNew) {
            // Update channel name if it changed
            if (video.channelName && video.channelName !== String(row.yt_channel_name || '')) {
                try {
                    await dbQuery(
                        'UPDATE youtube_notifications SET yt_channel_name = ? WHERE id = ?',
                        [video.channelName, Number(row.id)]
                    );
                } catch (_) {}
            }

            // Don't notify on first-ever check (no last_video_id), just seed
            if (lastVideoId === '') {
                try {
                    await dbQuery(
                        'UPDATE youtube_notifications SET last_video_id = ? WHERE id = ?',
                        [video.videoId, Number(row.id)]
                    );
                } catch (_) {}
            } else {
                await sendNotification(client, botId, row, video);
            }
        }
    }
}

// ── Public API ────────────────────────────────────────────────────────────────

const CHECK_INTERVAL_MS = 5 * 60 * 1000; // 5 minutes

function startYoutubeService(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return;

    const handle = setInterval(async () => {
        try {
            await pollYoutubeNotifications(client, numericId);
        } catch (e) {
            console.warn(`[YTSvc] Bot ${numericId}: tick error:`, e?.message);
        }
    }, CHECK_INTERVAL_MS);

    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Initial check 30s after bot start
    setTimeout(async () => {
        try { await pollYoutubeNotifications(client, numericId); } catch (_) {}
    }, 30_000);
}

function stopYoutubeService(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) {
        clearInterval(handle);
        timers.delete(numericId);
    }
}

module.exports = { startYoutubeService, stopYoutubeService };
