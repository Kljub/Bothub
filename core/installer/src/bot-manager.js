// PFAD: /core/installer/src/bot-manager.js

const { Client, GatewayIntentBits, Partials } = require('discord.js');
const { createHash, createDecipheriv } = require('crypto');
const { appKey, runnerName } = require('./config');
const { dbQuery } = require('./db');
const { syncSlashCommands } = require('./slash-sync');
const { handleInteraction } = require('./interaction-handler');
const { loadCustomCommandRegistry } = require('./services/custom-command-service');
const { attachWelcomerEvents }     = require('./services/welcomer-service');
const { attachReactionRoleEvents } = require('./services/reaction-role-service');
const { attachPollsEvents }        = require('./services/polls-service');
const { startTimedMessageService, stopTimedMessageService } = require('./services/timed-message-service');
const { attachAutoresponderEvents }  = require('./services/autoresponder-service');
const { attachAutoReactEvents }      = require('./services/auto-react-service');
const { attachStickyMessageEvents }  = require('./services/sticky-message-service');
const { startStatisticChannelService, stopStatisticChannelService } = require('./services/statistic-channel-service');
const { attachAutomodEvents } = require('./services/automod-service');
const { attachLevelingEvents } = require('./services/leveling-service');
const { startTwitchService, stopTwitchService } = require('./services/twitch-service');
const { startKickService,    stopKickService    } = require('./services/kick-service');
const { startYoutubeService, stopYoutubeService } = require('./services/youtube-service');
const { startFreeGamesService, stopFreeGamesService } = require('./services/free-games-service');
const { startGiveawayService, stopGiveawayService } = require('./services/giveaway-service');
const { ensureTables: ensureAITables, attachMentionEvents } = require('./services/ai-service');
const { attachCountingEvents } = require('./services/counting-service');
const { attachVerificationEvents, startVerificationTimer, stopVerificationTimer } = require('./services/verification-service');
const { attachInviteTrackerEvents } = require('./services/invite-tracker-service');
const { destroyAllQueues } = require('./services/music-service');
const { initStatus, cleanupStatus } = require('./services/status-service');
const { attachTicketEvents } = require('./services/ticket-service');
const { attachTempVoiceEvents } = require('./services/temp-voice-service');

/**
 * Decrypts a bot token stored as 'enc:<base64(iv16+ciphertext)>'.
 * Mirrors PHP: openssl_encrypt('AES-256-CBC', hash('sha256', APP_KEY, true))
 */
function decryptBotToken(stored) {
    if (typeof stored !== 'string') return '';
    stored = stored.trim();
    if (stored === '') return '';

    // Legacy: plain token (no enc: prefix, no meta)
    if (!stored.startsWith('enc:')) return stored;

    const key = createHash('sha256').update(appKey).digest(); // 32-byte raw key
    const raw = Buffer.from(stored.slice(4), 'base64');

    if (raw.length <= 16) {
        throw new Error('Bot-Token: encrypted value too short');
    }

    const iv         = raw.subarray(0, 16);
    const ciphertext = raw.subarray(16);
    const decipher   = createDecipheriv('aes-256-cbc', key, iv);
    const plain      = Buffer.concat([decipher.update(ciphertext), decipher.final()]);

    return plain.toString('utf8');
}

class BotManager {
    constructor() {
        this.clients = new Map();
        this.botMeta = new Map();
        this.commandRegistry = new Map();
        this.customCommandRegistries = new Map();
        this.lastDesiredStats = {
            totalKnown: 0,
            running: 0,
            desiredRunning: 0,
        };
    }

    async loadBotsFromDb() {
        const rows = await dbQuery(
            `
            SELECT
                id,
                display_name,
                discord_app_id,
                discord_bot_user_id,
                bot_token_encrypted,
                desired_state,
                runtime_status,
                is_active
            FROM bot_instances
            WHERE is_active = 1
              AND assigned_runner_name = ?
            ORDER BY id ASC
            `,
            [runnerName]
        );

        return Array.isArray(rows) ? rows : [];
    }

