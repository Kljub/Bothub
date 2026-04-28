const type = 'action.server.leave';

async function execute(ctx) {
    const { cfg, node, getNextNode, interaction } = ctx;
    if (cfg.confirm === true || cfg.confirm === 'true') {
        await interaction.guild.leave().catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
