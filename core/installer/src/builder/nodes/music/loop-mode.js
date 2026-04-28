const type = 'action.music.loop_mode';

async function execute(ctx) {
    const { cfg, node, getNextNode, interaction, botId } = ctx;
    const { getQueue: getMusicQueue } = require('../../services/music-service');
    const loopMode = String(cfg.mode || 'off');
    const mq = getMusicQueue(botId, interaction.guildId);
    if (mq) mq.setLoop(loopMode);
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
