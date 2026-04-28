// Builder flow runner — executes a node graph for a custom command interaction.

const { resolveVariables, buildEmbeds, buildMessageComponents } = require('./helpers');
const { loadHandlers, getHandler } = require('./registry');
const { dbQuery, botLog } = require('../db');

loadHandlers();

const MAX_STEPS = 300;

async function executeFlowGraph(builderData, interaction, botId, slashName = '', startNodeId = null, cmdEphemeral = false) {
    if (!builderData || !Array.isArray(builderData.nodes)) {
        await interaction.reply({ content: 'Dieser Command ist noch nicht konfiguriert.', ephemeral: true });
        return;
    }

    const nodes    = builderData.nodes;
    const edges    = Array.isArray(builderData.edges) ? builderData.edges : [];
    const nodeMap  = new Map(nodes.map((n) => [n.id, n]));
    const optionValues = _collectOptionValues(interaction);

    const localVars  = new Map();
    const globalVars = new Map();
    const messageVars = new Map();
    const loopStack   = [];

    // Preload global variables
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
        } catch (_) {}
    }

    const triggerNode = nodes.find((n) => n.type === 'trigger.slash');
    if (!triggerNode) {
        await interaction.reply({ content: 'Kein Trigger-Node gefunden.', ephemeral: true });
        return;
    }

    // Allowed-roles check
    const allowedRoles    = Array.isArray(triggerNode.config?.allowed_roles) ? triggerNode.config.allowed_roles : [{ id: 'everyone' }];
    const hasEveryoneEntry = allowedRoles.some((r) => r.id === 'everyone');

    if (!hasEveryoneEntry && allowedRoles.length > 0) {
        const member       = interaction.member;
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

    function getNextNode(fromNodeId, fromPort) {
        const edge = edges.find((e) => e.from_node_id === fromNodeId && e.from_port === fromPort);
        return edge ? (nodeMap.get(edge.to_node_id) || null) : null;
    }

    // Shared execution context passed to every node handler
    const ctx = {
        interaction,
        botId,
        slashName,
        optionValues,
        localVars,
        globalVars,
        messageVars,
        loopStack,
        nodes,
        edges,
        replied: false,
        currentNode: startNodeId
            ? (nodeMap.get(startNodeId) ? getNextNode(startNodeId, 'next') : null)
            : getNextNode(triggerNode.id, 'next'),

        getNextNode,
        rv: (t) => resolveVariables(t, interaction, optionValues, localVars, globalVars),
        buildEmbeds:           (cfgs) => buildEmbeds(cfgs, interaction, optionValues, localVars, globalVars),
        buildMessageComponents:(nodeId) => buildMessageComponents(nodeId, edges, nodes, botId, slashName),
        dbQuery,
        botLog,
    };

    let steps = 0;

    while (true) {
        // Loop-stack advancement when a branch ends (currentNode null)
        if (ctx.currentNode === null && loopStack.length > 0) {
            const loopCtx = loopStack[loopStack.length - 1];
            loopCtx.currentIter++;
            if (loopCtx.currentIter < loopCtx.maxIter) {
                const idxKey  = loopCtx.idxKey  || 'loop.index';
                const itemKey = loopCtx.itemKey || 'loop.item';
                localVars.set(idxKey, String(loopCtx.currentIter));
                if (loopCtx.mode === 'foreach' && loopCtx.items) {
                    localVars.set(itemKey, String(loopCtx.items[loopCtx.currentIter] ?? ''));
                }
                ctx.currentNode = loopCtx.bodyStartNode;
            } else {
                loopStack.pop();
                ctx.currentNode = loopCtx.nextAfterLoop;
            }
            continue;
        }

        if (ctx.currentNode === null || steps >= MAX_STEPS) break;
        steps++;

        ctx.node = ctx.currentNode;
        ctx.cfg  = ctx.currentNode.config || {};

        try {
            const handler = getHandler(ctx.currentNode.type);
            if (handler) {
                await handler(ctx);
            } else {
                const warnMsg = `Unknown node type "${ctx.currentNode.type}" — skipping.`;
                console.warn('[builder/runner]', warnMsg);
                botLog(botId, 'warn', warnMsg, { node_id: ctx.currentNode.id, command: slashName });
                ctx.currentNode = getNextNode(ctx.currentNode.id, 'next');
            }
        } catch (error) {
            const errMsg = error?.message || String(error);
            console.error('[builder/runner] Flow error:', errMsg);
            botLog(botId, 'error', errMsg, {
                node_id:   ctx.currentNode?.id,
                node_type: ctx.currentNode?.type,
                command:   slashName,
            });
            const errorNode = getNextNode(ctx.currentNode?.id, 'error');
            if (errorNode) {
                ctx.currentNode = errorNode;
            } else {
                if (error && typeof error === 'object') error._bhLogged = true;
                throw error;
            }
        }
    }

    if (!ctx.replied && !interaction.replied && !interaction.deferred) {
        await interaction.reply({ content: 'Command ausgeführt.', ephemeral: cmdEphemeral });
    }
}

function _collectOptionValues(interaction) {
    const values = new Map();
    if (!interaction.options) return values;
    try {
        const data = interaction.options.data;
        if (Array.isArray(data)) {
            for (const opt of data) {
                if (opt && opt.name) values.set(String(opt.name), opt.value ?? null);
            }
        }
    } catch (_) {}
    return values;
}

module.exports = { executeFlowGraph };
