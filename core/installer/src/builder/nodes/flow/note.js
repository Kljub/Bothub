const type = 'action.utility.note';

async function execute(ctx) {
    ctx.currentNode = ctx.getNextNode(ctx.node.id, 'next');
}

module.exports = { type, execute };
