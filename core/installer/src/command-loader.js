// PFAD: /core/installer/src/command-loader.js

const fs = require('fs');
const path = require('path');

function loadCommands(botManager) {
    if (!(botManager.commandRegistry instanceof Map)) {
        botManager.commandRegistry = new Map();
    } else {
        botManager.commandRegistry.clear();
    }

    let totalLoaded = 0;
    let totalFiles  = 0;

    function scanDir(dirPath, label) {
        if (!fs.existsSync(dirPath)) {
            console.warn(`[BotHub Core] commands folder missing (${label}): ${dirPath}`);
            return;
        }

        const files = fs.readdirSync(dirPath, { recursive: true })
            .filter((file) => typeof file === 'string' && file.endsWith('.js'));

        totalFiles += files.length;

        for (const file of files) {
            const filePath = path.join(dirPath, file);

            try {
                delete require.cache[require.resolve(filePath)];
            } catch (_) {}

            let command;
            try {
                command = require(filePath);
            } catch (error) {
                console.warn(`[BotHub Core] Failed to load command ${file} (${label}):`, error.message);
                continue;
            }

            if (!command || !command.data || typeof command.execute !== 'function') {
                console.warn(`[BotHub Core] Invalid command: ${file} (${label})`);
                continue;
            }

            const commandName = typeof command.key === 'string' && command.key.trim() !== ''
                ? command.key.trim()
                : String(command.data.name || '').trim();

            if (commandName === '') {
                console.warn(`[BotHub Core] Missing command name: ${file} (${label})`);
                continue;
            }

            botManager.commandRegistry.set(commandName, command);

            const slashName = String(command.data.name || '').trim();
            if (slashName !== '' && slashName !== commandName) {
                botManager.commandRegistry.set(slashName, command);
            }

            totalLoaded++;
        }
    }

    scanDir(path.join(__dirname, 'commands'),                          'core');
    scanDir(path.join(__dirname, 'community-apps', 'commands'),        'community');

    console.log(`[BotHub Core] ${totalLoaded}/${totalFiles} Commands geladen.`);
}

module.exports = {
    loadCommands
};
