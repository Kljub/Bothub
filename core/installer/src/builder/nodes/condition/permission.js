const { PermissionFlagsBits } = require('discord.js');

const type = 'condition.permission';

async function execute(ctx) {
    const { cfg, node, getNextNode, interaction } = ctx;
    const permName = String(cfg.permission || '').trim();

    let hasPerm = false;
    if (permName && interaction.inGuild && interaction.inGuild()) {
        const bit = PermissionFlagsBits[permName];
        if (bit !== undefined) hasPerm = !!interaction.memberPermissions?.has(bit);
    }

    ctx.currentNode = getNextNode(node.id, hasPerm ? 'true' : 'false');
}

module.exports = { type, execute };
