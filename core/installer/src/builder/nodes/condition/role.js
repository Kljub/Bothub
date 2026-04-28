const type = 'condition.role';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const roleId = rv(String(cfg.role_id || '').trim());

    let hasRole = false;
    if (roleId && interaction.member) {
        const roleIds = interaction.member?.roles?.cache
            ? [...interaction.member.roles.cache.keys()]
            : (Array.isArray(interaction.member?.roles) ? interaction.member.roles : []);
        hasRole = roleIds.includes(roleId);
    }

    ctx.currentNode = getNextNode(node.id, hasRole ? 'true' : 'false');
}

module.exports = { type, execute };
