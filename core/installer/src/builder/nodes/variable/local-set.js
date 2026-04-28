const type = 'variable.local.set';

async function execute(ctx) {
    const { cfg, node, getNextNode, localVars, rv } = ctx;
    const key = String(cfg.var_key || '').trim();
    if (key) localVars.set(key, rv(String(cfg.var_value || '')));
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
