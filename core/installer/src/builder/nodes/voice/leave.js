const type = 'action.vc.leave';

async function execute(ctx) {
    const { cfg, node, getNextNode, interaction, botId } = ctx;
    const { getVoiceConnection } = require('@discordjs/voice');
    const { getQueue: getMusicQueue } = require('../../services/music-service');

    const force = cfg.force === true || cfg.force === 'true';
    const conn  = getVoiceConnection(interaction.guildId);
    if (conn) {
        const mq = getMusicQueue && getMusicQueue(botId, interaction.guildId);
        if (force && mq) mq.stop();
        conn.destroy();
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
