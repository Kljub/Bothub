const type = 'action.vc.deafen_member';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, botId, botLog } = ctx;
    const userId   = rv(String(cfg.user_id || '').trim());
    const deafVal  = cfg.deafen === true || cfg.deafen === 'true';
    if (userId) {
        const member = await interaction.guild.members.fetch(userId).catch(() => null);
        if (member?.voice?.channel) {
            await member.voice.setDeaf(deafVal).catch((e) => {
                botLog(botId, 'error', `setDeaf failed: ${e.message}`, { user_id: userId });
            });
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