    /**
     * Claim all unassigned bots when running as the only registered core runner.
     * In a single-runner setup, bots added before the runner registered have
     * assigned_runner_name = NULL and would otherwise never start.
     */
    async claimUnassignedBots() {
        try {
            const countRows = await dbQuery('SELECT COUNT(*) AS cnt FROM core_runners');
            const runnerCount = Number((Array.isArray(countRows) && countRows[0] ? countRows[0].cnt : 0) || 0);

            if (runnerCount <= 1) {
                const result = await dbQuery(
                    `UPDATE bot_instances SET assigned_runner_name = ? WHERE assigned_runner_name IS NULL AND is_active = 1`,
                    [runnerName]
                );
                const affected = result && typeof result.affectedRows === 'number' ? result.affectedRows : 0;
                if (affected > 0) {
                    console.log(`[BotHub Core] Claimed ${affected} unassigned bot(s) for runner "${runnerName}".`);
                }
            } else {
                console.log(`[BotHub Core] ${runnerCount} runners registered — skipping auto-claim of unassigned bots.`);
            }
        } catch (error) {
            console.warn('[BotHub Core] claimUnassignedBots failed:', error instanceof Error ? error.message : String(error));
        }
    }

    async loadBotById(botId) {
        const rows = await dbQuery(
            `
            SELECT
                id,
                display_name,
                discord_app_id,
                discord_bot_user_id,
                bot_token_encrypted,
                desired_state,
                runtime_status,
                is_active
            FROM bot_instances
            WHERE id = ?
            LIMIT 1
            `,
            [Number(botId)]
        );

        if (!Array.isArray(rows) || rows.length === 0) {
            return null;
        }

        return rows[0];
    }

    getClient(botId) {
        return this.clients.get(Number(botId)) || null;
    }

    getStats() {
        return {
            totalKnown: Number(this.lastDesiredStats.totalKnown || 0),
            running: this.clients.size,
            desiredRunning: Number(this.lastDesiredStats.desiredRunning || 0),
        };
    }

    async updateBotRuntimeStatus(botId, runtimeStatus, lastError = null) {
        await dbQuery(
            `
            UPDATE bot_instances
            SET runtime_status = ?, last_error = ?, updated_at = NOW()
            WHERE id = ?
            `,
            [runtimeStatus, lastError, Number(botId)]
        );
    }

    async setBotStarted(botId) {
        await dbQuery(
            `
            UPDATE bot_instances
            SET runtime_status = 'running',
                last_error = NULL,
                last_started_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
            `,
            [Number(botId)]
        );
    }

    async setBotStopped(botId, errorMessage = null) {
        const runtimeStatus = errorMessage ? 'error' : 'stopped';

        // When stopping due to an error also flip desired_state so the dashboard
        // shows "Fehler" instead of staying stuck on "Startet..." indefinitely.
        const desiredState = errorMessage ? 'stopped' : null;

        if (desiredState !== null) {
            await dbQuery(
                `
                UPDATE bot_instances
                SET runtime_status = ?,
                    desired_state  = ?,
                    last_error     = ?,
                    last_stopped_at = NOW(),
                    updated_at     = NOW()
                WHERE id = ?
                `,
                [runtimeStatus, desiredState, errorMessage, Number(botId)]
            );
        } else {
            await dbQuery(
                `
                UPDATE bot_instances
                SET runtime_status = ?,
                    last_error     = ?,
                    last_stopped_at = NOW(),
                    updated_at     = NOW()
                WHERE id = ?
                `,
                [runtimeStatus, errorMessage, Number(botId)]
            );
        }
    }

