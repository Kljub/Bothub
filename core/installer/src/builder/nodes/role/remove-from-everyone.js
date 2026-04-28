const type = 'action.role.remove_from_everyone';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const roleIds = rv(String(cfg.role_ids || '').trim()).split(',').map(s => s.trim()).filter(Boolean);
    if (roleIds.length > 0 && interaction.guild) {
        const members = await interaction.guild.members.fetch().catch(() => null);
        if (members) {
            for (const [, member] of members) {
                for (const roleId of roleIds) await member.roles.remove(roleId).catch(() => {});
            }
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
