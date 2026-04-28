const type = 'action.send_message';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv } = ctx;
    const content   = rv(String(cfg.content || ''));
    const ephemeral = !!cfg.ephemeral;
    const tts       = !!cfg.tts;

    if (!ctx.replied && !interaction.replied && !interaction.deferred) {
        await interaction.reply({ content: content || '​', ephemeral, tts });
        ctx.replied = true;
    } else {
        await interaction.followUp({ content: content || '​', ephemeral, tts });
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
