const { ChannelType } = require('discord.js');

const type = 'action.channel.create';

const CHANNEL_TYPE_MAP = {
    text:         ChannelType.GuildText,
    voice:        ChannelType.GuildVoice,
    category:     ChannelType.GuildCategory,
    announcement: ChannelType.GuildAnnouncement,
};

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, localVars } = ctx;
    const name      = rv(String(cfg.name || 'new-channel').trim()).toLowerCase().replace(/\s+/g, '-');
    const chType    = CHANNEL_TYPE_MAP[String(cfg.type || 'text')] ?? ChannelType.GuildText;
    const resultVar = String(cfg.result_var || '').trim();

    if (interaction.guild) {
        const ch = await interaction.guild.channels.create({ name, type: chType }).catch(() => null);
        if (ch && resultVar) {
            localVars.set(resultVar,           ch.id);
            localVars.set(resultVar + '.id',   ch.id);
            localVars.set(resultVar + '.name', ch.name);
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
