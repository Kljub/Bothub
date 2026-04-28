// PFAD: /core/installer/src/services/custom-command-service.js

const {
    SlashCommandBuilder,
    PermissionFlagsBits,
} = require('discord.js');
const { dbQuery, botLog } = require('../db');
const { executeFlowGraph } = require('../builder/runner');
const { buildMessageComponents } = require('../builder/helpers');

async function loadCustomCommands(botId) {
    const rows = await dbQuery(
        `
        SELECT
            c.id,
            c.slash_name,
            c.name,
            c.description,
            b.builder_json
        FROM bot_custom_commands AS c
        LEFT JOIN bot_custom_command_builders AS b
            ON b.custom_command_id = c.id
        WHERE c.bot_id = ?
          AND c.is_enabled = 1
        ORDER BY c.id ASC
        `,
        [Number(botId)]
    );

    if (!Array.isArray(rows)) return [];

    return rows
        .map((row) => {
            const slashName = String(row.slash_name || '').trim().toLowerCase();
            if (slashName === '') return null;

            let builderData = null;
            if (row.builder_json) {
                try {
                    const parsed = JSON.parse(String(row.builder_json));
                    if (parsed && typeof parsed === 'object') builderData = parsed;
                } catch (_) {}
            }

            return {
                id: Number(row.id),
                slashName,
                name:        String(row.name        || '').trim(),
                description: String(row.description || '').trim(),
                builderData,
            };
        })
        .filter(Boolean);
}

function permNamesToBigInt(names) {
    if (!Array.isArray(names) || names.length === 0) return null;
    let bits = BigInt(0);
    for (const name of names) {
        const bit = PermissionFlagsBits[name];
        if (bit !== undefined) bits |= BigInt(bit);
    }
    return bits > BigInt(0) ? bits : null;
}

function buildSlashCommandData(cmd) {
    const builder = new SlashCommandBuilder()
        .setName(cmd.slashName)
        .setDescription(cmd.description || 'Custom command');

    if (!cmd.builderData || !Array.isArray(cmd.builderData.nodes)) return builder;

    const nodes = cmd.builderData.nodes;
    const edges = Array.isArray(cmd.builderData.edges) ? cmd.builderData.edges : [];

    const triggerNode = nodes.find((n) => n.type === 'trigger.slash');
    if (!triggerNode) return builder;

    const permBits = permNamesToBigInt(triggerNode.config?.required_permissions);
    if (permBits !== null) builder.setDefaultMemberPermissions(permBits);

    const optionNodes = nodes.filter(
        (n) => n.type && n.type.startsWith('option.') &&
               edges.some((e) => e.from_node_id === n.id && e.to_node_id === triggerNode.id)
    );

    for (const optNode of optionNodes) {
        const cfg     = optNode.config || {};
        const rawName = String(cfg.option_name || cfg.name || '').trim();
        const optName = rawName.toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 32);
        const optDesc = String(cfg.description || 'Option').trim().slice(0, 100) || 'Option';
        const required = !!cfg.required;

        if (optName === '') continue;

        try {
            switch (optNode.type) {
                case 'option.text':       builder.addStringOption((o) => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.number':     builder.addNumberOption((o) => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.user':       builder.addUserOption((o)   => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.channel':    builder.addChannelOption((o) => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.role':       builder.addRoleOption((o)   => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.choice':     builder.addBooleanOption((o) => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                case 'option.attachment': builder.addAttachmentOption((o) => o.setName(optName).setDescription(optDesc).setRequired(required)); break;
                default: break;
            }
        } catch (error) {
            console.warn(
                `[custom-command-service] Skipping invalid option "${optName}" on command "${cmd.slashName}":`,
                error instanceof Error ? error.message : String(error)
            );
        }
    }

    return builder;
}

// Per-command cooldown storage
const _cooldownStore = new Map();

function buildCustomCommandObject(cmd, botId) {
    const triggerNode = (() => {
        try {
            const bd = cmd.builderData;
            if (bd && Array.isArray(bd.nodes)) return bd.nodes.find((n) => n.type === 'trigger.slash') || null;
        } catch (_) {}
        return null;
    })();

    const cmdEphemeral     = triggerNode?.config?.ephemeral === '1' || triggerNode?.config?.ephemeral === true;
    const cooldownType     = String(triggerNode?.config?.cooldown_type   || 'none');
    const cooldownSeconds  = Math.max(1, parseInt(triggerNode?.config?.cooldown_seconds || '10', 10) || 10);
    const requiredPermBits = permNamesToBigInt(triggerNode?.config?.required_permissions);

    if (!_cooldownStore.has(cmd.id)) _cooldownStore.set(cmd.id, new Map());
    const cooldownMap = _cooldownStore.get(cmd.id);

    return {
        key:             cmd.slashName,
        data:            buildSlashCommandData(cmd),
        isCustom:        true,
        customCommandId: cmd.id,

        async execute(interaction) {
            if (!cmd.builderData) {
                await interaction.reply({ content: 'Dieser Command hat noch keinen Builder-Flow.', ephemeral: true });
                return;
            }

            if (requiredPermBits !== null && interaction.inGuild()) {
                const memberPerms = interaction.memberPermissions;
                if (!memberPerms || !memberPerms.has(requiredPermBits)) {
                    await interaction.reply({ content: '🚫 Du hast nicht die benötigten Berechtigungen für diesen Command.', ephemeral: true });
                    return;
                }
            }

            if (cooldownType !== 'none') {
                const cooldownKey = cooldownType === 'user'
                    ? `u:${interaction.user.id}`
                    : `s:${interaction.guildId || 'dm'}`;
                const now    = Date.now();
                const expiry = cooldownMap.get(cooldownKey) || 0;
                if (now < expiry) {
                    const remaining = ((expiry - now) / 1000).toFixed(1);
                    await interaction.reply({ content: `⏳ Bitte warte noch **${remaining}s** bevor du diesen Command erneut verwendest.`, ephemeral: true });
                    return;
                }
                cooldownMap.set(cooldownKey, now + cooldownSeconds * 1000);
            }

            try {
                await executeFlowGraph(cmd.builderData, interaction, botId, cmd.slashName, null, cmdEphemeral);
            } catch (error) {
                if (!error?._bhLogged) {
                    const errMsg = error instanceof Error ? error.message : String(error);
                    console.error(`[custom-command-service] Command /${cmd.slashName} unhandled error:`, errMsg);
                    botLog(botId, 'error', `Custom Command /${cmd.slashName} Fehler: ${errMsg}`, { command: cmd.slashName });
                }
                throw error;
            }
        },
    };
}

async function loadCustomCommandRegistry(botId) {
    const cmds     = await loadCustomCommands(botId);
    const registry = new Map();

    for (const cmd of cmds) {
        registry.set(cmd.slashName, buildCustomCommandObject(cmd, botId));
    }

    console.log(`[custom-command-service] Loaded ${registry.size} custom command(s) for bot ${botId}.`);
    return registry;
}

module.exports = {
    loadCustomCommands,
    loadCustomCommandRegistry,
    buildSlashCommandData,
};
