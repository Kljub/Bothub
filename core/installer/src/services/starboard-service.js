'use strict';

const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

// settings cache: `${botId}:${guildId}` → row | null
const settingsCache = new Map();
const cacheTime     = new Map();
const CACHE_TTL     = 60_000;

// ── Table init ────────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_starboard_settings\` (
        \`bot_id\`          BIGINT UNSIGNED  NOT NULL,
        \`guild_id\`        VARCHAR(20)      NOT NULL,
        \`channel_id\`      VARCHAR(20)      NOT NULL DEFAULT '',
        \`emoji\`           VARCHAR(100)     NOT NULL DEFAULT '⭐',
        \`threshold\`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
        \`allow_self_star\` TINYINT(1)       NOT NULL DEFAULT 0,
        \`ignore_bots\`     TINYINT(1)       NOT NULL DEFAULT 1,
        \`is_enabled\`      TINYINT(1)       NOT NULL DEFAULT 1,
        \`created_at\`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        \`updated_at\`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_starboard_entries\` (
        \`id\`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        \`bot_id\`                BIGINT UNSIGNED NOT NULL,
        \`guild_id\`              VARCHAR(20)     NOT NULL,
        \`original_message_id\`   VARCHAR(20)     NOT NULL,
        \`starboard_message_id\`  VARCHAR(20)     NOT NULL,
        \`star_count\`            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        \`created_at\`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY \`uq_original\` (\`bot_id\`, \`guild_id\`, \`original_message_id\`),
        INDEX \`idx_bot_guild\` (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    // Migration: add guild_id if table was created without it
    const cols = await dbQuery("SHOW COLUMNS FROM `bot_starboard_settings` LIKE 'guild_id'");
    if (!Array.isArray(cols) || cols.length === 0) {
        await dbQuery("ALTER TABLE `bot_starboard_settings` ADD COLUMN `guild_id` VARCHAR(20) NOT NULL DEFAULT '' AFTER `bot_id`");
        try { await dbQuery("ALTER TABLE `bot_starboard_settings` DROP PRIMARY KEY"); } catch (_) {}
        await dbQuery("ALTER TABLE `bot_starboard_settings` ADD PRIMARY KEY (`bot_id`, `guild_id`)");
    }
}

// ── Settings ──────────────────────────────────────────────────────────────────
async function loadSettings(botId, guildId) {
    const key = `${botId}:${guildId}`;
    const now = Date.now();
    if (settingsCache.has(key) && now - (cacheTime.get(key) ?? 0) < CACHE_TTL) {
        return settingsCache.get(key);
    }
    const rows = await dbQuery(
        'SELECT * FROM bot_starboard_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
        [Number(botId), String(guildId)]
    );
    const s = Array.isArray(rows) && rows.length ? rows[0] : null;
    settingsCache.set(key, s);
    cacheTime.set(key, now);
    return s;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function emojiMatches(reactionEmoji, configEmoji) {
    const cfg = (configEmoji ?? '⭐').trim();
    if (reactionEmoji.id) {
        return cfg.includes(reactionEmoji.id) || reactionEmoji.name === cfg;
    }
    return reactionEmoji.name === cfg;
}

function buildEmbed(message, starCount, emoji) {
    const author = message.author;
    const embed  = new EmbedBuilder()
        .setColor(0xFFAC33)
        .setAuthor({
            name:    author?.globalName ?? author?.username ?? 'Unbekannt',
            iconURL: author?.displayAvatarURL() ?? undefined,
        })
        .setDescription(message.content || null)
        .addFields({ name: '​', value: `[Zur Nachricht springen](${message.url})`, inline: false })
        .setFooter({ text: `${emoji} ${starCount} · #${message.channel.name}` })
        .setTimestamp(message.createdAt);

    const img = message.attachments.find(a => a.contentType?.startsWith('image/'));
    if (img) embed.setImage(img.url);
    else if (message.embeds[0]?.image?.url) embed.setImage(message.embeds[0].image.url);

    return embed;
}

