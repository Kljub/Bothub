const type = 'action.role.add_to_member';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId  = rv(String(cfg.user_id  || '').trim());
    const roleIds = rv(String(cfg.role_ids || '').trim()).split(',').map(s => s.trim()).filter(Boolean);

    if (userId && roleIds.length > 0) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member) {
            for (const roleId of roleIds) await member.roles.add(roleId).catch(() => {});
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
