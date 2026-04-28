const type = 'action.flow.loop.stop';

async function execute(ctx) {
    const { cfg, loopStack } = ctx;
    const breakDepth = Math.max(1, parseInt(String(cfg.break_depth || '1'), 10) || 1);

    let lastCtx = null;
    for (let d = 0; d < breakDepth && loopStack.length > 0; d++) {
        lastCtx = loopStack.pop();
    }
    ctx.currentNode = lastCtx ? lastCtx.nextAfterLoop : null;
}

module.exports = { type, execute };
