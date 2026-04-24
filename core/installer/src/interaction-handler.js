// PFAD: /core/installer/src/interaction-handler.js
const { PermissionFlagsBits } = require('discord.js');

async function handleButtonInteraction(interaction, botManager, botId) {
    const id = interaction.customId || '';

    if (id.startsWith('bj_')) {
        const { handleBlackjackButton } = require('./services/economy-button-handler');
        await handleBlackjackButton(interaction, botId);
        return;
    }

    if (id.startsWith('mines_')) {
        const { handleMinesButton } = require('./services/economy-button-handler');
        await handleMinesButton(interaction, botId);
        return;
    }

    if (id.startsWith('hm_')) {
        const { handleHangmanButton } = require('./services/economy-button-handler');
        await handleHangmanButton(interaction, botId);
        return;
    }

    if (id.startsWith('crash_stop_')) {
        const { handleCrashButton } = require('./services/economy-button-handler');
        await handleCrashButton(interaction, botId, id);
        return;
    }

    if (id.startsWith('pkm_')) {
        const { handlePkmButton } = require('./services/pokemia-handler');
        await handlePkmButton(interaction, botId);
        return;
    }

    if (id === 'ga_join') {
        const { handleGiveawayButton } = require('./services/giveaway-service');
        await handleGiveawayButton(interaction, botId);
        return;
    }

    if (id === 'ticket_open' || id === 'ticket_close') {
        const { handleTicketButton } = require('./services/ticket-service');
        await handleTicketButton(interaction, botId);
        return;
    }

    if (id.startsWith('ace_')) {
        const { handleArcEnCielButton } = require('./services/arcenciel-service');
        await handleArcEnCielButton(interaction, botId);
        return;
    }

    if (id === 'sug_up' || id === 'sug_down') {
        const { handleSuggestionButton } = require('./services/suggestion-service');
        await handleSuggestionButton(interaction, botId);
        return;
    }
}

async function handleModalInteraction(interaction, botManager, botId) {
    const id = interaction.customId || '';

    if (id.startsWith('hm_modal_')) {
        const { handleHangmanModal } = require('./services/economy-button-handler');
        await handleHangmanModal(interaction, botId);
        return;
    }
}

async function handleInteraction(interaction, botManager, botId) {
    if (!interaction) return;

    // Button interactions
    if (typeof interaction.isButton === 'function' && interaction.isButton()) {
        try {
            await handleButtonInteraction(interaction, botManager, botId);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            console.error(`[BotHub Core] Button handler failed for bot ${botId}:`, message);
            try {
                if (!interaction.replied && !interaction.deferred) {
                    await interaction.reply({ content: 'Fehler beim Verarbeiten des Buttons.', ephemeral: true });
                }
            } catch (_) {}
        }
        return;
    }

    // Modal submit interactions
    if (typeof interaction.isModalSubmit === 'function' && interaction.isModalSubmit()) {
        try {
            await handleModalInteraction(interaction, botManager, botId);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            console.error(`[BotHub Core] Modal handler failed for bot ${botId}:`, message);
            try {
                if (!interaction.replied && !interaction.deferred) {
                    await interaction.reply({ content: 'Fehler beim Verarbeiten des Modals.', ephemeral: true });
                }
            } catch (_) {}
        }
        return;
    }

    // Autocomplete interactions
    if (typeof interaction.isAutocomplete === 'function' && interaction.isAutocomplete()) {
        const commandName = String(interaction.commandName || '').trim();
        let command = null;
        if (botManager.commandRegistry instanceof Map) {
            command = botManager.commandRegistry.get(commandName) || null;
        }
        if (command && typeof command.autocomplete === 'function') {
            try {
                await command.autocomplete(interaction, botId);
            } catch (err) {
                try { await interaction.respond([]); } catch (_) {}
            }
        } else {
            try { await interaction.respond([]); } catch (_) {}
        }
        return;
    }

    if (typeof interaction.isChatInputCommand !== 'function') {
        return;
    }

    if (!interaction.isChatInputCommand()) {
        return;
    }

    const commandName = String(interaction.commandName || '').trim();
    if (commandName === '') {
        return;
    }

    let command = null;

    if (botManager.commandRegistry instanceof Map && botManager.commandRegistry.has(commandName)) {
        command = botManager.commandRegistry.get(commandName);
    } else if (botManager.customCommandRegistries instanceof Map) {
        const customReg = botManager.customCommandRegistries.get(Number(botId));
        if (customReg instanceof Map && customReg.has(commandName)) {
            command = customReg.get(commandName);
        }
    }

    if (!command || typeof command.execute !== 'function') {
        return;
    }

    // Runtime permission guard for built-in commands that declare requiredPermissions.
    // Custom commands handle their own check inside execute().
    if (!command.isCustom && command.requiredPermissions != null && interaction.inGuild()) {
        const bits = BigInt(command.requiredPermissions);
        const memberPerms = interaction.memberPermissions;
        if (bits > BigInt(0) && (!memberPerms || !memberPerms.has(bits))) {
            await interaction.reply({
                content: '🚫 Du hast nicht die benötigten Berechtigungen für diesen Command.',
                ephemeral: true,
            });
            return;
        }
    }

    try {
        await command.execute(interaction, botId, botManager);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        console.error(`[BotHub Core] Command ${commandName} failed for bot ${botId}:`, message);

        if (interaction.replied || interaction.deferred) {
            await interaction.followUp({
                content: 'Beim Ausführen des Commands ist ein Fehler aufgetreten.',
                ephemeral: true
            });
            return;
        }

        await interaction.reply({
            content: 'Beim Ausführen des Commands ist ein Fehler aufgetreten.',
            ephemeral: true
        });
    }
}

module.exports = {
    handleInteraction
};