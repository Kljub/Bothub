const type = 'action.vc.move_member';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId    = rv(String(cfg.user_id    || '').trim());
    const channelId = rv(String(cfg.channel_id || '').trim());
    if (userId && channelId) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member) await member.voice.setChannel(channelId).catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
