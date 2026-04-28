const type = 'action.message.publish';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, messageVars } = ctx;

    let publishMsg = null;
    if (cfg.publish_mode === 'by_id') {
        const msgId = rv(String(cfg.message_id || '').trim());
        const chId  = rv(String(cfg.channel_id  || '').trim());
        if (msgId && chId) {
            const ch = await interaction.client.channels.fetch(chId).catch(() => null);
            if (ch) publishMsg = await ch.messages.fetch(msgId).catch(() => null);
        }
    } else {
        const nodeId = String(cfg.target_message_node_id || '').trim();
        if (nodeId) publishMsg = messageVars.get(nodeId) || null;
    }

    if (publishMsg && typeof publishMsg.crosspost === 'function') {
        await publishMsg.crosspost().catch(() => {});
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
