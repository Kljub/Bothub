const { ActionRowBuilder } = require('discord.js');

const type = 'action.message.edit_component';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, messageVars } = ctx;

    let editMsg = null;
    if (cfg.edit_mode === 'by_id') {
        const msgId = rv(String(cfg.message_id || '').trim());
        const chId  = rv(String(cfg.channel_id  || '').trim());
        if (msgId && chId) {
            const ch = await interaction.client.channels.fetch(chId).catch(() => null);
            if (ch) editMsg = await ch.messages.fetch(msgId).catch(() => null);
        }
    } else {
        const nodeId = String(cfg.target_message_node_id || '').trim();
        if (nodeId) editMsg = messageVars.get(nodeId) || null;
    }

    if (editMsg) {
        const targetId  = rv(String(cfg.component_custom_id || '').trim());
        const ecAction  = String(cfg.component_action || 'disable');
        const newComponents = editMsg.components.map((row) => {
            const newRow = ActionRowBuilder.from(row);
            newRow.components = row.components.map((comp) => {
                if (comp.customId !== targetId) return comp;
                const built = comp.toJSON ? { ...comp.toJSON() } : { ...comp };
                if (ecAction === 'disable')   built.disabled = true;
                if (ecAction === 'enable')    built.disabled = false;
                if (ecAction === 'set_label') built.label = rv(String(cfg.new_label || '').trim());
                return built;
            });
            return newRow;
        });
        await editMsg.edit({ components: newComponents }).catch(() => {});
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
