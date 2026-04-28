const type = 'action.message.pin';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, messageVars } = ctx;
    const pinMode = String(cfg.pin_mode || 'by_var');

    let targetMsg = null;
    if (pinMode === 'by_var') {
        const varKey = String(cfg.var_name || '').trim();
        if (varKey && messageVars.has(varKey)) targetMsg = messageVars.get(varKey);
    } else if (pinMode === 'by_id') {
        const msgId = rv(String(cfg.message_id || '').trim());
        const chId  = rv(String(cfg.channel_id  || '').trim());
        let ch = interaction.channel;
        if (chId) ch = await interaction.client.channels.fetch(chId).catch(() => null) || ch;
        if (ch && msgId) targetMsg = await ch.messages.fetch(msgId).catch(() => null);
    }

    if (targetMsg) await targetMsg.pin().catch(() => {});

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
