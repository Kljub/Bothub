const type = 'action.channel.edit';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const channelId = rv(String(cfg.channel_id || '').trim());
    if (channelId && interaction.client) {
        const ch = await interaction.client.channels.fetch(channelId).catch(() => null);
        if (ch) {
            const opts  = {};
            const name  = rv(String(cfg.name  || '').trim());
            const topic = rv(String(cfg.topic || '').trim());
            if (name)  opts.name  = name;
            if (topic) opts.topic = topic;
            if (Object.keys(opts).length > 0) await ch.edit(opts).catch(() => {});
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
