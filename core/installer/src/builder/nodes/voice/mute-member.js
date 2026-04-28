const type = 'action.vc.mute_member';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, botId, botLog } = ctx;
    const userId   = rv(String(cfg.user_id || '').trim());
    const muteVal  = cfg.mute === true || cfg.mute === 'true';
    if (userId) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member?.voice?.channel) {
            await member.voice.setMute(muteVal).catch((e) => {
                botLog(botId, 'error', `setMute failed: ${e.message}`, { user_id: userId });
            });
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