    async startBot(botRow) {
        const botId = Number(botRow.id || 0);

        if (botId <= 0) {
            throw new Error('Invalid bot id');
        }

        if (this.clients.has(botId)) {
            return this.clients.get(botId);
        }

        let token = '';
        try {
            token = decryptBotToken(botRow.bot_token_encrypted);
        } catch (e) {
            await this.setBotStopped(botId, `Token decrypt failed: ${e.message}`);
            throw new Error(`Bot ${botId}: token decrypt failed: ${e.message}`);
        }

        if (token === '') {
            await this.setBotStopped(botId, 'Bot token missing');
            throw new Error(`Bot ${botId}: token missing`);
        }

        const client = new Client({
            intents: [
                // Standard
                GatewayIntentBits.Guilds,
                GatewayIntentBits.GuildMessages,
                GatewayIntentBits.GuildMessageReactions,
                GatewayIntentBits.GuildVoiceStates,
                GatewayIntentBits.DirectMessages,
                GatewayIntentBits.GuildModeration,
                GatewayIntentBits.GuildInvites,
                // Privileged (enabled in Discord Dev Portal)
                GatewayIntentBits.GuildMembers,
                GatewayIntentBits.GuildPresences,
                GatewayIntentBits.MessageContent,
            ],
            // Required to receive reactions on messages not in the cache (e.g. old messages)
            partials: [Partials.Message, Partials.Channel, Partials.Reaction],
        });

        client.once('ready', async () => {
            const tag = client.user ? client.user.tag : 'unknown';
            console.log(`[BotHub Core] Bot ${botId} ready: ${tag}`);

            await this.setBotStarted(botId);

            // Sync guild list to DB
            try {
                await this.syncBotGuilds(client, botId);
            } catch (error) {
                console.error(
                    `[BotHub Core] Guild sync failed for bot ${botId}:`,
                    error instanceof Error ? error.message : String(error)
                );
            }

            try {
                const customReg = await loadCustomCommandRegistry(botId);
                this.customCommandRegistries.set(botId, customReg);
            } catch (error) {
                console.error(
                    `[BotHub Core] Custom command load failed for bot ${botId}:`,
                    error instanceof Error ? error.message : String(error)
                );
                this.customCommandRegistries.set(botId, new Map());
            }

            try {
                await syncSlashCommands(client, botId, this.commandRegistry, null, this.customCommandRegistries.get(botId));
            } catch (error) {
                console.error(
                    `[BotHub Core] Slash sync failed for bot ${botId}:`,
                    error instanceof Error ? error.message : String(error)
                );
            }

            try {
                await initStatus(client, botId);
            } catch (error) {
                console.error(
                    `[BotHub Core] Status init failed for bot ${botId}:`,
                    error instanceof Error ? error.message : String(error)
                );
            }
        });

        client.on('interactionCreate', async (interaction) => {
            try {
                await handleInteraction(interaction, this, botId);
            } catch (error) {
                console.error(
                    `[BotHub Core] Interaction handler failed for bot ${botId}:`,
                    error instanceof Error ? error.message : String(error)
                );
            }
        });

        client.on('guildCreate', async (guild) => {
            try {
                await this.upsertGuild(botId, guild, client);
            } catch (error) {
                console.error(`[BotHub Core] guildCreate DB error (bot ${botId}):`, error instanceof Error ? error.message : String(error));
            }
        });

        client.on('guildDelete', async (guild) => {
            try {
                await dbQuery('DELETE FROM bot_guilds WHERE bot_id = ? AND guild_id = ?', [botId, guild.id]);
            } catch (error) {
                console.error(`[BotHub Core] guildDelete DB error (bot ${botId}):`, error instanceof Error ? error.message : String(error));
            }
        });

        client.on('guildUpdate', async (oldGuild, newGuild) => {
            try {
                await this.upsertGuild(botId, newGuild, client);
            } catch (error) {
                console.error(`[BotHub Core] guildUpdate DB error (bot ${botId}):`, error instanceof Error ? error.message : String(error));
            }
        });

        attachWelcomerEvents(client, botId);
        attachReactionRoleEvents(client, botId);
        attachPollsEvents(client, botId);
        startTimedMessageService(client, botId);
        attachAutoresponderEvents(client, botId);
        attachAutoReactEvents(client, botId);
        attachStickyMessageEvents(client, botId);
        startStatisticChannelService(client, botId);
        attachAutomodEvents(client, botId);
        attachLevelingEvents(client, botId);
        startTwitchService(client, botId);
        startKickService(client, botId);
        startYoutubeService(client, botId);
        startFreeGamesService(client, botId);
        startGiveawayService(client, botId);
        attachMentionEvents(client, botId);
        attachCountingEvents(client, botId);
        attachVerificationEvents(client, botId);
        startVerificationTimer(client, botId);
        attachInviteTrackerEvents(client, botId);
        attachTicketEvents(client, botId);
        attachTempVoiceEvents(client, botId);

        client.on('error', async (err) => {
            const message = err instanceof Error ? err.message : String(err);
            console.error(`[BotHub Core] Bot ${botId} client error:`, message);
            await this.setBotStopped(botId, message);
        });

        client.on('shardDisconnect', async (event, shardId) => {
            const code = event && typeof event.code !== 'undefined' ? String(event.code) : 'unknown';
            console.warn(`[BotHub Core] Bot ${botId} shard ${String(shardId)} disconnected (code ${code}).`);

            if (this.clients.has(botId)) {
                await this.setBotStopped(botId, `Shard disconnected (${code})`);
            }
        });

        this.clients.set(botId, client);
        this.botMeta.set(botId, {
            id: botId,
            displayName: String(botRow.display_name || `Bot #${botId}`),
            discordAppId: botRow.discord_app_id || null,
            discordBotUserId: botRow.discord_bot_user_id || null,
        });

        try {
            await client.login(token);
            return client;
        } catch (firstError) {
            const firstMsg = firstError instanceof Error ? firstError.message : String(firstError);

            try { await client.destroy(); } catch (_) {}
            this.clients.delete(botId);
            this.botMeta.delete(botId);

            // Invalid token is unrecoverable — retrying will never help.
            // Disable the bot so syncAllBots doesn't loop endlessly.
            const isInvalidToken = /invalid token/i.test(firstMsg);
            if (isInvalidToken) {
                console.error(`[BotHub Core] Bot ${botId}: invalid token — disabling bot (desired_state → stopped).`);
                await dbQuery(
                    `UPDATE bot_instances SET desired_state = 'stopped', runtime_status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?`,
                    [firstMsg, botId]
                );
                throw firstError;
            }

            console.warn(`[BotHub Core] Bot ${botId}: login failed (attempt 1): ${firstMsg} — retrying in 5s`);

            // Wait 5s then retry with a fresh client — gives Discord time to expire the old session
            await new Promise(resolve => setTimeout(resolve, 5000));

            // Recurse once — startBot will create a new client and try again
            // Guard: mark with a retry flag so we don't recurse infinitely
            if (!botRow._loginRetry) {
                console.log(`[BotHub Core] Bot ${botId}: retrying login...`);
                return this.startBot({ ...botRow, _loginRetry: true });
            }

            await this.setBotStopped(botId, firstMsg);
            throw firstError;
        }
    }

