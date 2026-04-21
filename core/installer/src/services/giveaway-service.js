const { dbQuery } = require('../db');
const { EmbedBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');

const timers = new Map(); // botId → NodeJS.Timeout

// ── Settings ──────────────────────────────────────────────────────────────────
const DEFAULT_WINNER_MSG    = 'Herzlichen Glückwunsch {winners}! 🎉 Du hast **{prize}** gewonnen!';
const DEFAULT_NO_WINNER_MSG = 'Das Giveaway für **{prize}** ist beendet — leider gab es keine Teilnehmer.';

async function loadSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT winner_message, no_winner_message FROM bot_giveaway_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        return rows[0] || {};
    } catch (_) {
        return {};
    }
}

function applyTemplate(template, vars) {
    return template
        .replace(/\{winners\}/g,      vars.winners      || '')
        .replace(/\{prize\}/g,        vars.prize        || '')
        .replace(/\{winner_count\}/g, String(vars.winner_count ?? ''));
}

// ── Table init ────────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS bot_giveaways (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id BIGINT UNSIGNED NOT NULL,
        guild_id VARCHAR(20) NOT NULL,
        channel_id VARCHAR(20) NOT NULL,
        message_id VARCHAR(20) DEFAULT NULL,
        prize VARCHAR(255) NOT NULL,
        winner_count INT NOT NULL DEFAULT 1,
        ends_at DATETIME NOT NULL,
        host_id VARCHAR(20) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_bot_id (bot_id),
        INDEX idx_ends_at (ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS bot_giveaway_participants (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        giveaway_id BIGINT UNSIGNED NOT NULL,
        user_id VARCHAR(20) NOT NULL,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_giveaway_user (giveaway_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS bot_giveaway_settings (
        bot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        winner_message TEXT DEFAULT NULL,
        no_winner_message TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

// ── Create ────────────────────────────────────────────────────────────────────
async function createGiveaway(client, botId, guildId, channelId, prize, durationMs, winnerCount, hostId) {
    const endsAt    = new Date(Date.now() + durationMs);
    const endsAtStr = endsAt.toISOString().slice(0, 19).replace('T', ' ');

    const embed = new EmbedBuilder()
        .setTitle(`🎁 GIVEAWAY: ${prize}`)
        .setDescription(
            `Klicke auf den Button unten, um teilzunehmen!\n\n` +
            `**Endet:** <t:${Math.floor(endsAt.getTime() / 1000)}:R>\n` +
            `**Host:** <@${hostId}>\n` +
            `**Gewinner:** ${winnerCount}`
        )
        .setColor('#f1c40f')
        .setTimestamp(endsAt);

    const row = new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId('ga_join')
            .setLabel('Teilnehmen')
            .setEmoji('🎉')
            .setStyle(ButtonStyle.Primary)
    );

    const channel = await client.channels.fetch(channelId).catch(() => null);
    if (!channel) throw new Error('Kanal nicht gefunden.');

    const message = await channel.send({ embeds: [embed], components: [row] });

    await dbQuery(
        `INSERT INTO bot_giveaways (bot_id, guild_id, channel_id, message_id, prize, winner_count, ends_at, host_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [botId, guildId, channelId, message.id, prize, winnerCount, endsAtStr, hostId]
    );

    return message;
}

// ── Button handler ────────────────────────────────────────────────────────────
async function handleGiveawayButton(interaction, botId) {
    if (interaction.customId !== 'ga_join') return;

    const messageId = interaction.message.id;
    const userId    = interaction.user.id;

    const rows = await dbQuery(
        `SELECT id, is_active, ends_at FROM bot_giveaways WHERE bot_id = ? AND message_id = ? LIMIT 1`,
        [botId, messageId]
    );
    const giveaway = rows[0];

    if (!giveaway || !giveaway.is_active || new Date(giveaway.ends_at + ' UTC') < new Date()) {
        return interaction.reply({ content: '❌ Dieses Giveaway ist bereits beendet.', ephemeral: true });
    }

    try {
        await dbQuery(
            `INSERT INTO bot_giveaway_participants (giveaway_id, user_id) VALUES (?, ?)`,
            [giveaway.id, userId]
        );
        return interaction.reply({ content: '🎉 Du nimmst am Giveaway teil!', ephemeral: true });
    } catch (_) {
        return interaction.reply({ content: 'ℹ️ Du nimmst bereits an diesem Giveaway teil.', ephemeral: true });
    }
}

// ── Poller ────────────────────────────────────────────────────────────────────
async function checkAndEndGiveaways(client, botId) {
    const nowStr  = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const overdue = await dbQuery(
        `SELECT * FROM bot_giveaways WHERE bot_id = ? AND is_active = 1 AND ends_at <= ?`,
        [botId, nowStr]
    );

    if (!overdue.length) return;

    const settings = await loadSettings(botId);

    for (const ga of overdue) {
        try {
            const participants = await dbQuery(
                `SELECT user_id FROM bot_giveaway_participants WHERE giveaway_id = ?`,
                [ga.id]
            );

            const winners = participants.length > 0
                ? participants
                    .sort(() => 0.5 - Math.random())
                    .slice(0, ga.winner_count)
                    .map(p => `<@${p.user_id}>`)
                : [];

            const channel = await client.channels.fetch(String(ga.channel_id)).catch(() => null);
            if (!channel) {
                console.warn(`[Giveaway] Bot ${botId}: channel ${ga.channel_id} not found for giveaway #${ga.id}`);
            } else {
                // Edit the original giveaway message (best-effort — don't abort if deleted)
                if (ga.message_id) {
                    const message = await channel.messages.fetch(String(ga.message_id)).catch(() => null);
                    if (message) {
                        const baseEmbed = message.embeds[0]
                            ? EmbedBuilder.from(message.embeds[0])
                            : new EmbedBuilder();

                        const endEmbed = baseEmbed
                            .setTitle('🎊 GIVEAWAY BEENDET 🎊')
                            .setDescription(
                                `**Preis:** ${ga.prize}\n` +
                                `**Gewinner:** ${winners.length > 0 ? winners.join(', ') : 'Niemand'}`
                            )
                            .setColor('#2ecc71')
                            .setTimestamp();

                        await message.edit({ embeds: [endEmbed], components: [] }).catch(() => {});
                    }
                }

                // Send winner announcement — independent of whether original message exists
                const templateVars = {
                    winners:      winners.join(', '),
                    prize:        ga.prize,
                    winner_count: ga.winner_count,
                };

                const msgText = winners.length > 0
                    ? applyTemplate(settings.winner_message    || DEFAULT_WINNER_MSG,    templateVars)
                    : applyTemplate(settings.no_winner_message || DEFAULT_NO_WINNER_MSG, templateVars);

                await channel.send(msgText).catch(err => {
                    console.warn(`[Giveaway] Bot ${botId}: failed to send winner msg for #${ga.id}:`, err.message);
                });
            }
        } catch (err) {
            console.error(`[Giveaway] Bot ${botId}: error ending giveaway #${ga.id}:`, err.message);
        }

        await dbQuery(`UPDATE bot_giveaways SET is_active = 0 WHERE id = ?`, [ga.id]).catch(() => {});
    }
}

// ── Public API ────────────────────────────────────────────────────────────────
function startGiveawayService(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return; // already running

    // Ensure tables exist before first tick
    ensureTables().catch(err => {
        console.warn(`[Giveaway] Bot ${numericId}: table init failed:`, err.message);
    });

    const handle = setInterval(() => {
        checkAndEndGiveaways(client, numericId).catch(err => {
            console.error(`[Giveaway] Bot ${numericId}: tick error:`, err.message);
        });
    }, 30_000);

    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Initial check after 10s
    setTimeout(() => {
        checkAndEndGiveaways(client, numericId).catch(() => {});
    }, 10_000);
}

function stopGiveawayService(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) {
        clearInterval(handle);
        timers.delete(numericId);
    }
}

module.exports = { createGiveaway, handleGiveawayButton, startGiveawayService, stopGiveawayService };
