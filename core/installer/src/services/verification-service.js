// PFAD: /core/installer/src/services/verification-service.js

const {
    EmbedBuilder,
    ActionRowBuilder,
    ButtonBuilder,
    ButtonStyle,
    ModalBuilder,
    TextInputBuilder,
    TextInputStyle,
} = require('discord.js');
const { dbQuery } = require('../db');

// ── In-memory captcha store ────────────────────────────────────────────────
// key: `${guildId}:${userId}` → { code: string, attempts: number, expiresAt: number }
const captchaStore = new Map();

// ── Timer handles (botId → NodeJS.Timeout) ────────────────────────────────
const timers = new Map();

// ── Code generator ────────────────────────────────────────────────────────
// 6 chars from A-Z + 2-9 (excludes O, I, 0, 1 to avoid confusion)
const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function generateCode() {
    let code = '';
    for (let i = 0; i < 6; i++) {
        code += CODE_CHARS[Math.floor(Math.random() * CODE_CHARS.length)];
    }
    return code;
}

// ── Table init ────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_verification_settings\` (
        \`bot_id\`            BIGINT UNSIGNED NOT NULL,
        \`guild_id\`          VARCHAR(20) NOT NULL,
        \`verification_type\` ENUM('button','captcha') NOT NULL DEFAULT 'captcha',
        \`channel_id\`        VARCHAR(20) NULL DEFAULT NULL,
        \`verified_role_id\`  VARCHAR(20) NULL DEFAULT NULL,
        \`embed_author\`      VARCHAR(256) NULL DEFAULT NULL,
        \`embed_title\`       VARCHAR(256) NULL DEFAULT NULL,
        \`embed_body\`        TEXT NULL DEFAULT NULL,
        \`embed_image\`       VARCHAR(512) NULL DEFAULT NULL,
        \`embed_footer\`      VARCHAR(256) NULL DEFAULT NULL,
        \`embed_color\`       VARCHAR(7) NOT NULL DEFAULT '#5ba9e4',
        \`embed_url\`         VARCHAR(512) NULL DEFAULT NULL,
        \`button_name\`       VARCHAR(80) NOT NULL DEFAULT 'Start Verification',
        \`log_channel_id\`    VARCHAR(20) NULL DEFAULT NULL,
        \`success_message\`   TEXT NULL DEFAULT NULL,
        \`max_attempts\`      INT NOT NULL DEFAULT 3,
        \`time_limit_sec\`    INT NOT NULL DEFAULT 0,
        UNIQUE KEY \`uq_bot_guild\` (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_verification_pending\` (
        \`bot_id\`      BIGINT UNSIGNED NOT NULL,
        \`guild_id\`    VARCHAR(20) NOT NULL,
        \`user_id\`     VARCHAR(20) NOT NULL,
        \`joined_at\`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        \`kick_after\`  DATETIME NULL DEFAULT NULL,
        \`attempts\`    INT NOT NULL DEFAULT 0,
        UNIQUE KEY \`uq_bot_guild_user\` (\`bot_id\`, \`guild_id\`, \`user_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

// ── Settings loader ───────────────────────────────────────────────────────
async function getSettings(botId, guildId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_verification_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
            [Number(botId), String(guildId)]
        );
        return rows[0] || null;
    } catch (_) {
        return null;
    }
}

// ── Add verified role ─────────────────────────────────────────────────────
async function addVerifiedRole(member, settings) {
    if (!settings || !settings.verified_role_id) return;
    try {
        await member.roles.add(String(settings.verified_role_id));
    } catch (err) {
        console.warn(`[Verification] Could not add role ${settings.verified_role_id} to ${member.id}:`, err.message);
    }
}

// ── Send log ──────────────────────────────────────────────────────────────
async function sendLog(client, settings, botId, userId, success, reason) {
    if (!settings || !settings.log_channel_id) return;
    try {
        const channel = await client.channels.fetch(String(settings.log_channel_id)).catch(() => null);
        if (!channel) return;

        const embed = new EmbedBuilder()
            .setTitle(success ? 'Verification erfolgreich' : 'Verification fehlgeschlagen')
            .setColor(success ? '#4caf7d' : '#e05252')
            .setDescription(`<@${userId}> (${userId})`)
            .setTimestamp();

        if (reason) embed.addFields({ name: 'Grund', value: reason });

        await channel.send({ embeds: [embed] });
    } catch (err) {
        console.warn(`[Verification] Bot ${botId}: sendLog error:`, err.message);
    }
}

// ── Build the verification embed ──────────────────────────────────────────
function buildVerificationEmbed(settings) {
    const embed = new EmbedBuilder();

    if (settings.embed_color) {
        try { embed.setColor(settings.embed_color); } catch (_) { embed.setColor('#5ba9e4'); }
    } else {
        embed.setColor('#5ba9e4');
    }

    if (settings.embed_author)  embed.setAuthor({ name: String(settings.embed_author) });
    if (settings.embed_title)   embed.setTitle(String(settings.embed_title));
    if (settings.embed_body)    embed.setDescription(String(settings.embed_body));
    if (settings.embed_image)   embed.setImage(String(settings.embed_image));
    if (settings.embed_footer)  embed.setFooter({ text: String(settings.embed_footer) });
    if (settings.embed_url)     embed.setURL(String(settings.embed_url));

    return embed;
}

// ── Build the verify button row ───────────────────────────────────────────
function buildVerifyButton(settings, guildId) {
    const btn = new ButtonBuilder()
        .setCustomId(`vfy_start_${guildId}`)
        .setLabel(settings.button_name || 'Start Verification')
        .setStyle(ButtonStyle.Primary);

    return new ActionRowBuilder().addComponents(btn);
}

// ── Interaction handler ───────────────────────────────────────────────────
function registerInteractionHandler(client, botId) {
    client.on('interactionCreate', async (interaction) => {
        // Only handle interactions for this bot instance
        if (!interaction.guild) return;
        const guildId = interaction.guildId;

        // ── Button: vfy_start_<guildId> ───────────────────────────────────
        if (interaction.isButton() && interaction.customId === `vfy_start_${guildId}`) {
            try {
                const settings = await getSettings(botId, guildId);
                if (!settings) {
                    return interaction.reply({ content: '❌ Verification ist nicht konfiguriert.', ephemeral: true });
                }

                // Check if user already has the role
                if (settings.verified_role_id) {
                    const member = interaction.member;
                    if (member && member.roles && member.roles.cache.has(String(settings.verified_role_id))) {
                        return interaction.reply({ content: 'ℹ️ Du bist bereits verifiziert.', ephemeral: true });
                    }
                }

                if (settings.verification_type === 'button') {
                    // Direct role assignment
                    const member = interaction.member;
                    await addVerifiedRole(member, settings);

                    // Remove from pending
                    await dbQuery(
                        'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                        [Number(botId), guildId, interaction.user.id]
                    ).catch(() => {});

                    const successMsg = settings.success_message || '✅ Du wurdest erfolgreich verifiziert!';
                    await sendLog(client, settings, botId, interaction.user.id, true, 'Button-Verifikation');
                    return interaction.reply({ content: String(successMsg), ephemeral: true });
                }

                // Captcha flow
                const code = generateCode();
                const storeKey = `${guildId}:${interaction.user.id}`;
                captchaStore.set(storeKey, {
                    code,
                    attempts: 0,
                    expiresAt: Date.now() + 5 * 60 * 1000, // 5 minutes
                });

                const modal = new ModalBuilder()
                    .setCustomId(`vfy_submit_${guildId}`)
                    .setTitle('Verification');

                const textInput = new TextInputBuilder()
                    .setCustomId('vfy_code_input')
                    .setLabel(`Code: ${code}`)
                    .setStyle(TextInputStyle.Short)
                    .setPlaceholder('Gib den Code ein')
                    .setRequired(true)
                    .setMinLength(6)
                    .setMaxLength(10);

                modal.addComponents(new ActionRowBuilder().addComponents(textInput));
                return interaction.showModal(modal);

            } catch (err) {
                console.error(`[Verification] Bot ${botId}: button handler error:`, err.message);
                try {
                    if (!interaction.replied && !interaction.deferred) {
                        await interaction.reply({ content: '❌ Ein Fehler ist aufgetreten.', ephemeral: true });
                    }
                } catch (_) {}
            }
        }

        // ── Modal: vfy_submit_<guildId> ───────────────────────────────────
        if (interaction.isModalSubmit() && interaction.customId === `vfy_submit_${guildId}`) {
            try {
                const settings = await getSettings(botId, guildId);
                if (!settings) {
                    return interaction.reply({ content: '❌ Verification ist nicht konfiguriert.', ephemeral: true });
                }

                const storeKey = `${guildId}:${interaction.user.id}`;
                const entry = captchaStore.get(storeKey);

                if (!entry) {
                    return interaction.reply({ content: '❌ Kein aktiver Verification-Code. Bitte starte die Verifikation erneut.', ephemeral: true });
                }

                // Check expiry
                if (entry.expiresAt < Date.now()) {
                    captchaStore.delete(storeKey);
                    return interaction.reply({ content: '⏱ Dein Code ist abgelaufen. Bitte starte die Verifikation erneut.', ephemeral: true });
                }

                const userInput = (interaction.fields.getTextInputValue('vfy_code_input') || '').trim().toUpperCase();
                const correctCode = entry.code.toUpperCase();

                if (userInput === correctCode) {
                    // Success
                    captchaStore.delete(storeKey);

                    const member = interaction.member;
                    await addVerifiedRole(member, settings);

                    // Remove from pending
                    await dbQuery(
                        'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                        [Number(botId), guildId, interaction.user.id]
                    ).catch(() => {});

                    const successMsg = settings.success_message || '✅ Du wurdest erfolgreich verifiziert!';
                    await sendLog(client, settings, botId, interaction.user.id, true, 'Captcha-Verifikation');
                    return interaction.reply({ content: String(successMsg), ephemeral: true });

                } else {
                    // Wrong code
                    entry.attempts += 1;
                    captchaStore.set(storeKey, entry);

                    const maxAttempts = settings.max_attempts ? Number(settings.max_attempts) : 0;

                    // Update attempts in pending table
                    await dbQuery(
                        'UPDATE bot_verification_pending SET attempts = attempts + 1 WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                        [Number(botId), guildId, interaction.user.id]
                    ).catch(() => {});

                    if (maxAttempts > 0 && entry.attempts >= maxAttempts) {
                        // Auto-kick
                        captchaStore.delete(storeKey);
                        await sendLog(client, settings, botId, interaction.user.id, false, `Zu viele Fehlversuche (${entry.attempts}/${maxAttempts})`);

                        const member = interaction.member;
                        try {
                            await interaction.reply({ content: `❌ Zu viele Fehlversuche. Du wirst vom Server entfernt.`, ephemeral: true });
                        } catch (_) {}

                        setTimeout(async () => {
                            try {
                                await member.kick(`Verification fehlgeschlagen: zu viele Fehlversuche (${entry.attempts}/${maxAttempts})`);
                            } catch (kickErr) {
                                console.warn(`[Verification] Bot ${botId}: kick failed for ${interaction.user.id}:`, kickErr.message);
                            }
                        }, 3000);

                        return;
                    }

                    const remaining = maxAttempts > 0 ? ` (${entry.attempts}/${maxAttempts} Versuche)` : '';
                    return interaction.reply({ content: `❌ Falscher Code.${remaining} Bitte versuche es erneut.`, ephemeral: true });
                }

            } catch (err) {
                console.error(`[Verification] Bot ${botId}: modal handler error:`, err.message);
                try {
                    if (!interaction.replied && !interaction.deferred) {
                        await interaction.reply({ content: '❌ Ein Fehler ist aufgetreten.', ephemeral: true });
                    }
                } catch (_) {}
            }
        }
    });
}

// ── guildMemberAdd handler ────────────────────────────────────────────────
function registerGuildMemberAddHandler(client, botId) {
    client.on('guildMemberAdd', async (member) => {
        try {
            const settings = await getSettings(botId, member.guild.id);
            if (!settings) return;
            if (!settings.time_limit_sec || Number(settings.time_limit_sec) <= 0) return;

            const kickAfter = new Date(Date.now() + Number(settings.time_limit_sec) * 1000);
            const kickAfterStr = kickAfter.toISOString().slice(0, 19).replace('T', ' ');

            await dbQuery(
                `INSERT INTO bot_verification_pending (bot_id, guild_id, user_id, joined_at, kick_after, attempts)
                 VALUES (?, ?, ?, NOW(), ?, 0)
                 ON DUPLICATE KEY UPDATE kick_after = VALUES(kick_after), joined_at = NOW(), attempts = 0`,
                [Number(botId), member.guild.id, member.id, kickAfterStr]
            );
        } catch (err) {
            console.warn(`[Verification] Bot ${botId}: guildMemberAdd error for ${member.id}:`, err.message);
        }
    });
}

// ── Timer: check pending kick-afters ─────────────────────────────────────
async function checkPendingKicks(client, botId) {
    const nowStr = new Date().toISOString().slice(0, 19).replace('T', ' ');
    let overdue;
    try {
        overdue = await dbQuery(
            `SELECT * FROM bot_verification_pending WHERE bot_id = ? AND kick_after IS NOT NULL AND kick_after <= ?`,
            [Number(botId), nowStr]
        );
    } catch (_) {
        return;
    }

    for (const row of overdue) {
        try {
            const settings = await getSettings(botId, row.guild_id);
            if (!settings) {
                // Clean up even without settings
                await dbQuery(
                    'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                    [Number(botId), row.guild_id, row.user_id]
                ).catch(() => {});
                continue;
            }

            const guild = client.guilds.cache.get(String(row.guild_id));
            if (!guild) continue;

            const member = await guild.members.fetch(String(row.user_id)).catch(() => null);
            if (!member) {
                // Member already left
                await dbQuery(
                    'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                    [Number(botId), row.guild_id, row.user_id]
                ).catch(() => {});
                continue;
            }

            // Check if member already has the verified role
            if (settings.verified_role_id && member.roles.cache.has(String(settings.verified_role_id))) {
                await dbQuery(
                    'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
                    [Number(botId), row.guild_id, row.user_id]
                ).catch(() => {});
                continue;
            }

            // Kick for not verifying in time
            await sendLog(client, settings, botId, row.user_id, false, 'Zeit für Verifikation abgelaufen (Auto-Kick)');
            await member.kick('Verification nicht abgeschlossen innerhalb des Zeitlimits').catch(err => {
                console.warn(`[Verification] Bot ${botId}: auto-kick failed for ${row.user_id}:`, err.message);
            });

        } catch (err) {
            console.warn(`[Verification] Bot ${botId}: checkPendingKicks error for user ${row.user_id}:`, err.message);
        }

        // Remove from pending regardless of kick success
        await dbQuery(
            'DELETE FROM bot_verification_pending WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
            [Number(botId), row.guild_id, row.user_id]
        ).catch(() => {});
    }
}

// ── Public API ────────────────────────────────────────────────────────────
function attachVerificationEvents(client, botId) {
    const numericId = Number(botId);

    ensureTables().catch(err => {
        console.warn(`[Verification] Bot ${numericId}: table init failed:`, err.message);
    });

    registerInteractionHandler(client, numericId);
    registerGuildMemberAddHandler(client, numericId);
}

function startVerificationTimer(client, botId) {
    const numericId = Number(botId);
    if (timers.has(numericId)) return;

    const handle = setInterval(() => {
        checkPendingKicks(client, numericId).catch(err => {
            console.error(`[Verification] Bot ${numericId}: timer tick error:`, err.message);
        });
    }, 30_000);

    if (handle.unref) handle.unref();
    timers.set(numericId, handle);

    // Initial check after 15s
    setTimeout(() => {
        checkPendingKicks(client, numericId).catch(() => {});
    }, 15_000);
}

function stopVerificationTimer(botId) {
    const numericId = Number(botId);
    const handle = timers.get(numericId);
    if (handle) {
        clearInterval(handle);
        timers.delete(numericId);
    }
}

module.exports = {
    attachVerificationEvents,
    startVerificationTimer,
    stopVerificationTimer,
    getSettings,
    buildVerificationEmbed,
    buildVerifyButton,
    ensureTables,
};
