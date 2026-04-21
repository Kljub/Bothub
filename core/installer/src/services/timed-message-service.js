// PFAD: /core/installer/src/services/timed-message-service.js

const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

// Per-bot interval handle
const timers = new Map(); // botId → NodeJS.Timeout

async function loadDueMessages(botId) {
    try {
        const rows = await dbQuery(
            `SELECT * FROM bot_timed_messages
             WHERE bot_id = ? AND is_active = 1
               AND next_send_at IS NOT NULL
               AND next_send_at <= UTC_TIMESTAMP()`,
            [Number(botId)]
        );
        return Array.isArray(rows) ? rows : [];
    } catch (_) {
        return [];
    }
}

async function isHandlerEnabled(botId) {
    try {
        const rows = await dbQuery(
            'SELECT evt_handler FROM bot_timed_message_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return true; // default on
        return Number(rows[0].evt_handler) === 1;
    } catch (_) {
        return true;
    }
}

async function processTimedMessages(client, botId) {
    if (!await isHandlerEnabled(botId)) return;

    const messages = await loadDueMessages(botId);

    for (const tm of messages) {
        await sendTimedMessage(client, botId, tm);
    }
}

async function sendTimedMessage(client, botId, tm) {
    const channelId = String(tm.channel_id || '');
    if (!channelId) return;

    let channel = null;
    try {
        channel = await client.channels.fetch(channelId);
    } catch (_) {
        console.warn(`[TimedMsg] Bot ${botId}: channel ${channelId} not found`);
        return;
    }

    if (!channel || !channel.isTextBased()) return;

    // Block stacked: skip if last message in channel was from this bot
    if (Number(tm.block_stacked) === 1) {
        try {
            const msgs = await channel.messages.fetch({ limit: 1 });
            const last = msgs.first();
            if (last && client.user && last.author.id === client.user.id) {
                // Reschedule to next interval without sending
                await reschedule(tm);
                return;
            }
        } catch (_) {}
    }

    // Build and send message
    try {
        let lastMsgId = '';

        if (Number(tm.is_embed) === 1) {
            const embed = new EmbedBuilder();
            const color = String(tm.embed_color || '#ef4444').trim();
            embed.setColor(/^#[0-9a-fA-F]{6}$/.test(color) ? color : '#ef4444');

            if (tm.embed_author) embed.setAuthor({ name: String(tm.embed_author) });
            if (tm.embed_title)  embed.setTitle(String(tm.embed_title));
            if (tm.embed_body)   embed.setDescription(String(tm.embed_body));
            if (tm.embed_thumbnail) embed.setThumbnail(String(tm.embed_thumbnail));
            if (tm.embed_image)  embed.setImage(String(tm.embed_image));
            if (tm.embed_url)    embed.setURL(String(tm.embed_url));
            embed.setTimestamp();

            const sent = await channel.send({ embeds: [embed] });
            lastMsgId = sent.id;
        } else {
            const text = String(tm.plain_text || '').trim();
            if (!text) {
                await reschedule(tm);
                return;
            }
            const sent = await channel.send(text);
            lastMsgId = sent.id;
        }

        await reschedule(tm, lastMsgId);

    } catch (e) {
        console.error(`[TimedMsg] Bot ${botId}: send failed for "${tm.name}":`, e?.message);
        await reschedule(tm);
    }
}

async function reschedule(tm, lastMsgId = null) {
    const totalSec = (Number(tm.interval_days || 0) * 86400)
                   + (Number(tm.interval_hours || 0) * 3600)
                   + (Number(tm.interval_minutes || 0) * 60);

    const safeInterval = Math.max(300, totalSec);
    const nextSend = new Date(Date.now() + safeInterval * 1000);
    const nextSendStr = nextSend.toISOString().slice(0, 19).replace('T', ' ');

    try {
        if (lastMsgId !== null) {
            await dbQuery(
                `UPDATE bot_timed_messages
                 SET last_sent_at = NOW(), next_send_at = ?, last_message_id = ?
                 WHERE id = ?`,
                [nextSendStr, lastMsgId, Number(tm.id)]
            );
        } else {
            await dbQuery(
                `UPDATE bot_timed_messages
                 SET next_send_at = ?
                 WHERE id = ?`,
                [nextSendStr, Number(tm.id)]
            );
        }
    } catch (_) {}
}

function startTimedMessageService(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return; // already running

    // Check every 30 seconds
    const CHECK_INTERVAL_MS = 30_000;

    const handle = setInterval(async () => {
        try {
            await processTimedMessages(client, botId);
        } catch (e) {
            console.error(`[TimedMsg] Bot ${botId}: tick error:`, e?.message);
        }
    }, CHECK_INTERVAL_MS);

    // Unref so the interval doesn't keep the process alive if everything else is done
    if (handle.unref) handle.unref();

    timers.set(numericId, handle);

    // Run once immediately after 5s to catch any overdue messages on startup
    setTimeout(async () => {
        try {
            await processTimedMessages(client, numericId);
        } catch (_) {}
    }, 5000);
}

function stopTimedMessageService(botId) {
    const handle = timers.get(Number(botId));
    if (handle) {
        clearInterval(handle);
        timers.delete(Number(botId));
    }
}

module.exports = { startTimedMessageService, stopTimedMessageService };
