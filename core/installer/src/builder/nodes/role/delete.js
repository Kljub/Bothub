const type = 'action.role.delete';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const roleId = rv(String(cfg.role_id || '').trim());
    if (roleId && interaction.guild) {
        const role = await interaction.guild.roles.fetch(roleId).catch(() => null);
        if (role) await role.delete().catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
