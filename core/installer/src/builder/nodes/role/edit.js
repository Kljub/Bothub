const type = 'action.role.edit';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const roleId = rv(String(cfg.role_id || '').trim());
    if (roleId && interaction.guild) {
        const role = await interaction.guild.roles.fetch(roleId).catch(() => null);
        if (role) {
            const opts = {};
            const name  = rv(String(cfg.name  || '').trim());
            const color = rv(String(cfg.color || '').trim());
            if (name)                           opts.name  = name;
            if (/^#[0-9a-f]{6}$/i.test(color)) opts.color = color;
            if (Object.keys(opts).length > 0) await role.edit(opts).catch(() => {});
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
