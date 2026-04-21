// PFAD: /core/installer/src/services/reaction-role-service.js

const { dbQuery } = require('../db');

// ── Load all reaction role configs for a bot ──────────────────────────────
async function loadRules(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_reaction_roles WHERE bot_id = ? ORDER BY id ASC',
            [Number(botId)]
        );
        if (!Array.isArray(rows)) return [];

        return rows.map((row) => ({
            id:               Number(row.id),
            messageId:        String(row.message_id || ''),
            emoji:            String(row.emoji || ''),
            rolesToAdd:       parseJsonArray(row.roles_to_add),
            rolesToRemove:    parseJsonArray(row.roles_to_remove),
            blacklistedRoles: parseJsonArray(row.blacklisted_roles),
            restrictOne:      Number(row.restrict_one  || 0) === 1,
            removeReaction:   Number(row.remove_reaction || 1) === 1,
        }));
    } catch (_) {
        return [];
    }
}

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return Array.isArray(arr) ? arr : [];
    } catch (_) {
        return [];
    }
}

// ── Normalise emoji key for comparison ────────────────────────────────────
// Discord reactions can be <Emoji name>:<id> for custom or just the unicode char.
function emojiKey(reactionEmoji) {
    if (reactionEmoji.id) {
        // Custom emoji: match by name or by name:id
        return String(reactionEmoji.name || '');
    }
    return String(reactionEmoji.name || reactionEmoji.emoji || '');
}

function ruleEmojiKey(stored) {
    // strip potential <:name:id> wrapper the user might type
    const stripped = stored.replace(/^<a?:(\w+):\d+>$/, '$1').replace(/^:(\w+):$/, '$1');
    return stripped;
}

function emojiMatches(reactionEmoji, stored) {
    const a = emojiKey(reactionEmoji).toLowerCase();
    const b = ruleEmojiKey(stored).toLowerCase();
    if (a === b) return true;
    // fallback: stored is the raw emoji char
    if (String(stored).trim() === String(reactionEmoji.name || '').trim()) return true;
    return false;
}

// ── Handle messageReactionAdd ─────────────────────────────────────────────
async function handleReactionAdd(reaction, user, botId) {
    if (user.bot) return;

    // Fetch partial structures
    if (reaction.partial) {
        try { await reaction.fetch(); } catch (_) { return; }
    }
    if (reaction.message.partial) {
        try { await reaction.message.fetch(); } catch (_) { return; }
    }

    const messageId = String(reaction.message.id);
    const guild     = reaction.message.guild;
    if (!guild) return;

    const rules = await loadRules(botId);
    const matching = rules.filter((r) => r.messageId === messageId && emojiMatches(reaction.emoji, r.emoji));
    if (matching.length === 0) return;

    // Fetch member
    const member = await guild.members.fetch(user.id).catch(() => null);
    if (!member) return;

    for (const rule of matching) {
        // Blacklist check
        const memberRoleIds = [...member.roles.cache.keys()];
        const isBlacklisted = rule.blacklistedRoles.some((r) => memberRoleIds.includes(r.id));
        if (isBlacklisted) continue;

        // Restrict to one: remove reaction and skip if user already has one of the add roles
        if (rule.restrictOne) {
            const alreadyHas = rule.rolesToAdd.some((r) => memberRoleIds.includes(r.id));
            if (alreadyHas) {
                if (rule.removeReaction) {
                    reaction.users.remove(user.id).catch(() => {});
                }
                continue;
            }
        }

        // Add roles
        for (const r of rule.rolesToAdd) {
            await member.roles.add(r.id).catch(() => {});
        }

        // Remove roles
        for (const r of rule.rolesToRemove) {
            await member.roles.remove(r.id).catch(() => {});
        }

        // Remove reaction from message
        if (rule.removeReaction) {
            reaction.users.remove(user.id).catch(() => {});
        }
    }
}

// ── Handle messageReactionRemove ──────────────────────────────────────────
async function handleReactionRemove(reaction, user, botId) {
    if (user.bot) return;

    if (reaction.partial) {
        try { await reaction.fetch(); } catch (_) { return; }
    }
    if (reaction.message.partial) {
        try { await reaction.message.fetch(); } catch (_) { return; }
    }

    const messageId = String(reaction.message.id);
    const guild     = reaction.message.guild;
    if (!guild) return;

    const rules = await loadRules(botId);
    const matching = rules.filter(
        (r) => r.messageId === messageId && emojiMatches(reaction.emoji, r.emoji)
              && !r.removeReaction // only reverse if we didn't auto-remove
    );
    if (matching.length === 0) return;

    const member = await guild.members.fetch(user.id).catch(() => null);
    if (!member) return;

    for (const rule of matching) {
        // Reverse: remove added roles, re-add removed roles
        for (const r of rule.rolesToAdd) {
            await member.roles.remove(r.id).catch(() => {});
        }
        for (const r of rule.rolesToRemove) {
            await member.roles.add(r.id).catch(() => {});
        }
    }
}

// ── Attach event handlers to a client ────────────────────────────────────
function attachReactionRoleEvents(client, botId) {
    client.on('messageReactionAdd', async (reaction, user) => {
        try {
            await handleReactionAdd(reaction, user, botId);
        } catch (err) {
            console.error(
                `[reaction-role-service] Bot ${botId} reactionAdd error:`,
                err instanceof Error ? err.message : String(err)
            );
        }
    });

    client.on('messageReactionRemove', async (reaction, user) => {
        try {
            await handleReactionRemove(reaction, user, botId);
        } catch (err) {
            console.error(
                `[reaction-role-service] Bot ${botId} reactionRemove error:`,
                err instanceof Error ? err.message : String(err)
            );
        }
    });

    console.log(`[reaction-role-service] Bot ${botId}: event handlers attached.`);
}

module.exports = { attachReactionRoleEvents };
