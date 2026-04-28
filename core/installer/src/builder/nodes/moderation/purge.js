const type = 'action.mod.purge';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const amount    = Math.min(100, Math.max(1, parseInt(rv(String(cfg.amount || '10'))) || 10));
    const channelId = rv(String(cfg.channel_id || '').trim());

    let ch = interaction.channel;
    if (channelId && interaction.client) {
        ch = await interaction.client.channels.fetch(channelId).catch(() => null) || ch;
    }
    if (ch && ch.isTextBased()) await ch.bulkDelete(amount, true).catch(() => {});

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
