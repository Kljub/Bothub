const type = 'action.role.create';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, localVars } = ctx;
    const name      = rv(String(cfg.name  || 'New Role').trim());
    const color     = rv(String(cfg.color || '').trim());
    const hoist     = cfg.hoist === true || cfg.hoist === 'true';
    const resultVar = String(cfg.result_var || '').trim();

    if (interaction.guild) {
        const opts = { name, hoist };
        if (/^#[0-9a-f]{6}$/i.test(color)) opts.color = color;

        const role = await interaction.guild.roles.create(opts).catch(() => null);
        if (role && resultVar) {
            localVars.set(resultVar,          role.id);
            localVars.set(resultVar + '.id',  role.id);
            localVars.set(resultVar + '.name', role.name);
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
