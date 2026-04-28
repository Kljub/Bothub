const type = 'action.flow.loop.run';

async function execute(ctx) {
    const { cfg, node, getNextNode, localVars, loopStack, rv } = ctx;

    const loopMode = String(cfg.mode     || 'count');
    const varName  = String(cfg.var_name || 'loop').trim() || 'loop';
    const rawCount = parseInt(String(cfg.count || '3'), 10);
    const maxIter  = Math.min(Math.max(isNaN(rawCount) ? 3 : rawCount, 1), 50);
    const listVar  = String(cfg.list_var || '').trim();
    const idxKey   = `${varName}.index`;
    const itemKey  = `${varName}.item`;
    const countKey = `${varName}.count`;

    const bodyStart = getNextNode(node.id, 'body');
    const afterLoop = getNextNode(node.id, 'next');

    if (!bodyStart) {
        ctx.currentNode = afterLoop;
        return;
    }

    let items = [];
    let count = maxIter;

    if (loopMode === 'foreach') {
        const raw = listVar ? rv(`{${listVar}}`) : '';
        try {
            const parsed = JSON.parse(raw);
            items = Array.isArray(parsed) ? parsed : [parsed];
        } catch (_) {
            items = raw.split(',').map((s) => s.trim()).filter(Boolean);
        }
        count = Math.min(items.length, 50);
    }

    if (count <= 0) {
        ctx.currentNode = afterLoop;
        return;
    }

    localVars.set(idxKey,   '0');
    localVars.set(countKey, String(count));
    if (loopMode === 'foreach' && items.length > 0) {
        localVars.set(itemKey, String(items[0] ?? ''));
    }

    loopStack.push({
        bodyStartNode: bodyStart,
        nextAfterLoop: afterLoop,
        maxIter:       count,
        currentIter:   0,
        mode:          loopMode,
        items,
        idxKey,
        itemKey,
        countKey,
    });

    ctx.currentNode = bodyStart;
}

module.exports = { type, execute };
