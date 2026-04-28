const type = 'action.mod.timeout';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId   = rv(String(cfg.user_id  || '').trim());
    const reason   = rv(String(cfg.reason   || '').trim());
    // duration in seconds (default 60), capped at 28 days
    const seconds  = Math.min(28 * 24 * 3600, Math.max(1, parseInt(rv(String(cfg.duration || '60'))) || 60));

    if (userId && interaction.guild) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member) await member.timeout(seconds * 1000, reason || undefined).catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
