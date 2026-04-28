const type = 'action.thread.create';

const ARCHIVE_MAP = { '60': 60, '1440': 1440, '4320': 4320, '10080': 10080 };

async function execute(ctx) {
    const { cfg, node, getNextNode, rv, interaction, localVars } = ctx;
    const name        = rv(String(cfg.name || 'new-thread').trim());
    const archiveStr  = String(cfg.auto_archive || '1440');
    const autoArchive = ARCHIVE_MAP[archiveStr] || 1440;
    const resultVar   = String(cfg.result_var || '').trim();

    if (interaction.channel && typeof interaction.channel.threads?.create === 'function') {
        const thread = await interaction.channel.threads.create({ name, autoArchiveDuration: autoArchive }).catch(() => null);
        if (thread && resultVar) {
            localVars.set(resultVar,           thread.id);
            localVars.set(resultVar + '.id',   thread.id);
            localVars.set(resultVar + '.name', thread.name);
        }
    }
    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
