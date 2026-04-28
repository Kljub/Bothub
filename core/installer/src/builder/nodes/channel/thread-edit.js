const type = 'action.thread.edit';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const threadId = rv(String(cfg.thread_id || '').trim());
    if (threadId && interaction.client) {
        const thread = await interaction.client.channels.fetch(threadId).catch(() => null);
        if (thread) {
            const name = rv(String(cfg.name || '').trim());
            if (name) await thread.edit({ name }).catch(() => {});
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
