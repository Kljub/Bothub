const type = 'action.message.react';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, messageVars } = ctx;
    const reactMode = String(cfg.react_mode || 'by_var');

    let emojisRaw = [];
    if (Array.isArray(cfg.emojis) && cfg.emojis.length > 0) {
        emojisRaw = cfg.emojis;
    } else if (cfg.emoji && String(cfg.emoji).trim() !== '') {
        emojisRaw = [String(cfg.emoji).trim()];
    }
    const emojis = emojisRaw.map(e => rv(String(e).trim())).filter(Boolean);

    let targetMsg = null;
    if (reactMode === 'by_var') {
        const varKey = String(cfg.var_name || '').trim();
        if (varKey && messageVars.has(varKey)) targetMsg = messageVars.get(varKey);
    } else if (reactMode === 'by_id') {
        const msgId = rv(String(cfg.message_id || '').trim());
        const chId  = rv(String(cfg.channel_id  || '').trim());
        let ch = interaction.channel;
        if (chId) ch = await interaction.client.channels.fetch(chId).catch(() => null) || ch;
        if (ch && msgId) targetMsg = await ch.messages.fetch(msgId).catch(() => null);
    }

    if (targetMsg && emojis.length > 0) {
        for (const emoji of emojis) await targetMsg.react(emoji).catch(() => {});
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
