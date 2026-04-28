const type = 'action.vc.kick_member';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId = rv(String(cfg.user_id || '').trim());
    if (userId) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member?.voice?.channel) await member.voice.disconnect().catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
