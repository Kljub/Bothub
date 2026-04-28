const type = 'condition.user';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId = rv(String(cfg.user_id || '').trim());
    const match  = userId && interaction.user.id === userId;
    ctx.currentNode = getNextNode(node.id, match ? 'true' : 'false');
}

module.exports = { type, execute };
