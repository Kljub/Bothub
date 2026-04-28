const type = 'condition.channel';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const channelId = rv(String(cfg.channel_id || '').trim());
    const match     = channelId && interaction.channel?.id === channelId;
    ctx.currentNode = getNextNode(node.id, match ? 'true' : 'false');
}

module.exports = { type, execute };
