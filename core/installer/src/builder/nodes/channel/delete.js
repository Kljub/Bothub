const type = 'action.channel.delete';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const channelId = rv(String(cfg.channel_id || '').trim());
    if (channelId && interaction.client) {
        const ch = await interaction.client.channels.fetch(channelId).catch(() => null);
        if (ch) await ch.delete().catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
