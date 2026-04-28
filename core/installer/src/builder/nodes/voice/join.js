const type = 'action.vc.join';

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction } = ctx;
    const vcChannelId = rv(String(cfg.channel_id || '').trim());
    if (vcChannelId) {
        const vcChannel = await interaction.client.channels.fetch(vcChannelId).catch(() => null);
        if (vcChannel && vcChannel.isVoiceBased()) {
            const { joinVoiceChannel } = require('@discordjs/voice');
            joinVoiceChannel({
                channelId:       vcChannel.id,
                guildId:         vcChannel.guild.id,
                adapterCreator:  vcChannel.guild.voiceAdapterCreator,
            });
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