    async stopBot(botId, reason = null) {
        const numericBotId = Number(botId);
        const client = this.clients.get(numericBotId);

        if (!client) {
            await this.setBotStopped(numericBotId, reason);
            return;
        }

        try {
            await client.destroy();
        } catch (error) {
            console.warn(
                `[BotHub Core] Bot ${numericBotId} destroy warning:`,
                error instanceof Error ? error.message : String(error)
            );
        }

        this.clients.delete(numericBotId);
        this.botMeta.delete(numericBotId);
        this.customCommandRegistries.delete(numericBotId);
        destroyAllQueues(numericBotId);
        cleanupStatus(numericBotId);
        stopTimedMessageService(numericBotId);
        stopStatisticChannelService(numericBotId);
        stopTwitchService(numericBotId);
        stopKickService(numericBotId);
        stopYoutubeService(numericBotId);
        stopFreeGamesService(numericBotId);
        stopGiveawayService(numericBotId);
        stopVerificationTimer(numericBotId);
        await this.setBotStopped(numericBotId, reason);
    }

    async restartBot(botRow) {
        const botId = Number(botRow.id || 0);
        await this.stopBot(botId, null);
        // Give Discord time to close the previous WebSocket session
        await new Promise(resolve => setTimeout(resolve, 3000));
        await this.startBot(botRow);
    }

