const type = 'action.mod.ban';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const userId     = rv(String(cfg.user_id     || '').trim());
    const reason     = rv(String(cfg.reason      || '').trim());
    const deleteDays = Math.min(7, Math.max(0, parseInt(rv(String(cfg.delete_days || '0'))) || 0));

    if (userId && interaction.guild) {
        await interaction.guild.members.ban(userId, {
            deleteMessageSeconds: deleteDays * 86400,
            ...(reason ? { reason } : {}),
        }).catch(() => {});
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
