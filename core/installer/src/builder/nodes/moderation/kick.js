const type = 'action.mod.kick';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId = rv(String(cfg.user_id || '').trim());
    const reason = rv(String(cfg.reason  || '').trim());

    if (userId && interaction.guild) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member) await member.kick(reason || undefined).catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
