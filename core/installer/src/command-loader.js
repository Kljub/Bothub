// PFAD: /core/installer/src/command-loader.js

const fs = require('fs');
const path = require('path');

function loadCommands(botManager) {
    const commandsPath = path.join(__dirname, 'commands');

    if (!fs.existsSync(commandsPath)) {
        console.warn('[BotHub Core] commands folder missing.');
        return;
    }

    const files = fs.readdirSync(commandsPath, { recursive: true })
        .filter((file) => file.endsWith('.js'));

    if (!(botManager.commandRegistry instanceof Map)) {
        botManager.commandRegistry = new Map();
    } else {
        botManager.commandRegistry.clear();
    }

    let loadedCount = 0;

    for (const file of files) {
        const filePath = path.join(commandsPath, file);

        try {
            delete require.cache[require.resolve(filePath)];
        } catch (_) {
            // ignore cache delete problems
        }

        let command;
        try {
            command = require(filePath);
        } catch (error) {
            console.warn(`[BotHub Core] Failed to load command ${file}:`, error.message);
            continue;
        }

        if (!command || !command.data || typeof command.execute !== 'function') {
            console.warn(`[BotHub Core] Invalid command: ${file}`);
            continue;
        }

        const commandName = typeof command.key === 'string' && command.key.trim() !== ''
            ? command.key.trim()
            : String(command.data.name || '').trim();

        if (commandName === '') {
            console.warn(`[BotHub Core] Missing command name: ${file}`);
            continue;
        }

        botManager.commandRegistry.set(commandName, command);

        // Also register by Discord slash name if it differs from the key
        const slashName = String(command.data.name || '').trim();
        if (slashName !== '' && slashName !== commandName) {
            botManager.commandRegistry.set(slashName, command);
        }

        loadedCount++;
    }

    console.log(`[BotHub Core] ${loadedCount}/${files.length} Commands geladen.`);
}

module.exports = {
    loadCommands
};