    async reloadBotById(botId) {
        const numericBotId = Number(botId);

        if (!Number.isFinite(numericBotId) || numericBotId <= 0) {
            throw new Error('Invalid bot id');
        }

        const botRow = await this.loadBotById(numericBotId);
        if (!botRow) {
            throw new Error(`Bot not found: ${numericBotId}`);
        }

        const desiredState = String(botRow.desired_state || 'stopped');
        const isActive = Number(botRow.is_active || 0) === 1;
        const currentlyRunning = this.clients.has(numericBotId);

        if (!isActive || desiredState !== 'running') {
            if (currentlyRunning) {
                await this.stopBot(numericBotId, null);
            } else {
                await this.setBotStopped(numericBotId, null);
            }

            return {
                bot_id: numericBotId,
                action: 'stopped',
                desired_state: desiredState,
                is_active: isActive,
                running_after: false,
            };
        }

        if (currentlyRunning) {
            await this.restartBot(botRow);

            return {
                bot_id: numericBotId,
                action: 'restarted',
                desired_state: desiredState,
                is_active: isActive,
                running_after: true,
            };
        }

        await this.startBot(botRow);

        return {
            bot_id: numericBotId,
            action: 'started',
            desired_state: desiredState,
            is_active: isActive,
            running_after: true,
        };
    }

    async reloadAllBots() {
        await this.syncAllBots();

        return {
            total_known: this.lastDesiredStats.totalKnown,
            desired_running: this.lastDesiredStats.desiredRunning,
            running_after: this.clients.size,
        };
    }

    async upsertGuild(botId, guild, client) {
        const botUserId = client.user ? client.user.id : null;
        const guildId   = String(guild.id);
        const guildName = String(guild.name || '').slice(0, 150);
        const iconHash  = guild.icon || null;
        const isOwner   = botUserId && guild.ownerId === botUserId ? 1 : 0;

        await dbQuery(
            `INSERT INTO bot_guilds (bot_id, guild_id, guild_name, is_owner, icon_hash)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               guild_name = VALUES(guild_name),
               is_owner   = VALUES(is_owner),
               icon_hash  = VALUES(icon_hash),
               updated_at = NOW()`,
            [botId, guildId, guildName, isOwner, iconHash]
        );
    }

    async syncBotGuilds(client, botId) {
        const guilds = Array.from(client.guilds.cache.values());

        if (guilds.length === 0) {
            await dbQuery('DELETE FROM bot_guilds WHERE bot_id = ?', [botId]);
            return;
        }

        for (const guild of guilds) {
            await this.upsertGuild(botId, guild, client);
        }

        // Remove stale guilds no longer in cache
        const currentIds = guilds.map(g => String(g.id));
        const placeholders = currentIds.map(() => '?').join(', ');
        await dbQuery(
            `DELETE FROM bot_guilds WHERE bot_id = ? AND guild_id NOT IN (${placeholders})`,
            [botId, ...currentIds]
        );

        console.log(`[BotHub Core] Bot ${botId}: synced ${guilds.length} guild(s) to DB.`);
    }

    async syncAllBots() {
        const bots = await this.loadBotsFromDb();
        const wantedRunning = new Set();

        this.lastDesiredStats.totalKnown = bots.length;
        this.lastDesiredStats.desiredRunning = 0;

        for (const bot of bots) {
            const botId = Number(bot.id || 0);
            if (botId <= 0) {
                continue;
            }

            const desiredState = String(bot.desired_state || 'stopped');
            const isActive = Number(bot.is_active || 0) === 1;

            if (!isActive || desiredState !== 'running') {
                if (this.clients.has(botId)) {
                    await this.stopBot(botId, null);
                }
                continue;
            }

            // Don't auto-restart bots in error state — requires manual intervention (fix token, then re-enable)
            const runtimeStatus = String(bot.runtime_status || '');
            if (!this.clients.has(botId) && runtimeStatus === 'error') {
                continue;
            }

            this.lastDesiredStats.desiredRunning += 1;
            wantedRunning.add(botId);

            if (!this.clients.has(botId)) {
                try {
                    await this.startBot(bot);
                    // Small stagger between bot starts to avoid hammering Discord login
                    await new Promise(resolve => setTimeout(resolve, 500));
                } catch (error) {
                    const message = error instanceof Error ? error.message : String(error);
                    console.error(`[BotHub Core] Failed to start bot ${botId}:`, message);
                }
            }
        }

        for (const activeBotId of Array.from(this.clients.keys())) {
            if (!wantedRunning.has(activeBotId)) {
                await this.stopBot(activeBotId, null);
            }
        }

        this.lastDesiredStats.running = this.clients.size;
    }
}

module.exports = {
    BotManager,
};