const type = 'action.server.create_invite';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, localVars } = ctx;
    const maxAge  = Math.max(0, parseInt(rv(String(cfg.max_age  || '86400'))) || 86400);
    const maxUses = Math.max(0, parseInt(rv(String(cfg.max_uses || '0')))    || 0);
    const resultVar = String(cfg.result_var || '').trim();

    if (interaction.channel) {
        const invite = await interaction.channel.createInvite({ maxAge, maxUses }).catch(() => null);
        if (invite && resultVar) {
            localVars.set(resultVar,          invite.url);
            localVars.set(resultVar + '.url', invite.url);
            localVars.set(resultVar + '.code', invite.code);
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
