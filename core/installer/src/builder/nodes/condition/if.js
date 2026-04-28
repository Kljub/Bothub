const type = 'condition.if';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv } = ctx;

    const left  = rv(String(cfg.left_value  || ''));
    const right = rv(String(cfg.right_value || ''));
    const op    = String(cfg.operator || 'equals');

    const lNum = parseFloat(left);
    const rNum = parseFloat(right);
    const num  = !isNaN(lNum) && !isNaN(rNum);

    let result = false;
    switch (op) {
        case 'equals':       result = left === right || (num && lNum === rNum); break;
        case 'not_equals':   result = left !== right && (!num || lNum !== rNum); break;
        case 'contains':     result = left.includes(right); break;
        case 'greater_than': result = num ? lNum > rNum : left > right; break;
        case 'less_than':    result = num ? lNum < rNum : left < right; break;
        default:             result = left === right;
    }

    ctx.currentNode = getNextNode(node.id, result ? 'true' : 'false');
}

module.exports = { type, execute };
