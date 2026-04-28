const type = 'condition.chance';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv } = ctx;
    const percent = Math.min(100, Math.max(0, parseFloat(rv(String(cfg.percent || '50'))) || 50));
    const hit     = Math.random() * 100 < percent;
    ctx.currentNode = getNextNode(node.id, hit ? 'true' : 'false');
}

module.exports = { type, execute };
