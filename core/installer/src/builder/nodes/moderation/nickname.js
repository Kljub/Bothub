const type = 'action.mod.nickname';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId   = rv(String(cfg.user_id   || '').trim());
    const nickname = rv(String(cfg.nickname  || '').trim());

    if (userId && interaction.guild) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member) await member.setNickname(nickname || null).catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
