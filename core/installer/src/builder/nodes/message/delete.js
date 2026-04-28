const type = 'action.delete_message';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, messageVars } = ctx;
    const deleteMode = String(cfg.delete_mode || 'by_var');

    if (deleteMode === 'by_var') {
        const varKey = String(cfg.var_name || '').trim();
        if (varKey && messageVars.has(varKey)) {
            await messageVars.get(varKey).delete().catch(() => {});
            messageVars.delete(varKey);
        }
    } else if (deleteMode === 'by_id') {
        const msgId = rv(String(cfg.message_id || '').trim());
        const chId  = rv(String(cfg.channel_id  || '').trim());

        let targetChannel = interaction.channel;
        if (chId) targetChannel = await interaction.client.channels.fetch(chId).catch(() => null) || interaction.channel;

        if (targetChannel && msgId) {
            const msg = await targetChannel.messages.fetch(msgId).catch(() => null);
            if (msg) await msg.delete().catch(() => {});
        }
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
