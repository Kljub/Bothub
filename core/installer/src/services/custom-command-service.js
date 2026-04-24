// PFAD: /core/installer/src/services/custom-command-service.js

const {
    SlashCommandBuilder,
    ButtonBuilder,
    ButtonStyle,
    StringSelectMenuBuilder,
    StringSelectMenuOptionBuilder,
    ActionRowBuilder,
    PermissionFlagsBits,
} = require('discord.js');
const { dbQuery, botLog } = require('../db');
const { getQueue: getMusicQueue } = require('./music-service');

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

    if (!Array.isArray(rows)) {
        return [];
    }

    return rows
        .map((row) => {
            const slashName = String(row.slash_name || '').trim().toLowerCase();
            if (slashName === '') {
                return null;
            }

            let builderData = null;
            if (row.builder_json) {
                try {
                    const parsed = JSON.parse(String(row.builder_json));
                    if (parsed && typeof parsed === 'object') {
                        builderData = parsed;
                    }
                } catch (error) {
                    builderData = null;
                }
            }

            return {
                id: Number(row.id),
                slashName,
                name: String(row.name || '').trim(),
                description: String(row.description || '').trim(),
                builderData,
            };
        })
        .filter(Boolean);
}

/**
 * Converts an array of PermissionFlagsBits key names to a combined BigInt bitmask.
 * Unknown names are silently ignored.
 * Returns null when the array is empty or all names are unknown.
 */
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

    if (!cmd.builderData || !Array.isArray(cmd.builderData.nodes)) {
        return builder;
    }

    const nodes = cmd.builderData.nodes;
    const edges = Array.isArray(cmd.builderData.edges) ? cmd.builderData.edges : [];

    const triggerNode = nodes.find((n) => n.type === 'trigger.slash');
    if (!triggerNode) {
        return builder;
    }

    // Apply required permissions → Discord hides the command from members without them
    const permBits = permNamesToBigInt(triggerNode.config?.required_permissions);
    if (permBits !== null) {
        builder.setDefaultMemberPermissions(permBits);
    }

    // Option nodes: any edge whose source is an option.* node and target is the trigger
    // (to_port may be 'options' or 'in' depending on builder version — accept both)
    const optionNodes = nodes.filter(
        (n) => n.type && n.type.startsWith('option.') &&
               edges.some((e) => e.from_node_id === n.id && e.to_node_id === triggerNode.id)
    );

    for (const optNode of optionNodes) {
        const cfg = optNode.config || {};
        const rawName = String(cfg.option_name || cfg.name || '').trim();
        const optName = rawName.toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 32);
        const optDesc = String(cfg.description || 'Option').trim().slice(0, 100) || 'Option';
        const required = !!cfg.required;

        if (optName === '') {
            continue;
        }

        try {
            switch (optNode.type) {
                case 'option.text':
                    builder.addStringOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.number':
                    builder.addNumberOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.user':
                    builder.addUserOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.channel':
                    builder.addChannelOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.role':
                    builder.addRoleOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.choice':
                    builder.addBooleanOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                case 'option.attachment':
                    builder.addAttachmentOption((o) =>
                        o.setName(optName).setDescription(optDesc).setRequired(required)
                    );
                    break;
                default:
                    break;
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


function resolveVariables(text, interaction, optionValues, localVars, globalVars) {
    if (typeof text !== 'string') {
        return String(text || '');
    }

    // Resolve member-specific values (available when in a guild)
    const member      = interaction.member || null;
    const guildMember = member && typeof member.joinedAt !== 'undefined' ? member : null;
    const presence    = guildMember ? (guildMember.presence || null) : null;

    return text
        .replace(/\{user\}/gi, interaction.user.tag)
        .replace(/\{user\.name\}/gi, interaction.user.username)
        .replace(/\{user\.id\}/gi, interaction.user.id)
        .replace(/\{user\.tag\}/gi, interaction.user.tag)
        .replace(/\{user\.discriminator\}/gi, interaction.user.discriminator || '0')
        .replace(/\{user\.icon\}/gi, interaction.user.displayAvatarURL({ size: 256 }))
        .replace(/\{usericon\}/gi, interaction.user.displayAvatarURL({ size: 256 }))
        .replace(/\{user\.display_name\}/gi, guildMember ? (guildMember.displayName || interaction.user.username) : interaction.user.username)
        .replace(/\{user\.created_at\}/gi, interaction.user.createdAt ? interaction.user.createdAt.toLocaleDateString('de-DE') : '')
        .replace(/\{user\.joined_at\}/gi, guildMember && guildMember.joinedAt ? guildMember.joinedAt.toLocaleDateString('de-DE') : '')
        .replace(/\{user\.status\}/gi, presence ? (presence.status || 'offline') : 'offline')
        .replace(/\{user\.is_bot\}/gi, interaction.user.bot ? 'true' : 'false')
        .replace(/\{user\.nickname\}/gi, guildMember ? (guildMember.nickname || interaction.user.username) : interaction.user.username)
        .replace(/\{server\}/gi, interaction.guild ? interaction.guild.name : '')
        .replace(/\{server\.id\}/gi, interaction.guild ? interaction.guild.id : '')
        .replace(/\{channel\}/gi, interaction.channel ? interaction.channel.name : '')
        .replace(/\{option\.([a-z0-9_-]+)\}/gi, (_, optName) => {
            if (optionValues.has(optName)) {
                return String(optionValues.get(optName) ?? '');
            }
            return '';
        })
        .replace(/\{local\.([a-z0-9_-]+)\}/gi, (_, key) => {
            if (localVars && localVars.has(key)) {
                return String(localVars.get(key) ?? '');
            }
            return '';
        })
        .replace(/\{global\.([a-z0-9_-]+)\}/gi, (_, key) => {
            if (globalVars && globalVars.has(key)) {
                return String(globalVars.get(key) ?? '');
            }
            return '';
        })
        .replace(/\{([a-z0-9_-]+)\.Input\.(\d+)\.InputLabel\}/g, (_, formKey, num) => {
            const k = `${formKey}.Input.${num}.InputLabel`;
            return localVars && localVars.has(k) ? String(localVars.get(k) ?? '') : '';
        });
}

function collectOptionValues(interaction) {
    const values = new Map();

    if (!interaction.options) {
        return values;
    }

    try {
        const data = interaction.options.data;
        if (Array.isArray(data)) {
            for (const opt of data) {
                if (opt && opt.name) {
                    values.set(String(opt.name), opt.value ?? null);
                }
            }
        }
    } catch (error) {
        // ignore
    }

    return values;
}

const BUTTON_STYLE_MAP = {
    primary:   ButtonStyle.Primary,
    secondary: ButtonStyle.Secondary,
    success:   ButtonStyle.Success,
    danger:    ButtonStyle.Danger,
    link:      ButtonStyle.Link,
};

/**
 * Build Discord components (buttons + select menus) for a message node.
 * custom_id format: `bh:{type}:{botId}:{slashName}:{nodeId}`
 */
function buildMessageComponents(msgNodeId, edges, nodes, botId, slashName) {
    const rows = [];

    // Collect connected button nodes (from_port === 'button')
    const buttonEdges = edges.filter((e) => e.from_node_id === msgNodeId && e.from_port === 'button');
    if (buttonEdges.length > 0) {
        const buttonRow = new ActionRowBuilder();
        for (const edge of buttonEdges.slice(0, 5)) {
            const btnNode = nodes.find((n) => n.id === edge.to_node_id);
            if (!btnNode) continue;
            const cfg = btnNode.config || {};
            const style     = cfg.style || 'primary';
            const label     = String(cfg.label || 'Button').slice(0, 80);
            const customId  = String(cfg.custom_id || '').trim() || `bh:btn:${botId}:${slashName}:${btnNode.id}`;
            const btn = new ButtonBuilder().setLabel(label);
            if (style === 'link') {
                btn.setStyle(ButtonStyle.Link).setURL(customId);
            } else {
                btn.setStyle(BUTTON_STYLE_MAP[style] || ButtonStyle.Primary).setCustomId(customId.slice(0, 100));
            }
            if (cfg.emoji) {
                try { btn.setEmoji(String(cfg.emoji).trim()); } catch (_) {}
            }
            buttonRow.addComponents(btn);
        }
        if (buttonRow.components.length > 0) rows.push(buttonRow);
    }

    // Collect connected select menu node (from_port === 'menu')
    const menuEdge = edges.find((e) => e.from_node_id === msgNodeId && e.from_port === 'menu');
    if (menuEdge) {
        const menuNode = nodes.find((n) => n.id === menuEdge.to_node_id);
        if (menuNode) {
            const cfg      = menuNode.config || {};
            const customId = String(cfg.custom_id || '').trim() || `bh:sm:${botId}:${slashName}:${menuNode.id}`;
            const options  = Array.isArray(cfg.options) ? cfg.options : [];
            if (options.length > 0) {
                const menu = new StringSelectMenuBuilder()
                    .setCustomId(customId.slice(0, 100))
                    .setPlaceholder(String(cfg.placeholder || 'Select an option...').slice(0, 150))
                    .setMinValues(1)
                    .setMaxValues(Math.min(Number(cfg.max_values) || 1, options.length))
                    .setDisabled(cfg.disabled === 'true' || cfg.disabled === true);

                for (const opt of options.slice(0, 25)) {
                    const o = new StringSelectMenuOptionBuilder()
                        .setLabel(String(opt.label || 'Option').slice(0, 100))
                        .setValue(String(opt.value || opt.label || 'option').slice(0, 100));
                    if (opt.description) o.setDescription(String(opt.description).slice(0, 100));
                    menu.addOptions(o);
                }
                rows.push(new ActionRowBuilder().addComponents(menu));
            }
        }
    }

    return rows;
}

function buildEmbeds(embedConfigs, interaction, optionValues, localVars, globalVars) {
    if (!Array.isArray(embedConfigs) || embedConfigs.length === 0) {
        return [];
    }

    const { EmbedBuilder } = require('discord.js');
    const embeds = [];

    for (const ec of embedConfigs.slice(0, 10)) {
        if (!ec || typeof ec !== 'object') {
            continue;
        }

        const embed = new EmbedBuilder();

        try {
            const colorHex = String(ec.color || '#5865F2').trim();
            if (/^#[0-9a-f]{6}$/i.test(colorHex)) {
                embed.setColor(colorHex);
            }

            const authorName = resolveVariables(String(ec.author_name || ''), interaction, optionValues, localVars, globalVars);
            if (authorName) {
                embed.setAuthor({
                    name: authorName,
                    ...(ec.author_icon_url ? { iconURL: String(ec.author_icon_url) } : {}),
                });
            }

            const title = resolveVariables(String(ec.title || ''), interaction, optionValues, localVars, globalVars);
            if (title) {
                embed.setTitle(title);
                if (ec.url) {
                    embed.setURL(String(ec.url));
                }
            }

            const description = resolveVariables(String(ec.description || ''), interaction, optionValues, localVars, globalVars);
            if (description) {
                embed.setDescription(description);
            }

            if (ec.thumbnail_url) {
                embed.setThumbnail(String(ec.thumbnail_url));
            }

            if (ec.image_url) {
                embed.setImage(String(ec.image_url));
            }

            if (Array.isArray(ec.fields)) {
                for (const f of ec.fields.slice(0, 25)) {
                    if (!f) {
                        continue;
                    }
                    const name = resolveVariables(String(f.name || ''), interaction, optionValues, localVars, globalVars);
                    const value = resolveVariables(String(f.value || ''), interaction, optionValues, localVars, globalVars);
                    if (name && value) {
                        embed.addFields({ name, value, inline: !!f.inline });
                    }
                }
            }

            const footerText = resolveVariables(String(ec.footer_text || ''), interaction, optionValues, localVars, globalVars);
            if (footerText) {
                embed.setFooter({ text: footerText });
            }

            if (ec.timestamp) {
                embed.setTimestamp();
            }

            embeds.push(embed);
        } catch (error) {
            console.warn('[custom-command-service] Embed build error:', error instanceof Error ? error.message : String(error));
        }
    }

    return embeds;
}

async function executeFlowGraph(builderData, interaction, botId, slashName = '', startNodeId = null, cmdEphemeral = false) {
    if (!builderData || !Array.isArray(builderData.nodes)) {
        await interaction.reply({ content: 'Dieser Command ist noch nicht konfiguriert.', ephemeral: true });
        return;
    }

    const nodes = builderData.nodes;
    const edges = Array.isArray(builderData.edges) ? builderData.edges : [];
    const nodeMap = new Map(nodes.map((n) => [n.id, n]));
    const optionValues = collectOptionValues(interaction);
    const messageVars = new Map(); // stores sent Message objects by var_name for later editing
    const localVars   = new Map(); // local variables, scoped to this execution
    const globalVars  = new Map(); // global variables, preloaded from DB

    // Preload global variables for this bot
    if (botId) {
        try {
            const gRows = await dbQuery(
                'SELECT var_key, var_value FROM bot_global_variables WHERE bot_id = ?',
                [Number(botId)]
            );
            if (Array.isArray(gRows)) {
                for (const row of gRows) {
                    globalVars.set(String(row.var_key), row.var_value != null ? String(row.var_value) : '');
                }
            }
        } catch (_) {
            // Non-fatal: global vars unavailable
        }
    }

    let replied = false;

    function getNextNode(fromNodeId, fromPort) {
        const edge = edges.find(
            (e) => e.from_node_id === fromNodeId && e.from_port === fromPort
        );
        if (!edge) {
            return null;
        }
        return nodeMap.get(edge.to_node_id) || null;
    }

    const triggerNode = nodes.find((n) => n.type === 'trigger.slash');

    if (!triggerNode) {
        await interaction.reply({ content: 'Kein Trigger-Node gefunden.', ephemeral: true });
        return;
    }

    // ── Allowed-roles check ───────────────────────────────────────────────────
    const allowedRoles = Array.isArray(triggerNode.config?.allowed_roles)
        ? triggerNode.config.allowed_roles
        : [{ id: 'everyone' }];

    const hasEveryoneEntry = allowedRoles.some((r) => r.id === 'everyone');

    if (!hasEveryoneEntry && allowedRoles.length > 0) {
        const member = interaction.member;
        const memberRoleIds = member?.roles?.cache
            ? [...member.roles.cache.keys()]
            : (Array.isArray(member?.roles) ? member.roles : []);

        const hasRole = allowedRoles.some((r) => memberRoleIds.includes(r.id));

        if (!hasRole) {
            const roleNames = allowedRoles.map((r) => r.name || r.id).join(', ');
            await interaction.reply({
                content: `⛔ Oops, du hast nicht die benötigte Berechtigung, um diesen Command zu nutzen.\n**Benötigte Rolle(n):** ${roleNames}`,
                ephemeral: true,
            });
            return;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    let currentNode = startNodeId
        ? (nodeMap.get(startNodeId) ? getNextNode(startNodeId, 'next') : null)
        : getNextNode(triggerNode.id, 'next');
    const MAX_STEPS = 300;
    let steps = 0;

    // Loop stack: each entry = { bodyStartNode, nextAfterLoop, maxIter, currentIter, mode, items, idxKey, itemKey }
    const loopStack = [];

    while (true) {
        // When a branch ends (currentNode null) but we're inside a loop, advance the loop
        if (currentNode === null && loopStack.length > 0) {
            const ctx = loopStack[loopStack.length - 1];
            ctx.currentIter++;
            if (ctx.currentIter < ctx.maxIter) {
                const idxKey  = ctx.idxKey  || 'loop.index';
                const itemKey = ctx.itemKey || 'loop.item';
                localVars.set(idxKey, String(ctx.currentIter));
                if (ctx.mode === 'foreach' && ctx.items) {
                    localVars.set(itemKey, String(ctx.items[ctx.currentIter] ?? ''));
                }
                currentNode = ctx.bodyStartNode;
            } else {
                loopStack.pop();
                currentNode = ctx.nextAfterLoop;
            }
            continue;
        }

        if (currentNode === null || steps >= MAX_STEPS) break;
        steps++;
        const cfg = currentNode.config || {};

        try {
            switch (currentNode.type) {
                case 'action.send_message': {
                    const content = resolveVariables(String(cfg.content || ''), interaction, optionValues, localVars, globalVars);
                    const ephemeral = !!cfg.ephemeral;
                    const tts = !!cfg.tts;

                    if (!replied && !interaction.replied && !interaction.deferred) {
                        await interaction.reply({ content: content || '\u200B', ephemeral, tts });
                        replied = true;
                    } else {
                        await interaction.followUp({ content: content || '\u200B', ephemeral, tts });
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.message.send_or_edit': {
                    const content    = resolveVariables(String(cfg.message_content || ''), interaction, optionValues, localVars, globalVars);
                    const ephemeral  = !!cfg.ephemeral;
                    const embeds     = buildEmbeds(cfg.embeds || [], interaction, optionValues, localVars, globalVars);
                    const responseType = String(cfg.response_type || 'reply');
                    const varName    = String(cfg.var_name || '').trim();
                    const components = buildMessageComponents(currentNode.id, edges, nodes, botId, slashName);

                    const payload = {
                        ...(content  ? { content }  : { content: '\u200B' }),
                        ...(embeds.length > 0     ? { embeds }     : {}),
                        ...(components.length > 0 ? { components } : {}),
                    };

                    switch (responseType) {
                        case 'channel': {
                            // Send to the channel the command was used in (no ephemeral)
                            if (interaction.channel) {
                                const msg = await interaction.channel.send(payload);
                                if (varName) messageVars.set(varName, msg);
                                if (!replied && !interaction.replied && !interaction.deferred) {
                                    await interaction.deferReply({ ephemeral: true });
                                    await interaction.deleteReply();
                                    replied = true;
                                }
                            }
                            break;
                        }
                        case 'specific_channel': {
                            const channelId = resolveVariables(String(cfg.target_channel_id || '').trim(), interaction, optionValues, localVars, globalVars);
                            if (channelId && interaction.client) {
                                const ch = await interaction.client.channels.fetch(channelId).catch(() => null);
                                if (ch && ch.isTextBased()) {
                                    const msg = await ch.send(payload);
                                    if (varName) messageVars.set(varName, msg);
                                }
                            }
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply({ content: '✅', ephemeral: true });
                                replied = true;
                            }
                            break;
                        }
                        case 'channel_option': {
                            const optName = String(cfg.target_option_name || '').trim().toLowerCase();
                            const ch = optName ? interaction.options.getChannel(optName) : null;
                            if (ch && ch.isTextBased()) {
                                const msg = await ch.send(payload);
                                if (varName) messageVars.set(varName, msg);
                            }
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply({ content: '✅', ephemeral: true });
                                replied = true;
                            }
                            break;
                        }
                        case 'dm_user': {
                            const msg = await interaction.user.send(payload).catch(() => null);
                            if (varName && msg) messageVars.set(varName, msg);
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                                replied = true;
                            }
                            break;
                        }
                        case 'dm_user_option': {
                            const optName = String(cfg.target_dm_option_name || '').trim().toLowerCase();
                            const targetUser = optName ? interaction.options.getUser(optName) : null;
                            if (targetUser) {
                                const msg = await targetUser.send(payload).catch(() => null);
                                if (varName && msg) messageVars.set(varName, msg);
                            }
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                                replied = true;
                            }
                            break;
                        }
                        case 'dm_specific_user': {
                            const userId = resolveVariables(String(cfg.target_user_id || '').trim(), interaction, optionValues, localVars, globalVars);
                            if (userId && interaction.client) {
                                const user = await interaction.client.users.fetch(userId).catch(() => null);
                                if (user) {
                                    const msg = await user.send(payload).catch(() => null);
                                    if (varName && msg) messageVars.set(varName, msg);
                                }
                            }
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                                replied = true;
                            }
                            break;
                        }
                        case 'edit_action': {
                            // Edit a message previously stored under edit_target_var
                            const targetVar = String(cfg.edit_target_var || '').trim();
                            if (targetVar && messageVars.has(targetVar)) {
                                const targetMsg = messageVars.get(targetVar);
                                const edited = await targetMsg.edit(payload).catch(() => null);
                                // Re-store under the same key (and optionally under a new var_name)
                                if (edited) {
                                    messageVars.set(targetVar, edited);
                                    if (varName && varName !== targetVar) messageVars.set(varName, edited);
                                }
                            }
                            // Silently acknowledge the interaction if not yet replied
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.deferReply({ ephemeral: true });
                                await interaction.deleteReply().catch(() => null);
                                replied = true;
                            }
                            break;
                        }
                        case 'reply_message': {
                            // Reply to a specific message by ID (or variable) in the current channel
                            const rawMsgId = resolveVariables(String(cfg.target_message_id || '').trim(), interaction, optionValues, localVars, globalVars);
                            let targetMsg = null;
                            if (rawMsgId && interaction.channel) {
                                // Check if it's a stored message object variable first
                                targetMsg = messageVars.get(rawMsgId) || await interaction.channel.messages.fetch(rawMsgId).catch(() => null);
                            }
                            if (targetMsg && interaction.channel) {
                                const msg = await targetMsg.reply(payload).catch(() => interaction.channel.send(payload));
                                if (varName && msg) messageVars.set(varName, msg);
                            } else if (interaction.channel) {
                                const msg = await interaction.channel.send(payload);
                                if (varName) messageVars.set(varName, msg);
                            }
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.deferReply({ ephemeral: true });
                                await interaction.deleteReply().catch(() => null);
                                replied = true;
                            }
                            break;
                        }
                        default: {
                            // 'reply' — standard interaction reply
                            payload.ephemeral = ephemeral;
                            if (!replied && !interaction.replied && !interaction.deferred) {
                                await interaction.reply(payload);
                                replied = true;
                                if (varName) {
                                    const msg = await interaction.fetchReply().catch(() => null);
                                    if (msg) messageVars.set(varName, msg);
                                }
                            } else {
                                const msg = await interaction.followUp(payload);
                                if (varName) messageVars.set(varName, msg);
                            }
                            break;
                        }
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.message.send_form': {
                    const { ModalBuilder, TextInputBuilder, TextInputStyle } = require('discord.js');
                    const formTitle  = String(cfg.form_title  || 'Form').slice(0, 45);
                    const formName   = String(cfg.form_name   || 'form').trim();
                    const formFields = Array.isArray(cfg.fields) ? cfg.fields : [];
                    const customId   = `bh:form:${botId}:${slashName}:${currentNode.id}`;

                    const modal = new ModalBuilder()
                        .setTitle(formTitle)
                        .setCustomId(customId.slice(0, 100));

                    formFields.slice(0, 5).forEach((f, i) => {
                        if (f.hidden === 'true' || f.hidden === true) return;
                        const fieldId = `field_${i + 1}`;
                        const inp = new TextInputBuilder()
                            .setCustomId(fieldId)
                            .setLabel(String(f.label || 'Input').slice(0, 45))
                            .setStyle(f.style === 'paragraph' ? TextInputStyle.Paragraph : TextInputStyle.Short)
                            .setRequired(f.required !== 'false' && f.required !== false);
                        if (f.placeholder) inp.setPlaceholder(String(f.placeholder).slice(0, 100));
                        if (f.min_length)  inp.setMinLength(Math.max(0, Number(f.min_length)));
                        if (f.max_length)  inp.setMaxLength(Math.min(4000, Number(f.max_length)));
                        if (f.default)     inp.setValue(String(f.default).slice(0, 4000));
                        modal.addComponents(new ActionRowBuilder().addComponents(inp));
                    });

                    await interaction.showModal(modal);

                    // Wait for the user to submit (15 s timeout)
                    const submitted = await interaction.awaitModalSubmit({
                        filter: (i) => i.customId === customId && i.user.id === interaction.user.id,
                        time: 15 * 60 * 1000,
                    }).catch(() => null);

                    if (submitted) {
                        // Store each field as {formName.Input.N.InputLabel}
                        formFields.slice(0, 5).forEach((f, i) => {
                            if (f.hidden === 'true' || f.hidden === true) return;
                            try {
                                const val = submitted.fields.getTextInputValue(`field_${i + 1}`) ?? '';
                                localVars.set(`${formName}.Input.${i + 1}.InputLabel`, String(val));
                            } catch (_) {
                                localVars.set(`${formName}.Input.${i + 1}.InputLabel`, '');
                            }
                        });
                        // Acknowledge the modal submit so Discord doesn't show an error
                        if (!submitted.replied && !submitted.deferred) {
                            await submitted.deferReply({ ephemeral: true }).catch(() => {});
                        }
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.flow.wait': {
                    const ms = Math.min(
                        Math.max(Number(cfg.duration_ms || cfg.ms || 1000), 100),
                        14000
                    );
                    await new Promise((resolve) => setTimeout(resolve, ms));
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'condition.comparison': {
                    const conds   = Array.isArray(cfg.conditions) ? cfg.conditions : [];
                    const mode    = String(cfg.run_mode || 'first_match');

                    function evalCond(cond) {
                        const base = resolveVariables(String(cond.base_value       || ''), interaction, optionValues, localVars, globalVars);
                        const comp = resolveVariables(String(cond.comparison_value || ''), interaction, optionValues, localVars, globalVars);
                        const op   = String(cond.operator || '==');
                        const bNum = parseFloat(base);
                        const cNum = parseFloat(comp);
                        const numeric = !isNaN(bNum) && !isNaN(cNum);
                        switch (op) {
                            case '<':                    return numeric ? bNum < cNum  : base <  comp;
                            case '<=':                   return numeric ? bNum <= cNum : base <= comp;
                            case '>':                    return numeric ? bNum > cNum  : base >  comp;
                            case '>=':                   return numeric ? bNum >= cNum : base >= comp;
                            case '==':                   return base === comp || (numeric && bNum === cNum);
                            case '!=':                   return base !== comp && (!numeric || bNum !== cNum);
                            case 'contains':             return base.includes(comp);
                            case 'not_contains':         return !base.includes(comp);
                            case 'starts_with':          return base.startsWith(comp);
                            case 'ends_with':            return base.endsWith(comp);
                            case 'not_starts_with':      return !base.startsWith(comp);
                            case 'not_ends_with':        return !base.endsWith(comp);
                            case 'collection_contains': {
                                try { const a = JSON.parse(base); return Array.isArray(a) && a.map(String).includes(comp); } catch (_) { return false; }
                            }
                            case 'collection_not_contains': {
                                try { const a = JSON.parse(base); return !(Array.isArray(a) && a.map(String).includes(comp)); } catch (_) { return true; }
                            }
                            default: return false;
                        }
                    }

                    let matched = false;
                    if (mode === 'all_matches') {
                        // Execute each matching branch sequentially, then continue after all
                        for (let i = 0; i < conds.length; i++) {
                            if (evalCond(conds[i])) {
                                matched = true;
                                const branchNode = getNextNode(currentNode.id, 'cond_' + i);
                                if (branchNode) {
                                    // Run branch sub-graph up to its natural end
                                    let subNode = branchNode;
                                    let subSteps = 0;
                                    while (subNode !== null && subSteps < MAX_STEPS) {
                                        subSteps++;
                                        // Re-enter the outer loop body for this sub-node is complex;
                                        // for now, just record where to start next pass via a queue
                                        break; // placeholder — sub-graph not yet supported
                                    }
                                }
                            }
                        }
                        // Fall back to first match if all_matches sub-graph not yet supported
                        matched = false;
                    }

                    if (!matched) {
                        // first_match: follow the first matching condition port
                        for (let i = 0; i < conds.length; i++) {
                            if (evalCond(conds[i])) {
                                currentNode = getNextNode(currentNode.id, 'cond_' + i);
                                matched = true;
                                break;
                            }
                        }
                    }
                    if (!matched) {
                        currentNode = getNextNode(currentNode.id, 'else');
                    }
                    break;
                }

                case 'variable.local.set': {
                    const key = String(cfg.var_key || '').trim();
                    if (key) {
                        const value = resolveVariables(String(cfg.var_value || ''), interaction, optionValues, localVars, globalVars);
                        localVars.set(key, value);
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.global.set': {
                    const key = String(cfg.var_key || '').trim();
                    if (key && botId) {
                        const value = resolveVariables(String(cfg.var_value || ''), interaction, optionValues, localVars, globalVars);
                        globalVars.set(key, value); // update in-memory immediately
                        await dbQuery(
                            `INSERT INTO bot_global_variables (bot_id, var_key, var_value)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE var_value = VALUES(var_value)`,
                            [Number(botId), key, value]
                        );
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.global.delete': {
                    const key = String(cfg.var_key || '').trim();
                    if (key && botId) {
                        globalVars.delete(key);
                        await dbQuery(
                            'DELETE FROM bot_global_variables WHERE bot_id = ? AND var_key = ?',
                            [Number(botId), key]
                        );
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.delete_message': {
                    const deleteMode = String(cfg.delete_mode || 'by_var');

                    if (deleteMode === 'by_var') {
                        const varKey = String(cfg.var_name || '').trim();
                        if (varKey && messageVars.has(varKey)) {
                            await messageVars.get(varKey).delete().catch(() => {});
                            messageVars.delete(varKey);
                        }
                    } else if (deleteMode === 'by_id') {
                        const msgId  = resolveVariables(String(cfg.message_id || '').trim(), interaction, optionValues, localVars, globalVars);
                        const chId   = resolveVariables(String(cfg.channel_id  || '').trim(), interaction, optionValues, localVars, globalVars);

                        let targetChannel = interaction.channel;
                        if (chId) {
                            targetChannel = await interaction.client.channels.fetch(chId).catch(() => null) || interaction.channel;
                        }

                        if (targetChannel && msgId) {
                            const msg = await targetChannel.messages.fetch(msgId).catch(() => null);
                            if (msg) await msg.delete().catch(() => {});
                        }
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.message.react': {
                    const reactMode = String(cfg.react_mode || 'by_var');

                    // Support both legacy single `emoji` string and new `emojis` array
                    let emojisRaw = [];
                    if (Array.isArray(cfg.emojis) && cfg.emojis.length > 0) {
                        emojisRaw = cfg.emojis;
                    } else if (cfg.emoji && String(cfg.emoji).trim() !== '') {
                        emojisRaw = [String(cfg.emoji).trim()];
                    }

                    const emojis = emojisRaw
                        .map(e => resolveVariables(String(e).trim(), interaction, optionValues, localVars, globalVars))
                        .filter(Boolean);

                    let targetMsg = null;

                    if (reactMode === 'by_var') {
                        const varKey = String(cfg.var_name || '').trim();
                        if (varKey && messageVars.has(varKey)) {
                            targetMsg = messageVars.get(varKey);
                        }
                    } else if (reactMode === 'by_id') {
                        const msgId = resolveVariables(String(cfg.message_id || '').trim(), interaction, optionValues, localVars, globalVars);
                        const chId  = resolveVariables(String(cfg.channel_id  || '').trim(), interaction, optionValues, localVars, globalVars);

                        let targetChannel = interaction.channel;
                        if (chId) {
                            targetChannel = await interaction.client.channels.fetch(chId).catch(() => null) || interaction.channel;
                        }
                        if (targetChannel && msgId) {
                            targetMsg = await targetChannel.messages.fetch(msgId).catch(() => null);
                        }
                    }

                    if (targetMsg && emojis.length > 0) {
                        for (const emoji of emojis) {
                            await targetMsg.react(emoji).catch(() => {});
                        }
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.message.pin': {
                    const pinMode = String(cfg.pin_mode || 'by_var');

                    let targetMsg = null;

                    if (pinMode === 'by_var') {
                        const varKey = String(cfg.var_name || '').trim();
                        if (varKey && messageVars.has(varKey)) {
                            targetMsg = messageVars.get(varKey);
                        }
                    } else if (pinMode === 'by_id') {
                        const msgId = resolveVariables(String(cfg.message_id || '').trim(), interaction, optionValues, localVars, globalVars);
                        const chId  = resolveVariables(String(cfg.channel_id  || '').trim(), interaction, optionValues, localVars, globalVars);

                        let targetChannel = interaction.channel;
                        if (chId) {
                            targetChannel = await interaction.client.channels.fetch(chId).catch(() => null) || interaction.channel;
                        }
                        if (targetChannel && msgId) {
                            targetMsg = await targetChannel.messages.fetch(msgId).catch(() => null);
                        }
                    }

                    if (targetMsg) {
                        await targetMsg.pin().catch(() => {});
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'utility.error_handler':
                    // End of error path — stop execution
                    currentNode = null;
                    break;

                case 'action.http.request': {
                    const varName = String(cfg.var_name || '').trim();
                    const method  = String(cfg.method  || 'GET').toUpperCase();

                    const optVarsUrl     = cfg.opt_vars_url     !== false;
                    const optVarsParams  = cfg.opt_vars_params  !== false;
                    const optVarsHeaders = cfg.opt_vars_headers !== false;
                    const optVarsBody    = cfg.opt_vars_body    !== false;
                    const optExclude     = cfg.opt_exclude_empty !== false;
                    const optSanitize    = !!cfg.opt_sanitize;

                    const rv = (t) => resolveVariables(t, interaction, optionValues, localVars, globalVars);

                    // Build URL + params
                    let url = (optVarsUrl ? rv(String(cfg.url || '')) : String(cfg.url || '')).trim();
                    const params = Array.isArray(cfg.params) ? cfg.params : [];
                    const qsParts = [];
                    for (const p of params) {
                        const k = optVarsParams ? rv(String(p.key || ''))   : String(p.key || '');
                        const v = optVarsParams ? rv(String(p.value || '')) : String(p.value || '');
                        if (optExclude && (k === '' || v === '')) continue;
                        qsParts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                    }
                    if (qsParts.length > 0) url += (url.includes('?') ? '&' : '?') + qsParts.join('&');

                    // Build headers
                    const fetchHeaders = {};
                    const cfgHeaders = Array.isArray(cfg.headers) ? cfg.headers : [];
                    for (const h of cfgHeaders) {
                        const k = optVarsHeaders ? rv(String(h.key || ''))   : String(h.key || '');
                        const v = optVarsHeaders ? rv(String(h.value || '')) : String(h.value || '');
                        if (optExclude && (k === '' || v === '')) continue;
                        fetchHeaders[k] = v;
                    }

                    // Build body
                    let fetchBody = undefined;
                    const bodyStr = (optVarsBody ? rv(String(cfg.body || '')) : String(cfg.body || '')).trim();
                    if (bodyStr && method !== 'GET' && method !== 'DELETE') {
                        const bodyType = String(cfg.body_type || 'json');
                        if (bodyType === 'json' && !fetchHeaders['Content-Type']) {
                            fetchHeaders['Content-Type'] = 'application/json';
                        } else if (bodyType === 'form' && !fetchHeaders['Content-Type']) {
                            fetchHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
                        }
                        fetchBody = bodyStr;
                    }

                    let responseText = '';
                    let statusCode   = 0;
                    let statusText   = '';
                    try {
                        const res = await fetch(url, { method, headers: fetchHeaders, body: fetchBody });
                        statusCode = res.status;
                        statusText = res.statusText;
                        responseText = await res.text();
                        if (optSanitize) {
                            responseText = responseText.replace(/\{[^}]*\}/g, (m) => m.replace(/\{/g, '(').replace(/\}/g, ')'));
                        }
                    } catch (fetchErr) {
                        responseText = '';
                        statusCode   = 0;
                        statusText   = String(fetchErr instanceof Error ? fetchErr.message : fetchErr);
                    }

                    if (varName) {
                        localVars.set(varName + '.response',   responseText);
                        localVars.set(varName + '.status',     String(statusCode));
                        localVars.set(varName + '.statusText', statusText);
                    }

                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.utility.note':
                    // No-op: a visual note in the builder
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;

                case 'action.flow.loop.run': {
                    const loopMode  = String(cfg.mode     || 'count');
                    const varName   = String(cfg.var_name || 'loop').trim() || 'loop';
                    const rawCount  = parseInt(String(cfg.count    || '3'), 10);
                    const maxIter   = Math.min(Math.max(isNaN(rawCount) ? 3 : rawCount, 1), 50);
                    const listVar   = String(cfg.list_var || '').trim();
                    const idxKey    = `${varName}.index`;
                    const itemKey   = `${varName}.item`;
                    const countKey  = `${varName}.count`;
                    const bodyStart = getNextNode(currentNode.id, 'body');
                    const afterLoop = getNextNode(currentNode.id, 'next');

                    if (!bodyStart) {
                        currentNode = afterLoop;
                        break;
                    }

                    let items = [];
                    let count = maxIter;
                    if (loopMode === 'foreach') {
                        const raw = listVar
                            ? resolveVariables(`{${listVar}}`, interaction, optionValues, localVars, globalVars)
                            : '';
                        try {
                            const parsed = JSON.parse(raw);
                            items = Array.isArray(parsed) ? parsed : [parsed];
                        } catch (_) {
                            items = raw.split(',').map((s) => s.trim()).filter(Boolean);
                        }
                        count = Math.min(items.length, 50);
                    }

                    if (count <= 0) {
                        currentNode = afterLoop;
                        break;
                    }

                    // Set variables for first iteration
                    localVars.set(idxKey,   '0');
                    localVars.set(countKey, String(count));
                    if (loopMode === 'foreach' && items.length > 0) {
                        localVars.set(itemKey, String(items[0] ?? ''));
                    }

                    loopStack.push({
                        bodyStartNode: bodyStart,
                        nextAfterLoop: afterLoop,
                        maxIter:       count,
                        currentIter:   0,
                        mode:          loopMode,
                        items,
                        idxKey,
                        itemKey,
                        countKey,
                    });
                    currentNode = bodyStart;
                    break;
                }

                case 'action.flow.loop.stop': {
                    // Break out of the current loop
                    if (loopStack.length > 0) {
                        const ctx = loopStack.pop();
                        currentNode = ctx.nextAfterLoop;
                    } else {
                        currentNode = null;
                    }
                    break;
                }

                case 'action.music.loop_mode': {
                    const loopModeVal = String(cfg.mode || 'off');
                    const mq = getMusicQueue(botId, interaction.guildId);
                    if (mq) mq.setLoop(loopModeVal);
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.join': {
                    const vcChannelId = resolveVariables(String(cfg.channel_id || '').trim(), interaction, optionValues, localVars, globalVars);
                    if (vcChannelId) {
                        const vcChannel = await interaction.client.channels.fetch(vcChannelId).catch(() => null);
                        if (vcChannel && vcChannel.isVoiceBased()) {
                            const { joinVoiceChannel } = require('@discordjs/voice');
                            joinVoiceChannel({
                                channelId: vcChannel.id,
                                guildId: vcChannel.guild.id,
                                adapterCreator: vcChannel.guild.voiceAdapterCreator,
                            });
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.leave': {
                    const { getVoiceConnection } = require('@discordjs/voice');
                    const conn = getVoiceConnection(interaction.guildId);
                    if (conn) conn.destroy();
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.kick_member': {
                    const vcKickUserId = resolveVariables(String(cfg.user_id || '').trim(), interaction, optionValues, localVars, globalVars);
                    if (vcKickUserId) {
                        const vcKickMember = await interaction.guild.members.fetch(vcKickUserId).catch(() => null);
                        if (vcKickMember?.voice?.channel) {
                            await vcKickMember.voice.disconnect().catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.mute_member': {
                    const vcMuteUserId = resolveVariables(String(cfg.user_id || '').trim(), interaction, optionValues, localVars, globalVars);
                    const vcMuteVal = cfg.mute === true || cfg.mute === 'true';
                    if (vcMuteUserId) {
                        const vcMuteMember = await interaction.guild.members.fetch(vcMuteUserId).catch(() => null);
                        if (vcMuteMember?.voice?.channel) {
                            await vcMuteMember.voice.setMute(vcMuteVal).catch((e) => {
                                botLog(botId, 'error', `setMute failed: ${e.message}`, { user_id: vcMuteUserId });
                            });
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.deafen_member': {
                    const vcDeafUserId = resolveVariables(String(cfg.user_id || '').trim(), interaction, optionValues, localVars, globalVars);
                    const vcDeafVal = cfg.deafen === true || cfg.deafen === 'true';
                    if (vcDeafUserId) {
                        const vcDeafMember = await interaction.guild.members.fetch(vcDeafUserId).catch(() => null);
                        if (vcDeafMember?.voice?.channel) {
                            await vcDeafMember.voice.setDeaf(vcDeafVal).catch((e) => {
                                botLog(botId, 'error', `setDeaf failed: ${e.message}`, { user_id: vcDeafUserId });
                            });
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.vc.move_member': {
                    const vcMoveUserId  = resolveVariables(String(cfg.user_id    || '').trim(), interaction, optionValues, localVars, globalVars);
                    const vcMoveChId    = resolveVariables(String(cfg.channel_id || '').trim(), interaction, optionValues, localVars, globalVars);
                    if (vcMoveUserId && vcMoveChId) {
                        const vcMoveMember = await interaction.guild.members.fetch(vcMoveUserId).catch(() => null);
                        if (vcMoveMember) {
                            await vcMoveMember.voice.setChannel(vcMoveChId).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                default: {
                    const warnMsg = `Unknown node type "${currentNode.type}" — skipping.`;
                    console.warn('[custom-command-service]', warnMsg);
                    botLog(botId, 'warn', warnMsg, { node_id: currentNode.id, command: slashName });
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }
            }
        } catch (error) {
            const errMsg = error?.message || String(error);
            console.error('[custom-command-service] Flow error:', errMsg);
            botLog(botId, 'error', errMsg, {
                node_id: currentNode?.id,
                node_type: currentNode?.type,
                command: slashName,
            });
            const errorNode = getNextNode(currentNode.id, 'error');
            if (errorNode) {
                currentNode = errorNode;
            } else {
                throw error;
            }
        }
    }

    if (!replied && !interaction.replied && !interaction.deferred) {
        await interaction.reply({ content: 'Command ausgeführt.', ephemeral: cmdEphemeral });
    }
}

// Per-command cooldown storage: Map<cmdId, Map<key, expireAt>>
const _cooldownStore = new Map();

function buildCustomCommandObject(cmd, botId) {
    // Pull settings from trigger node config
    const triggerNode = (() => {
        try {
            const bd = cmd.builderData;
            if (bd && Array.isArray(bd.nodes)) return bd.nodes.find((n) => n.type === 'trigger.slash') || null;
        } catch (_) {}
        return null;
    })();

    const cmdEphemeral    = triggerNode?.config?.ephemeral === '1' || triggerNode?.config?.ephemeral === true;
    const cooldownType    = String(triggerNode?.config?.cooldown_type   || 'none');
    const cooldownSeconds = Math.max(1, parseInt(triggerNode?.config?.cooldown_seconds || '10', 10) || 10);
    const requiredPermBits = permNamesToBigInt(triggerNode?.config?.required_permissions);

    if (!_cooldownStore.has(cmd.id)) _cooldownStore.set(cmd.id, new Map());
    const cooldownMap = _cooldownStore.get(cmd.id);

    return {
        key: cmd.slashName,
        data: buildSlashCommandData(cmd),
        isCustom: true,
        customCommandId: cmd.id,
        async execute(interaction) {
            if (!cmd.builderData) {
                await interaction.reply({
                    content: 'Dieser Command hat noch keinen Builder-Flow.',
                    ephemeral: true,
                });
                return;
            }

            // ── Permission check (server-side, guards against API/client bypass) ──
            if (requiredPermBits !== null && interaction.inGuild()) {
                const memberPerms = interaction.memberPermissions;
                if (!memberPerms || !memberPerms.has(requiredPermBits)) {
                    await interaction.reply({
                        content: '🚫 Du hast nicht die benötigten Berechtigungen für diesen Command.',
                        ephemeral: true,
                    });
                    return;
                }
            }
            // ─────────────────────────────────────────────────────────────────

            // ── Cooldown check ────────────────────────────────────────────────
            if (cooldownType !== 'none') {
                const cooldownKey = cooldownType === 'user'
                    ? `u:${interaction.user.id}`
                    : `s:${interaction.guildId || 'dm'}`;
                const now = Date.now();
                const expiry = cooldownMap.get(cooldownKey) || 0;
                if (now < expiry) {
                    const remaining = ((expiry - now) / 1000).toFixed(1);
                    await interaction.reply({
                        content: `⏳ Bitte warte noch **${remaining}s** bevor du diesen Command erneut verwendest.`,
                        ephemeral: true,
                    });
                    return;
                }
                cooldownMap.set(cooldownKey, now + cooldownSeconds * 1000);
            }
            // ─────────────────────────────────────────────────────────────────

            await executeFlowGraph(cmd.builderData, interaction, botId, cmd.slashName, null, cmdEphemeral);
        },
    };
}

async function loadCustomCommandRegistry(botId) {
    const cmds = await loadCustomCommands(botId);
    const registry = new Map();

    for (const cmd of cmds) {
        registry.set(cmd.slashName, buildCustomCommandObject(cmd, botId));
    }

    console.log(
        `[custom-command-service] Loaded ${registry.size} custom command(s) for bot ${botId}.`
    );

    return registry;
}

module.exports = {
    loadCustomCommands,
    loadCustomCommandRegistry,
    buildSlashCommandData,
};
