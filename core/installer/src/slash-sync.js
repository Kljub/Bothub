// PFAD: /src/slash-sync.js
const { REST, Routes } = require('discord.js');
const { dbQuery } = require('./db');

async function tableExists(tableName) {
    const rows = await dbQuery(
        `SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?`,
        [tableName]
    );

    return Array.isArray(rows) && rows.length > 0;
}

async function loadEnabledCommandKeys(botId) {
    const keys = [];

    const exists = await tableExists('commands');
    if (!exists) return keys;

    const rows = await dbQuery(
        `SELECT command_key
         FROM commands
         WHERE bot_id = ?
           AND is_enabled = 1`,
        [botId]
    );

    if (Array.isArray(rows)) {
        for (const row of rows) {
            const k = String(row.command_key || '').trim();
            if (k !== '') keys.push(k);
        }
    }

    return keys;
}

// Built-in command registry keys that are always synced, regardless of the apps table.
// Add module keys here for features that should not require App Store installation.
const BUILTIN_COMMAND_KEYS = [
    'level',
    'rank',
    'ticket',
];

// Maps subcommand dashboard keys to their parent command registry key
const SUBCOMMAND_PARENT_MAP = {
    'balance-manage-add':    'balance-manage',
    'balance-manage-remove': 'balance-manage',
    'level_leaderboard':     'level',
    'level_editxp':          'level',
    'level_reset':           'level',
    'giveaway-create':       'giveaway',
    'giveaway-end':          'giveaway',
    'giveaway-list':         'giveaway',
    'counting-set':          'counting',
    'verification-setup':    'verification',
    'verification-add':      'verification',
    'ai':                    'ai',
    'birthday-add':          'birthday',
    'birthday-delete':       'birthday',
    'imagine':               'imagine',
    'img2img':               'img2img',
    'autotag':               'autotag',
};

function buildAllowedCommandMap(commandRegistry, enabledKeys) {
    const allowed = new Map();

    // Merge built-in keys (always enabled) with app-store-enabled keys
    const allKeys = [...new Set([...BUILTIN_COMMAND_KEYS, ...enabledKeys])];

    for (const key of allKeys) {
        const resolvedKey = SUBCOMMAND_PARENT_MAP[key] || key;
        if (!commandRegistry.has(resolvedKey)) {
            continue;
        }

        const command = commandRegistry.get(resolvedKey);
        if (!command || !command.data) {
            continue;
        }

        const slashName = typeof command.data.name === 'string'
            ? command.data.name.trim()
            : '';

        if ($slashNameIsInvalid(slashName)) {
            continue;
        }

        allowed.set(slashName, command);
    }

    return allowed;
}

function $slashNameIsInvalid(name) {
    return name === '';
}

function buildCustomCommandPayloads(customCommandRegistry) {
    if (!(customCommandRegistry instanceof Map)) {
        return [];
    }

    const payloads = [];
    for (const command of customCommandRegistry.values()) {
        if (!command || !command.data) {
            continue;
        }
        try {
            payloads.push(command.data.toJSON());
        } catch (error) {
            console.warn(
                `[slash-sync] Skipping custom command "${command.key}" (toJSON failed):`,
                error instanceof Error ? error.message : String(error)
            );
        }
    }
    return payloads;
}

async function syncSlashCommandsForBot(client, botId, commandRegistry, customCommandRegistry) {
    if (!client || !client.user || !client.application) {
        throw new Error('Client ist nicht bereit für Slash Sync.');
    }

    const enabledKeys = await loadEnabledCommandKeys(botId);
    const allowedCommands = buildAllowedCommandMap(commandRegistry, enabledKeys);

    const staticPayload = Array.from(allowedCommands.values()).map((command) =>
        command.data.toJSON()
    );
    const customPayload = buildCustomCommandPayloads(customCommandRegistry);
    const commandPayload = [...staticPayload, ...customPayload];

    const rest = new REST({ version: '10' }).setToken(client.token);

    await rest.put(
        Routes.applicationCommands(client.user.id),
        { body: commandPayload }
    );

    return {
        scope: 'global',
        total: commandPayload.length,
        enabled: enabledKeys.length,
        custom: customPayload.length,
        commandNames: commandPayload.map((cmd) => cmd.name),
    };
}

async function syncSlashCommandsForGuild(client, guildId, botId, commandRegistry, customCommandRegistry) {
    if (!client || !client.user || !client.application) {
        throw new Error('Client ist nicht bereit für Slash Sync.');
    }

    const enabledKeys = await loadEnabledCommandKeys(botId);
    const allowedCommands = buildAllowedCommandMap(commandRegistry, enabledKeys);

    const staticPayload = Array.from(allowedCommands.values()).map((command) =>
        command.data.toJSON()
    );
    const customPayload = buildCustomCommandPayloads(customCommandRegistry);
    const commandPayload = [...staticPayload, ...customPayload];

    const rest = new REST({ version: '10' }).setToken(client.token);

    await rest.put(
        Routes.applicationGuildCommands(client.user.id, guildId),
        { body: commandPayload }
    );

    return {
        scope: 'guild',
        guildId,
        total: commandPayload.length,
        enabled: enabledKeys.length,
        custom: customPayload.length,
        commandNames: commandPayload.map((cmd) => cmd.name),
    };
}

async function syncSlashCommands(client, botId, commandRegistry, guildId = null, customCommandRegistry = null) {
    if (guildId && String(guildId).trim() !== '') {
        return syncSlashCommandsForGuild(client, guildId, botId, commandRegistry, customCommandRegistry);
    }

    return syncSlashCommandsForBot(client, botId, commandRegistry, customCommandRegistry);
}

module.exports = {
    syncSlashCommands,
    syncSlashCommandsForBot,
    syncSlashCommandsForGuild,
    loadEnabledCommandKeys,
};