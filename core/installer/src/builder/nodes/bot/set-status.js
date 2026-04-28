const { ActivityType } = require('discord.js');

const type = 'action.bot.set_status';

const ACTIVITY_TYPE_MAP = {
    Playing:    ActivityType.Playing,
    Streaming:  ActivityType.Streaming,
    Listening:  ActivityType.Listening,
    Watching:   ActivityType.Watching,
    Competing:  ActivityType.Competing,
};

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const status       = String(cfg.status        || 'online');
    const activityType = ACTIVITY_TYPE_MAP[String(cfg.activity_type || '')] ?? null;
    const activityText = rv(String(cfg.activity_text || '').trim());

    const presenceOpts = { status };
    if (activityType !== null && activityText) {
        presenceOpts.activities = [{ name: activityText, type: activityType }];
    } else {
        presenceOpts.activities = [];
    }

    interaction.client.user.setPresence(presenceOpts);
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
