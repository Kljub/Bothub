const type = 'variable.global.set';

async function execute(ctx) {
    const { cfg, node, getNextNode, globalVars, rv, dbQuery, botId } = ctx;
    const key = String(cfg.var_key || '').trim();
    if (key && botId) {
        const value = rv(String(cfg.var_value || ''));
        globalVars.set(key, value);
        await dbQuery(
            `INSERT INTO bot_global_variables (bot_id, var_key, var_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE var_value = VALUES(var_value)`,
            [Number(botId), key, value]
        );
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