// ── Core logic ────────────────────────────────────────────────────────────────
async function handleReactionAdd(reaction, user, botId) {
    if (user.bot) return;

    if (reaction.partial) {
        try { await reaction.fetch(); } catch (_) { return; }
    }
    if (reaction.message.partial) {
        try { await reaction.message.fetch(); } catch (_) { return; }
    }

    const message = reaction.message;
    const guild   = message.guild;
    if (!guild) return;

    const settings = await loadSettings(botId, guild.id).catch(() => null);
    if (!settings?.channel_id) return;
    if (!emojiMatches(reaction.emoji, settings.emoji)) return;
    if (!settings.allow_self_star && user.id === message.author?.id) return;
    if (settings.ignore_bots && message.author?.bot) return;
    if (message.channelId === settings.channel_id) return; // don't star the starboard itself

    const starCount = reaction.count ?? 1;
    const threshold = settings.threshold ?? 3;
    const emoji     = settings.emoji ?? '⭐';

    const existing = await dbQuery(
        'SELECT * FROM bot_starboard_entries WHERE bot_id = ? AND guild_id = ? AND original_message_id = ? LIMIT 1',
        [Number(botId), guild.id, message.id]
    );

    const starboardChannel = await guild.channels.fetch(settings.channel_id).catch(() => null);
    if (!starboardChannel?.isTextBased()) return;

    const embed = buildEmbed(message, starCount, emoji);
    const content = `${emoji} **${starCount}** <#${message.channelId}>`;

    if (Array.isArray(existing) && existing.length > 0) {
        const entry = existing[0];
        await dbQuery('UPDATE bot_starboard_entries SET star_count = ? WHERE id = ?', [starCount, entry.id]);
        const sbMsg = await starboardChannel.messages.fetch(entry.starboard_message_id).catch(() => null);
        if (sbMsg) await sbMsg.edit({ content, embeds: [embed] }).catch(() => {});
    } else if (starCount >= threshold) {
        const sbMsg = await starboardChannel.send({ content, embeds: [embed] }).catch(() => null);
        if (!sbMsg) return;
        await dbQuery(
            'INSERT IGNORE INTO bot_starboard_entries (bot_id, guild_id, original_message_id, starboard_message_id, star_count) VALUES (?, ?, ?, ?, ?)',
            [Number(botId), guild.id, message.id, sbMsg.id, starCount]
        );
    }
}

async function handleReactionRemove(reaction, user, botId) {
    if (user.bot) return;

    if (reaction.partial) {
        try { await reaction.fetch(); } catch (_) { return; }
    }
    if (reaction.message.partial) {
        try { await reaction.message.fetch(); } catch (_) { return; }
    }

    const message = reaction.message;
    const guild   = message.guild;
    if (!guild) return;

    const settings = await loadSettings(botId, guild.id).catch(() => null);
    if (!settings?.channel_id) return;
    if (!emojiMatches(reaction.emoji, settings.emoji)) return;
    if (message.channelId === settings.channel_id) return;

    const existing = await dbQuery(
        'SELECT * FROM bot_starboard_entries WHERE bot_id = ? AND guild_id = ? AND original_message_id = ? LIMIT 1',
        [Number(botId), guild.id, message.id]
    );
    if (!Array.isArray(existing) || !existing.length) return;

    const entry     = existing[0];
    const starCount = reaction.count ?? 0;
    const emoji     = settings.emoji ?? '⭐';

    await dbQuery('UPDATE bot_starboard_entries SET star_count = ? WHERE id = ?', [starCount, entry.id]);

    const starboardChannel = await guild.channels.fetch(settings.channel_id).catch(() => null);
    if (!starboardChannel?.isTextBased()) return;

    const sbMsg = await starboardChannel.messages.fetch(entry.starboard_message_id).catch(() => null);
    if (!sbMsg) return;

    const embed = buildEmbed(message, starCount, emoji);
    await sbMsg.edit({ content: `${emoji} **${starCount}** <#${message.channelId}>`, embeds: [embed] }).catch(() => {});
}

// ── Attach events ─────────────────────────────────────────────────────────────
function attachStarboardEvents(client, botId) {
    ensureTables().catch(err => console.warn(`[Starboard] Bot ${botId}: table init:`, err.message));
    client.on('messageReactionAdd', async (reaction, user) => {
        try { await handleReactionAdd(reaction, user, botId); } catch (_) {}
    });
    client.on('messageReactionRemove', async (reaction, user) => {
        try { await handleReactionRemove(reaction, user, botId); } catch (_) {}
    });
}

module.exports = { ensureTables, attachStarboardEvents };
