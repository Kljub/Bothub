// PFAD: /core/installer/src/services/status-service.js

const { ActivityType } = require('discord.js');
const { dbQuery } = require('../db');

const TYPE_MAP = {
    watching:   ActivityType.Watching,
    playing:    ActivityType.Playing,
    listening:  ActivityType.Listening,
    streaming:  ActivityType.Streaming,
    competing:  ActivityType.Competing,
    custom:     ActivityType.Custom,
};

const VALID_PRESENCE = new Set(['online', 'idle', 'dnd', 'invisible']);

async function loadStatusSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_status_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
    } catch (_) {
        return null;
    }
}

async function loadRotations(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_status_rotations WHERE bot_id = ? ORDER BY sort_order ASC, id ASC',
            [Number(botId)]
        );
        return Array.isArray(rows) ? rows : [];
    } catch (_) {
        return [];
    }
}

/**
 * @param {import('discord.js').Client} client
 * @param {string} type         - activity type key (watching/playing/listening/streaming/competing/custom)
 * @param {string} text         - activity text / stream title / custom state
 * @param {string} [streamUrl]  - Twitch/YouTube URL (streaming only)
 * @param {string} [presenceStatus] - online | idle | dnd | invisible
 */
function applyStatus(client, type, text, streamUrl = '', presenceStatus = 'online') {
    if (!client?.user) return;

    const activityType = TYPE_MAP[String(type || '').toLowerCase()] ?? ActivityType.Playing;
    const statusText   = String(text || '').trim();
    const presence     = VALID_PRESENCE.has(String(presenceStatus)) ? String(presenceStatus) : 'online';

    // Custom activity can have empty text (emoji-only), others require text
    if (!statusText && activityType !== ActivityType.Custom) return;

    /** @type {import('discord.js').ActivitiesOptions} */
    const activity = { type: activityType };

    if (activityType === ActivityType.Custom) {
        // Custom status: Discord shows `state` as the visible text
        activity.name  = 'Custom Status';
        activity.state = statusText;
    } else {
        activity.name = statusText;
    }

    if (activityType === ActivityType.Streaming) {
        const url = String(streamUrl || '').trim();
        if (url) {
            activity.url = url;
        }
    }

    try {
        client.user.setPresence({
            activities: [activity],
            status: presence,
        });
    } catch (e) {
        console.warn(`[status-service] setPresence failed (shard not ready?): ${e.message}`);
        return;
    }

}

const _rotatingTimers = new Map(); // botId → intervalId

async function initStatus(client, botId) {
    const settings = await loadStatusSettings(botId);

    if (_rotatingTimers.has(botId)) {
        clearInterval(_rotatingTimers.get(botId));
        _rotatingTimers.delete(botId);
    }

    if (!settings || settings.mode === 'disabled') return;

    const presenceStatus = String(settings.presence_status || 'online');
    const streamUrl      = String(settings.stream_url || '');

    if (settings.mode === 'fixed' && Number(settings.event_restart) === 1) {
        applyStatus(client, settings.status_type, settings.status_text, streamUrl, presenceStatus);
        return;
    }

    if (settings.mode === 'rotating' && Number(settings.event_rotating) === 1) {
        const rotations = await loadRotations(botId);
        if (rotations.length === 0) return;

        let idx = 0;
        const applyRotation = (entry) => {
            applyStatus(
                client,
                entry.status_type,
                entry.status_text,
                String(entry.stream_url || ''),
                presenceStatus
            );
        };

        applyRotation(rotations[0]);

        const interval = Math.max(10, Number(settings.rotating_interval || 60)) * 1000;
        const timer = setInterval(() => {
            try {
                idx = (idx + 1) % rotations.length;
                applyRotation(rotations[idx]);
            } catch (e) {
                console.warn(`[status-service] Rotation tick failed:`, e.message);
            }
        }, interval);
        _rotatingTimers.set(botId, timer);
        return;
    }

    // 'command' mode: status set via /change-status — apply presence only
    if (settings.mode === 'command' && VALID_PRESENCE.has(presenceStatus)) {
        client.user.setPresence({ status: presenceStatus });
    }
}

function cleanupStatus(botId) {
    if (_rotatingTimers.has(botId)) {
        clearInterval(_rotatingTimers.get(botId));
        _rotatingTimers.delete(botId);
    }
}

module.exports = { loadStatusSettings, loadRotations, applyStatus, initStatus, cleanupStatus };
