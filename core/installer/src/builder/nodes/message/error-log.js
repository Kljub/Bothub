const type = 'action.utility.error_log';
const aliases = ['utility.error_handler'];

async function execute(ctx) {
    ctx.currentNode = null;
}

module.exports = { type, aliases, execute };
