const type = 'condition.comparison';

function evalCond(cond, rv) {
    const base = rv(String(cond.base_value       || ''));
    const comp = rv(String(cond.comparison_value || ''));
    const op   = String(cond.operator || '==');
    const bNum = parseFloat(base);
    const cNum = parseFloat(comp);
    const num  = !isNaN(bNum) && !isNaN(cNum);

    switch (op) {
        case '<':                    return num ? bNum < cNum  : base <  comp;
        case '<=':                   return num ? bNum <= cNum : base <= comp;
        case '>':                    return num ? bNum > cNum  : base >  comp;
        case '>=':                   return num ? bNum >= cNum : base >= comp;
        case '==':                   return base === comp || (num && bNum === cNum);
        case '!=':                   return base !== comp && (!num || bNum !== cNum);
        case 'contains':             return base.includes(comp);
        case 'not_contains':         return !base.includes(comp);
        case 'starts_with':          return base.startsWith(comp);
        case 'ends_with':            return base.endsWith(comp);
        case 'not_starts_with':      return !base.startsWith(comp);
        case 'not_ends_with':        return !base.endsWith(comp);
        case 'collection_contains': {
            try { const a = JSON.parse(base); return Array.isArray(a) && a.map(String).includes(comp); } catch (_) { return false; }
        }
        case 'collection_not_contains': {
            try { const a = JSON.parse(base); return !(Array.isArray(a) && a.map(String).includes(comp)); } catch (_) { return true; }
        }
        default: return false;
    }
}

async function execute(ctx) {
    const { cfg, node, getNextNode, rv } = ctx;
    const conds = Array.isArray(cfg.conditions) ? cfg.conditions : [];

    let matched = false;
    for (let i = 0; i < conds.length; i++) {
        if (evalCond(conds[i], rv)) {
            ctx.currentNode = getNextNode(node.id, 'cond_' + i);
            matched = true;
            break;
        }
    }
    if (!matched) ctx.currentNode = getNextNode(node.id, 'else');
}

module.exports = { type, execute };
