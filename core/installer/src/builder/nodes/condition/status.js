const type = 'condition.status';

async function execute(ctx) {
    const { cfg, node, getNextNode, interaction } = ctx;
    const targetStatus = String(cfg.status || 'online').toLowerCase();
    const member       = interaction.member;
    const presence     = member && typeof member.joinedAt !== 'undefined' ? (member.presence || null) : null;
    const current      = presence ? (presence.status || 'offline').toLowerCase() : 'offline';
    ctx.currentNode    = getNextNode(node.id, current === targetStatus ? 'true' : 'false');
}

module.exports = { type, execute };
