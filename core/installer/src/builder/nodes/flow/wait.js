const type = 'action.flow.wait';
const aliases = ['action.wait'];

async function execute(ctx) {
    const { cfg, node, getNextNode } = ctx;
    const ms = Math.min(Math.max(Number(cfg.duration_ms || cfg.ms || 1000), 100), 14000);
    await new Promise((resolve) => setTimeout(resolve, ms));
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, aliases, execute };
