// PFAD: /core/installer/src/services/polls-service.js

const { dbQuery } = require('../db');

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return Array.isArray(arr) ? arr : [];
    } catch (_) { return []; }
}

async function getPollsSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_polls_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) { return null; }
}

async function findActivePoll(botId, messageId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_polls WHERE bot_id = ? AND message_id = ? AND is_active = 1 LIMIT 1',
            [Number(botId), String(messageId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) { return null; }
}

function emojiKey(reaction) {
    // For custom emojis: <:name:id> → name
    // For unicode emojis: the emoji string itself
    const emoji = reaction.emoji;
    if (emoji.id) return emoji.name || emoji.toString();
    return emoji.name || emoji.toString();
}

async function handlePollReaction(reaction, user, botId) {
    if (user.bot) return;

    // Fetch partials
    try {
        if (reaction.partial) await reaction.fetch();
        if (reaction.message.partial) await reaction.message.fetch();
    } catch (_) { return; }

    const messageId = reaction.message.id;
    const poll = await findActivePoll(botId, messageId);
    if (!poll) return;

    const settings = await getPollsSettings(botId);
    if (!settings || Number(settings.enabled) !== 1) return;
    if (Number(settings.evt_polls_handler) !== 1) return;

    // Check blacklisted roles
    const blacklistedRoles = parseJsonArray(settings.blacklisted_roles);
    if (blacklistedRoles.length > 0 && reaction.message.guild) {
        try {
            const member = await reaction.message.guild.members.fetch(user.id);
            const memberRoles = member ? [...member.roles.cache.keys()] : [];
            if (blacklistedRoles.some(r => memberRoles.includes(r))) {
                try { await reaction.users.remove(user.id); } catch (_) {}
                return;
            }
        } catch (_) {}
    }

    const emoji = emojiKey(reaction);
    const pollId = Number(poll.id);
    const userId = String(user.id);

    // Enforce single choice: remove all previous votes by this user
    if (Number(settings.single_choice) === 1) {
        // Get existing votes for this user on this poll
        const existing = await dbQuery(
            'SELECT emoji FROM bot_poll_votes WHERE poll_id = ? AND user_id = ?',
            [pollId, userId]
        );
        if (Array.isArray(existing) && existing.length > 0) {
            for (const row of existing) {
                const prevEmoji = String(row.emoji);
                if (prevEmoji === emoji) return; // already voted for this option
                // Remove the reaction from Discord
                try {
                    const prevReaction = reaction.message.reactions.cache.find(
                        r => emojiKey(r) === prevEmoji
                    );
                    if (prevReaction) await prevReaction.users.remove(user.id);
                } catch (_) {}
            }
            // Remove all previous DB votes
            await dbQuery(
                'DELETE FROM bot_poll_votes WHERE poll_id = ? AND user_id = ?',
                [pollId, userId]
            );
        }
    }

    // Record vote (INSERT IGNORE handles race conditions / duplicates)
    try {
        await dbQuery(
            'INSERT IGNORE INTO bot_poll_votes (poll_id, user_id, emoji) VALUES (?, ?, ?)',
            [pollId, userId, emoji]
        );
    } catch (e) {
        console.error('[Polls] Failed to record vote:', e?.message);
    }
}

async function handlePollReactionRemove(reaction, user, botId) {
    if (user.bot) return;

    try {
        if (reaction.partial) await reaction.fetch();
        if (reaction.message.partial) await reaction.message.fetch();
    } catch (_) { return; }

    const poll = await findActivePoll(botId, reaction.message.id);
    if (!poll) return;

    const emoji = emojiKey(reaction);
    try {
        await dbQuery(
            'DELETE FROM bot_poll_votes WHERE poll_id = ? AND user_id = ? AND emoji = ?',
            [Number(poll.id), String(user.id), emoji]
        );
    } catch (_) {}
}

function attachPollsEvents(client, botId) {
    client.on('messageReactionAdd', async (reaction, user) => {
        try {
            await handlePollReaction(reaction, user, botId);
        } catch (e) {
            console.error(`[Polls] messageReactionAdd error (bot ${botId}):`, e?.message);
        }
    });

    client.on('messageReactionRemove', async (reaction, user) => {
        try {
            await handlePollReactionRemove(reaction, user, botId);
        } catch (e) {
            console.error(`[Polls] messageReactionRemove error (bot ${botId}):`, e?.message);
        }
    });
}

module.exports = { attachPollsEvents };
