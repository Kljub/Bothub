'use strict';

const { EmbedBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const { dbQuery } = require('../db');

const settingsCache = new Map();
const cacheTime     = new Map();
const CACHE_TTL     = 60_000;

async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_suggestion_settings\` (
        \`bot_id\`       BIGINT UNSIGNED NOT NULL,
        \`guild_id\`     VARCHAR(20)     NOT NULL,
        \`channel_id\`   VARCHAR(20)     NOT NULL DEFAULT '',
        \`button_style\` VARCHAR(10)     NOT NULL DEFAULT 'arrows',
        \`created_at\`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        \`updated_at\`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_suggestion_votes\` (
        \`id\`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        \`bot_id\`          BIGINT UNSIGNED NOT NULL,
        \`guild_id\`        VARCHAR(20)     NOT NULL,
        \`message_id\`      VARCHAR(20)     NOT NULL,
        \`user_id\`         VARCHAR(20)     NOT NULL,
        \`vote\`            TINYINT(1)      NOT NULL,
        \`created_at\`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY \`uq_vote\` (\`bot_id\`, \`guild_id\`, \`message_id\`, \`user_id\`),
        INDEX \`idx_msg\` (\`bot_id\`, \`guild_id\`, \`message_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

async function loadSettings(botId, guildId) {
    const key = `${botId}:${guildId}`;
    const now = Date.now();
    if (settingsCache.has(key) && now - (cacheTime.get(key) ?? 0) < CACHE_TTL) {
        return settingsCache.get(key);
    }
    const rows = await dbQuery(
        'SELECT * FROM bot_suggestion_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
        [Number(botId), String(guildId)]
    );
    const s = Array.isArray(rows) && rows.length ? rows[0] : null;
    settingsCache.set(key, s);
    cacheTime.set(key, now);
    return s;
}

function invalidateCache(botId, guildId) {
    settingsCache.delete(`${botId}:${guildId}`);
    cacheTime.delete(`${botId}:${guildId}`);
}

function buildVoteRow(style, upCount, downCount) {
    const isArrows = style !== 'thumbs';
    const upEmoji   = isArrows ? '⬆️' : '👍';
    const downEmoji = isArrows ? '⬇️' : '👎';
    return new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId('sug_up')
            .setLabel(`${upEmoji} ${upCount}`)
            .setStyle(ButtonStyle.Secondary),
        new ButtonBuilder()
            .setCustomId('sug_down')
            .setLabel(`${downEmoji} ${downCount}`)
            .setStyle(ButtonStyle.Secondary),
    );
}

async function handleSuggestionButton(interaction, botId) {
    const id      = interaction.customId;
    const isUp    = id === 'sug_up';
    const isDown  = id === 'sug_down';
    if (!isUp && !isDown) return;

    const guild  = interaction.guild;
    const userId = interaction.user.id;
    const msgId  = interaction.message.id;

    if (!guild) return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });

    const settings = await loadSettings(botId, guild.id).catch(() => null);
    const style    = settings?.button_style ?? 'arrows';
    const vote     = isUp ? 1 : 0;

    const existing = await dbQuery(
        'SELECT id, vote FROM bot_suggestion_votes WHERE bot_id = ? AND guild_id = ? AND message_id = ? AND user_id = ?',
        [Number(botId), guild.id, msgId, userId]
    );

    if (Array.isArray(existing) && existing.length) {
        const row = existing[0];
        if (row.vote === vote) {
            await dbQuery('DELETE FROM bot_suggestion_votes WHERE id = ?', [row.id]);
        } else {
            await dbQuery('UPDATE bot_suggestion_votes SET vote = ? WHERE id = ?', [vote, row.id]);
        }
    } else {
        await dbQuery(
            'INSERT INTO bot_suggestion_votes (bot_id, guild_id, message_id, user_id, vote) VALUES (?, ?, ?, ?, ?)',
            [Number(botId), guild.id, msgId, userId, vote]
        );
    }

    const counts = await dbQuery(
        'SELECT vote, COUNT(*) AS cnt FROM bot_suggestion_votes WHERE bot_id = ? AND guild_id = ? AND message_id = ? GROUP BY vote',
        [Number(botId), guild.id, msgId]
    );
    let upCount = 0, downCount = 0;
    if (Array.isArray(counts)) {
        for (const r of counts) {
            if (r.vote === 1) upCount = Number(r.cnt);
            else downCount = Number(r.cnt);
        }
    }

    const row = buildVoteRow(style, upCount, downCount);
    await interaction.update({ components: [row] });
}

function attachSuggestionButton(client, botId) {
    ensureTables().catch(err => console.warn(`[Suggestion] Bot ${botId}: table init:`, err.message));
}

module.exports = { ensureTables, attachSuggestionButton, loadSettings, invalidateCache, buildVoteRow, handleSuggestionButton };
