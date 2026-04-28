const type = 'variable.global.delete';

async function execute(ctx) {
    const { cfg, node, getNextNode, globalVars, dbQuery, botId } = ctx;
    const key = String(cfg.var_key || '').trim();
    if (key && botId) {
        globalVars.delete(key);
        await dbQuery('DELETE FROM bot_global_variables WHERE bot_id = ? AND var_key = ?', [Number(botId), key]);
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
