const type = 'action.message.send_or_edit';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, rv, buildEmbeds, buildMessageComponents, messageVars } = ctx;

    const content      = rv(String(cfg.message_content || ''));
    const ephemeral    = !!cfg.ephemeral;
    const embeds       = buildEmbeds(cfg.embeds || []);
    const responseType = String(cfg.response_type || 'reply');
    const varName      = String(cfg.var_name || '').trim();
    const components   = buildMessageComponents(node.id);

    const payload = {
        ...(content ? { content } : { content: '​' }),
        ...(embeds.length > 0     ? { embeds }     : {}),
        ...(components.length > 0 ? { components } : {}),
    };

    switch (responseType) {
        case 'channel': {
            if (interaction.channel) {
                const msg = await interaction.channel.send(payload);
                if (varName) messageVars.set(varName, msg);
                if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                    await interaction.deferReply({ ephemeral: true });
                    await interaction.deleteReply();
                    ctx.replied = true;
                }
            }
            break;
        }
        case 'specific_channel': {
            const channelId = rv(String(cfg.target_channel_id || '').trim());
            if (channelId && interaction.client) {
                const ch = await interaction.client.channels.fetch(channelId).catch(() => null);
                if (ch && ch.isTextBased()) {
                    const msg = await ch.send(payload);
                    if (varName) messageVars.set(varName, msg);
                }
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply({ content: '✅', ephemeral: true });
                ctx.replied = true;
            }
            break;
        }
        case 'channel_option': {
            const optName = String(cfg.target_option_name || '').trim().toLowerCase();
            const ch      = optName ? interaction.options.getChannel(optName) : null;
            if (ch && ch.isTextBased()) {
                const msg = await ch.send(payload);
                if (varName) messageVars.set(varName, msg);
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply({ content: '✅', ephemeral: true });
                ctx.replied = true;
            }
            break;
        }
        case 'dm_user': {
            const msg = await interaction.user.send(payload).catch(() => null);
            if (varName && msg) messageVars.set(varName, msg);
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                ctx.replied = true;
            }
            break;
        }
        case 'dm_user_option': {
            const optName = String(cfg.target_dm_option_name || '').trim().toLowerCase();
            const targetUser = optName ? interaction.options.getUser(optName) : null;
            if (targetUser) {
                const msg = await targetUser.send(payload).catch(() => null);
                if (varName && msg) messageVars.set(varName, msg);
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                ctx.replied = true;
            }
            break;
        }
        case 'dm_specific_user': {
            const userId = rv(String(cfg.target_user_id || '').trim());
            if (userId && interaction.client) {
                const user = await interaction.client.users.fetch(userId).catch(() => null);
                if (user) {
                    const msg = await user.send(payload).catch(() => null);
                    if (varName && msg) messageVars.set(varName, msg);
                }
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply({ content: '📨 DM gesendet.', ephemeral: true });
                ctx.replied = true;
            }
            break;
        }
        case 'edit_action': {
            const targetVar = String(cfg.edit_target_var || '').trim();
            if (targetVar && messageVars.has(targetVar)) {
                const targetMsg = messageVars.get(targetVar);
                const edited    = await targetMsg.edit(payload).catch(() => null);
                if (edited) {
                    messageVars.set(targetVar, edited);
                    if (varName && varName !== targetVar) messageVars.set(varName, edited);
                }
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.deferReply({ ephemeral: true });
                await interaction.deleteReply().catch(() => null);
                ctx.replied = true;
            }
            break;
        }
        case 'reply_message': {
            const rawMsgId = rv(String(cfg.target_message_id || '').trim());
            let targetMsg  = null;
            if (rawMsgId && interaction.channel) {
                targetMsg = messageVars.get(rawMsgId) || await interaction.channel.messages.fetch(rawMsgId).catch(() => null);
            }
            if (targetMsg && interaction.channel) {
                const msg = await targetMsg.reply(payload).catch(() => interaction.channel.send(payload));
                if (varName && msg) messageVars.set(varName, msg);
            } else if (interaction.channel) {
                const msg = await interaction.channel.send(payload);
                if (varName) messageVars.set(varName, msg);
            }
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.deferReply({ ephemeral: true });
                await interaction.deleteReply().catch(() => null);
                ctx.replied = true;
            }
            break;
        }
        default: {
            payload.ephemeral = ephemeral;
            if (!ctx.replied && !interaction.replied && !interaction.deferred) {
                await interaction.reply(payload);
                ctx.replied = true;
                if (varName) {
                    const msg = await interaction.fetchReply().catch(() => null);
                    if (msg) messageVars.set(varName, msg);
                }
            } else {
                const msg = await interaction.followUp(payload);
                if (varName) messageVars.set(varName, msg);
            }
            break;
        }
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
