// PFAD: /core/installer/src/services/custom-event-service.js

const { dbQuery } = require('../db');

// ── Discord.js event → handler descriptor ─────────────────────────────────────
const EVENT_TYPE_MAP = {
    // Message
    'message.create': {
        discordEvent: 'messageCreate',
        getGuildId: ([msg]) => msg?.guildId || null,
        buildContext: ([msg]) => ({
            'message.id':          msg.id,
            'message.content':     msg.content || '',
            'message.author.id':   msg.author?.id || '',
            'message.author.name': msg.author?.username || '',
            'message.channel.id':  msg.channelId || '',
            'message.guild.id':    msg.guildId || '',
            'guild.id':            msg.guildId || '',
            'guild.name':          msg.guild?.name || '',
        }),
    },
    'message.update': {
        discordEvent: 'messageUpdate',
        getGuildId: ([, newMsg]) => newMsg?.guildId || null,
        buildContext: ([oldMsg, newMsg]) => ({
            'message.id':              newMsg.id,
            'message.content':         newMsg.content || '',
            'message.old_content':     oldMsg?.content || '',
            'message.author.id':       newMsg.author?.id || '',
            'message.author.name':     newMsg.author?.username || '',
            'message.channel.id':      newMsg.channelId || '',
            'guild.id':                newMsg.guildId || '',
            'guild.name':              newMsg.guild?.name || '',
        }),
    },
    'message.delete': {
        discordEvent: 'messageDelete',
        getGuildId: ([msg]) => msg?.guildId || null,
        buildContext: ([msg]) => ({
            'message.id':         msg.id,
            'message.content':    msg.content || '',
            'message.author.id':  msg.author?.id || '',
            'message.channel.id': msg.channelId || '',
            'guild.id':           msg.guildId || '',
            'guild.name':         msg.guild?.name || '',
        }),
    },
    'message.pin': {
        discordEvent: 'channelPinsUpdate',
        getGuildId: ([ch]) => ch?.guildId || null,
        buildContext: ([ch, time]) => ({
            'channel.id':   ch?.id || '',
            'channel.name': ch?.name || '',
            'guild.id':     ch?.guildId || '',
            'guild.name':   ch?.guild?.name || '',
            'pin.time':     time ? time.toISOString() : '',
        }),
    },
    'message.typing': {
        discordEvent: 'typingStart',
        getGuildId: ([typing]) => typing?.guild?.id || null,
        buildContext: ([typing]) => ({
            'message.author.id':   typing.user?.id || '',
            'message.author.name': typing.user?.username || '',
            'message.channel.id':  typing.channel?.id || '',
            'guild.id':            typing.guild?.id || '',
            'guild.name':          typing.guild?.name || '',
        }),
    },

    // Member
    'member.join': {
        discordEvent: 'guildMemberAdd',
        getGuildId: ([member]) => member?.guild?.id || null,
        buildContext: ([member]) => ({
            'member.id':           member.id,
            'member.name':         member.user?.username || '',
            'member.display_name': member.displayName || member.user?.username || '',
            'member.tag':          member.user?.tag || '',
            'guild.id':            member.guild?.id || '',
            'guild.name':          member.guild?.name || '',
            'guild.member_count':  String(member.guild?.memberCount || ''),
        }),
    },
    'member.leave': {
        discordEvent: 'guildMemberRemove',
        getGuildId: ([member]) => member?.guild?.id || null,
        buildContext: ([member]) => ({
            'member.id':    member.id,
            'member.name':  member.user?.username || '',
            'member.tag':   member.user?.tag || '',
            'guild.id':     member.guild?.id || '',
            'guild.name':   member.guild?.name || '',
        }),
    },
    'member.ban': {
        discordEvent: 'guildBanAdd',
        getGuildId: ([ban]) => ban?.guild?.id || null,
        buildContext: ([ban]) => ({
            'member.id':   ban.user?.id || '',
            'member.name': ban.user?.username || '',
            'member.tag':  ban.user?.tag || '',
            'guild.id':    ban.guild?.id || '',
            'guild.name':  ban.guild?.name || '',
        }),
    },
    'member.unban': {
        discordEvent: 'guildBanRemove',
        getGuildId: ([ban]) => ban?.guild?.id || null,
        buildContext: ([ban]) => ({
            'member.id':   ban.user?.id || '',
            'member.name': ban.user?.username || '',
            'guild.id':    ban.guild?.id || '',
            'guild.name':  ban.guild?.name || '',
        }),
    },
    'member.update': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        buildContext: ([, newMember]) => ({
            'member.id':           newMember.id,
            'member.name':         newMember.user?.username || '',
            'member.display_name': newMember.displayName || '',
            'guild.id':            newMember.guild?.id || '',
            'guild.name':          newMember.guild?.name || '',
        }),
    },
    'member.role_add': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => {
            const oldIds = new Set([...(oldMember?.roles?.cache?.keys() || [])]);
            const newIds = [...(newMember?.roles?.cache?.keys() || [])];
            return newIds.some((id) => !oldIds.has(id));
        },
        buildContext: ([oldMember, newMember]) => {
            const oldIds = new Set([...(oldMember?.roles?.cache?.keys() || [])]);
            const addedRoles = [...(newMember?.roles?.cache?.values() || [])].filter((r) => !oldIds.has(r.id));
            const role = addedRoles[0] || null;
            return {
                'member.id':   newMember.id,
                'member.name': newMember.user?.username || '',
                'role.id':     role?.id || '',
                'role.name':   role?.name || '',
                'guild.id':    newMember.guild?.id || '',
                'guild.name':  newMember.guild?.name || '',
            };
        },
    },
    'member.role_remove': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => {
            const newIds = new Set([...(newMember?.roles?.cache?.keys() || [])]);
            const oldIds = [...(oldMember?.roles?.cache?.keys() || [])];
            return oldIds.some((id) => !newIds.has(id));
        },
        buildContext: ([oldMember, newMember]) => {
            const newIds = new Set([...(newMember?.roles?.cache?.keys() || [])]);
            const removedRoles = [...(oldMember?.roles?.cache?.values() || [])].filter((r) => !newIds.has(r.id));
            const role = removedRoles[0] || null;
            return {
                'member.id':   newMember.id,
                'member.name': newMember.user?.username || '',
                'role.id':     role?.id || '',
                'role.name':   role?.name || '',
                'guild.id':    newMember.guild?.id || '',
                'guild.name':  newMember.guild?.name || '',
            };
        },
    },
    'member.nickname_change': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => oldMember?.nickname !== newMember?.nickname,
        buildContext: ([oldMember, newMember]) => ({
            'member.id':        newMember.id,
            'member.name':      newMember.user?.username || '',
            'member.old_nick':  oldMember?.nickname || '',
            'member.new_nick':  newMember?.nickname || '',
            'guild.id':         newMember.guild?.id || '',
            'guild.name':       newMember.guild?.name || '',
        }),
    },

    // Reactions
    'reaction.add': {
        discordEvent: 'messageReactionAdd',
        getGuildId: ([reaction]) => reaction?.message?.guildId || null,
        buildContext: ([reaction, user]) => ({
            'reaction.emoji':      reaction.emoji?.name || '',
            'reaction.emoji.id':   reaction.emoji?.id || '',
            'reaction.user.id':    user?.id || '',
            'reaction.user.name':  user?.username || '',
            'reaction.message.id': reaction.message?.id || '',
            'reaction.channel.id': reaction.message?.channelId || '',
            'guild.id':            reaction.message?.guildId || '',
            'guild.name':          reaction.message?.guild?.name || '',
        }),
    },
    'reaction.remove': {
        discordEvent: 'messageReactionRemove',
        getGuildId: ([reaction]) => reaction?.message?.guildId || null,
        buildContext: ([reaction, user]) => ({
            'reaction.emoji':      reaction.emoji?.name || '',
            'reaction.user.id':    user?.id || '',
            'reaction.message.id': reaction.message?.id || '',
            'reaction.channel.id': reaction.message?.channelId || '',
            'guild.id':            reaction.message?.guildId || '',
        }),
    },
    'reaction.remove_all': {
        discordEvent: 'messageReactionRemoveAll',
        getGuildId: ([msg]) => msg?.guildId || null,
        buildContext: ([msg]) => ({
            'message.id':         msg?.id || '',
            'message.channel.id': msg?.channelId || '',
            'guild.id':           msg?.guildId || '',
        }),
    },
    'reaction.remove_emoji': {
        discordEvent: 'messageReactionRemoveEmoji',
        getGuildId: ([reaction]) => reaction?.message?.guildId || null,
        buildContext: ([reaction]) => ({
            'reaction.emoji':      reaction.emoji?.name || '',
            'reaction.emoji.id':   reaction.emoji?.id || '',
            'reaction.message.id': reaction.message?.id || '',
            'guild.id':            reaction.message?.guildId || '',
        }),
    },

    // Roles
    'role.create': {
        discordEvent: 'roleCreate',
        getGuildId: ([role]) => role?.guild?.id || null,
        buildContext: ([role]) => ({ 'role.id': role.id, 'role.name': role.name, 'guild.id': role.guild?.id || '', 'guild.name': role.guild?.name || '' }),
    },
    'role.update': {
        discordEvent: 'roleUpdate',
        getGuildId: ([, newRole]) => newRole?.guild?.id || null,
        buildContext: ([, newRole]) => ({ 'role.id': newRole.id, 'role.name': newRole.name, 'guild.id': newRole.guild?.id || '', 'guild.name': newRole.guild?.name || '' }),
    },
    'role.delete': {
        discordEvent: 'roleDelete',
        getGuildId: ([role]) => role?.guild?.id || null,
        buildContext: ([role]) => ({ 'role.id': role.id, 'role.name': role.name, 'guild.id': role.guild?.id || '' }),
    },

    // Channels
    'channel.create': {
        discordEvent: 'channelCreate',
        getGuildId: ([ch]) => ch?.guildId || null,
        buildContext: ([ch]) => ({ 'channel.id': ch.id, 'channel.name': ch.name || '', 'channel.type': String(ch.type), 'guild.id': ch.guildId || '', 'guild.name': ch.guild?.name || '' }),
    },
    'channel.update': {
        discordEvent: 'channelUpdate',
        getGuildId: ([, newCh]) => newCh?.guildId || null,
        buildContext: ([, newCh]) => ({ 'channel.id': newCh.id, 'channel.name': newCh.name || '', 'guild.id': newCh.guildId || '' }),
    },
    'channel.delete': {
        discordEvent: 'channelDelete',
        getGuildId: ([ch]) => ch?.guildId || null,
        buildContext: ([ch]) => ({ 'channel.id': ch.id, 'channel.name': ch.name || '', 'guild.id': ch.guildId || '' }),
    },
    'channel.permissions_update': {
        discordEvent: 'channelUpdate',
        getGuildId: ([, newCh]) => newCh?.guildId || null,
        filter: ([oldCh, newCh]) => {
            try { return !oldCh.permissionOverwrites?.cache?.equals?.(newCh.permissionOverwrites?.cache); } catch (_) { return true; }
        },
        buildContext: ([, newCh]) => ({ 'channel.id': newCh.id, 'channel.name': newCh.name || '', 'guild.id': newCh.guildId || '' }),
    },
    'channel.topic_update': {
        discordEvent: 'channelUpdate',
        getGuildId: ([, newCh]) => newCh?.guildId || null,
        filter: ([oldCh, newCh]) => oldCh?.topic !== newCh?.topic,
        buildContext: ([oldCh, newCh]) => ({
            'channel.id':        newCh.id,
            'channel.name':      newCh.name || '',
            'channel.old_topic': oldCh?.topic || '',
            'channel.new_topic': newCh?.topic || '',
            'guild.id':          newCh.guildId || '',
        }),
    },
    'channel.pins_update': {
        discordEvent: 'channelPinsUpdate',
        getGuildId: ([ch]) => ch?.guildId || null,
        buildContext: ([ch]) => ({ 'channel.id': ch?.id || '', 'channel.name': ch?.name || '', 'guild.id': ch?.guildId || '' }),
    },

    // Guild
    'guild.name_change': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => oldGuild?.name !== newGuild?.name,
        buildContext: ([oldGuild, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name, 'guild.old_name': oldGuild?.name || '' }),
    },
    'guild.ownership_change': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => oldGuild?.ownerId !== newGuild?.ownerId,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name, 'guild.owner_id': newGuild.ownerId || '' }),
    },
    'guild.features_update': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name }),
    },
    'guild.partner_add': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => !oldGuild?.partnered && newGuild?.partnered,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name }),
    },
    'guild.partner_remove': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => oldGuild?.partnered && !newGuild?.partnered,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name }),
    },
    'guild.banner_add': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => !oldGuild?.banner && !!newGuild?.banner,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name, 'guild.banner': newGuild.bannerURL() || '' }),
    },
    'guild.banner_remove': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => !!oldGuild?.banner && !newGuild?.banner,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'guild.name': newGuild.name }),
    },
    'guild.afk_set': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => !oldGuild?.afkChannel && !!newGuild?.afkChannel,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id, 'channel.id': newGuild.afkChannelId || '' }),
    },
    'guild.afk_remove': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => !!oldGuild?.afkChannel && !newGuild?.afkChannel,
        buildContext: ([, newGuild]) => ({ 'guild.id': newGuild.id }),
    },
    'guild.screening_pass': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => oldMember?.pending === true && newMember?.pending === false,
        buildContext: ([, newMember]) => ({
            'member.id':   newMember.id,
            'member.name': newMember.user?.username || '',
            'guild.id':    newMember.guild?.id || '',
        }),
    },
    'guild.integrations_update': {
        discordEvent: 'guildIntegrationsUpdate',
        getGuildId: ([guild]) => guild?.id || null,
        buildContext: ([guild]) => ({ 'guild.id': guild?.id || '', 'guild.name': guild?.name || '' }),
    },

    // Invites
    'invite.create': {
        discordEvent: 'inviteCreate',
        getGuildId: ([invite]) => invite?.guild?.id || null,
        buildContext: ([invite]) => ({
            'invite.code':        invite.code,
            'invite.channel.id':  invite.channel?.id || '',
            'invite.inviter.id':  invite.inviter?.id || '',
            'guild.id':           invite.guild?.id || '',
        }),
    },
    'invite.delete': {
        discordEvent: 'inviteDelete',
        getGuildId: ([invite]) => invite?.guild?.id || null,
        buildContext: ([invite]) => ({
            'invite.code':       invite.code,
            'invite.channel.id': invite.channel?.id || '',
            'guild.id':          invite.guild?.id || '',
        }),
    },

    // Boosts
    'boost.first': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => !oldMember?.premiumSince && !!newMember?.premiumSince,
        buildContext: ([, newMember]) => ({
            'member.id':       newMember.id,
            'member.name':     newMember.user?.username || '',
            'guild.id':        newMember.guild?.id || '',
            'guild.name':      newMember.guild?.name || '',
            'guild.boost_count': String(newMember.guild?.premiumSubscriptionCount || ''),
        }),
    },
    'boost.remove': {
        discordEvent: 'guildMemberUpdate',
        getGuildId: ([, newMember]) => newMember?.guild?.id || null,
        filter: ([oldMember, newMember]) => !!oldMember?.premiumSince && !newMember?.premiumSince,
        buildContext: ([, newMember]) => ({
            'member.id':   newMember.id,
            'member.name': newMember.user?.username || '',
            'guild.id':    newMember.guild?.id || '',
        }),
    },
    'boost.level_up': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => (oldGuild?.premiumTier ?? -1) < (newGuild?.premiumTier ?? -1),
        buildContext: ([, newGuild]) => ({
            'guild.id':          newGuild.id,
            'guild.name':        newGuild.name,
            'guild.boost_level': String(newGuild.premiumTier ?? ''),
        }),
    },
    'boost.level_down': {
        discordEvent: 'guildUpdate',
        getGuildId: ([, newGuild]) => newGuild?.id || null,
        filter: ([oldGuild, newGuild]) => (oldGuild?.premiumTier ?? -1) > (newGuild?.premiumTier ?? -1),
        buildContext: ([, newGuild]) => ({
            'guild.id':          newGuild.id,
            'guild.name':        newGuild.name,
            'guild.boost_level': String(newGuild.premiumTier ?? ''),
        }),
    },

    // Bot lifecycle
    'bot.guild_join': {
        discordEvent: 'guildCreate',
        getGuildId: ([guild]) => guild?.id || null,
        buildContext: ([guild]) => ({ 'guild.id': guild.id, 'guild.name': guild.name, 'guild.member_count': String(guild.memberCount) }),
    },
    'bot.guild_leave': {
        discordEvent: 'guildDelete',
        getGuildId: ([guild]) => guild?.id || null,
        buildContext: ([guild]) => ({ 'guild.id': guild.id, 'guild.name': guild.name }),
    },
    'bot.ready': {
        discordEvent: 'ready',
        getGuildId: () => null,
        buildContext: ([client]) => ({ 'bot.id': client?.user?.id || '', 'bot.name': client?.user?.username || '' }),
    },

    // Threads
    'thread.create': {
        discordEvent: 'threadCreate',
        getGuildId: ([thread]) => thread?.guildId || null,
        buildContext: ([thread]) => ({
            'thread.id':        thread.id,
            'thread.name':      thread.name,
            'thread.parent.id': thread.parentId || '',
            'guild.id':         thread.guildId || '',
        }),
    },
    'thread.update': {
        discordEvent: 'threadUpdate',
        getGuildId: ([, newThread]) => newThread?.guildId || null,
        buildContext: ([, newThread]) => ({ 'thread.id': newThread.id, 'thread.name': newThread.name, 'guild.id': newThread.guildId || '' }),
    },
    'thread.delete': {
        discordEvent: 'threadDelete',
        getGuildId: ([thread]) => thread?.guildId || null,
        buildContext: ([thread]) => ({ 'thread.id': thread.id, 'thread.name': thread.name, 'guild.id': thread.guildId || '' }),
    },
    'thread.member_add': {
        discordEvent: 'threadMemberUpdate',
        getGuildId: ([member]) => member?.thread?.guildId || null,
        buildContext: ([member]) => ({
            'member.id':   member.id,
            'thread.id':   member.thread?.id || '',
            'thread.name': member.thread?.name || '',
            'guild.id':    member.thread?.guildId || '',
        }),
    },
    'thread.member_remove': {
        discordEvent: 'threadMembersUpdate',
        getGuildId: ([thread]) => thread?.guildId || null,
        buildContext: ([thread]) => ({ 'thread.id': thread.id, 'thread.name': thread.name, 'guild.id': thread.guildId || '' }),
    },
    'thread.list_sync': {
        discordEvent: 'threadListSync',
        getGuildId: ([threads]) => threads?.first?.()?.guildId || null,
        buildContext: () => ({}),
    },
    'thread.members_update': {
        discordEvent: 'threadMembersUpdate',
        getGuildId: ([thread]) => thread?.guildId || null,
        buildContext: ([thread]) => ({ 'thread.id': thread.id, 'guild.id': thread.guildId || '' }),
    },

    // Scheduled events
    'scheduled_event.create': {
        discordEvent: 'guildScheduledEventCreate',
        getGuildId: ([ev]) => ev?.guild?.id || null,
        buildContext: ([ev]) => ({ 'event.id': ev.id, 'event.name': ev.name, 'event.description': ev.description || '', 'guild.id': ev.guild?.id || '' }),
    },
    'scheduled_event.update': {
        discordEvent: 'guildScheduledEventUpdate',
        getGuildId: ([, newEv]) => newEv?.guild?.id || null,
        buildContext: ([, newEv]) => ({ 'event.id': newEv.id, 'event.name': newEv.name, 'guild.id': newEv.guild?.id || '' }),
    },
    'scheduled_event.delete': {
        discordEvent: 'guildScheduledEventDelete',
        getGuildId: ([ev]) => ev?.guild?.id || null,
        buildContext: ([ev]) => ({ 'event.id': ev.id, 'event.name': ev.name, 'guild.id': ev.guild?.id || '' }),
    },
    'scheduled_event.user_add': {
        discordEvent: 'guildScheduledEventUserAdd',
        getGuildId: ([ev]) => ev?.guild?.id || null,
        buildContext: ([ev, user]) => ({ 'event.id': ev.id, 'event.name': ev.name, 'member.id': user?.id || '', 'guild.id': ev.guild?.id || '' }),
    },
    'scheduled_event.user_remove': {
        discordEvent: 'guildScheduledEventUserRemove',
        getGuildId: ([ev]) => ev?.guild?.id || null,
        buildContext: ([ev, user]) => ({ 'event.id': ev.id, 'event.name': ev.name, 'member.id': user?.id || '', 'guild.id': ev.guild?.id || '' }),
    },

    // Stage instances
    'stage.create': {
        discordEvent: 'stageInstanceCreate',
        getGuildId: ([si]) => si?.guild?.id || null,
        buildContext: ([si]) => ({ 'stage.id': si.id, 'stage.topic': si.topic || '', 'guild.id': si.guild?.id || '' }),
    },
    'stage.update': {
        discordEvent: 'stageInstanceUpdate',
        getGuildId: ([, newSi]) => newSi?.guild?.id || null,
        buildContext: ([, newSi]) => ({ 'stage.id': newSi.id, 'stage.topic': newSi.topic || '', 'guild.id': newSi.guild?.id || '' }),
    },
    'stage.delete': {
        discordEvent: 'stageInstanceDelete',
        getGuildId: ([si]) => si?.guild?.id || null,
        buildContext: ([si]) => ({ 'stage.id': si.id, 'guild.id': si.guild?.id || '' }),
    },

    // Stickers
    'sticker.create': {
        discordEvent: 'guildStickerCreate',
        getGuildId: ([sticker]) => sticker?.guild?.id || null,
        buildContext: ([sticker]) => ({ 'sticker.id': sticker.id, 'sticker.name': sticker.name, 'guild.id': sticker.guild?.id || '' }),
    },
    'sticker.update': {
        discordEvent: 'guildStickerUpdate',
        getGuildId: ([, newSticker]) => newSticker?.guild?.id || null,
        buildContext: ([, newSticker]) => ({ 'sticker.id': newSticker.id, 'sticker.name': newSticker.name, 'guild.id': newSticker.guild?.id || '' }),
    },
    'sticker.delete': {
        discordEvent: 'guildStickerDelete',
        getGuildId: ([sticker]) => sticker?.guild?.id || null,
        buildContext: ([sticker]) => ({ 'sticker.id': sticker.id, 'sticker.name': sticker.name, 'guild.id': sticker.guild?.id || '' }),
    },

    // Webhook
    'webhook.update': {
        discordEvent: 'webhooksUpdate',
        getGuildId: ([ch]) => ch?.guildId || null,
        buildContext: ([ch]) => ({ 'channel.id': ch?.id || '', 'guild.id': ch?.guildId || '' }),
    },

    // Audit log
    'audit.entry_create': {
        discordEvent: 'guildAuditLogEntryCreate',
        getGuildId: ([, guild]) => guild?.id || null,
        buildContext: ([entry, guild]) => ({
            'audit.action_type':  String(entry.action),
            'audit.executor.id':  entry.executorId || '',
            'audit.target.id':    entry.targetId || '',
            'audit.reason':       entry.reason || '',
            'guild.id':           guild?.id || '',
        }),
    },

    // AutoMod
    'automod.action': {
        discordEvent: 'autoModerationActionExecution',
        getGuildId: ([ex]) => ex?.guild?.id || null,
        buildContext: ([ex]) => ({
            'automod.user.id':    ex.userId || '',
            'automod.channel.id': ex.channelId || '',
            'automod.rule.id':    ex.ruleId || '',
            'automod.action.type': String(ex.action?.type ?? ''),
            'guild.id':           ex.guild?.id || '',
        }),
    },
    'automod.rule_create': {
        discordEvent: 'autoModerationRuleCreate',
        getGuildId: ([rule]) => rule?.guild?.id || null,
        buildContext: ([rule]) => ({ 'automod.rule.id': rule.id, 'automod.rule.name': rule.name, 'guild.id': rule.guild?.id || '' }),
    },
    'automod.rule_delete': {
        discordEvent: 'autoModerationRuleDelete',
        getGuildId: ([rule]) => rule?.guild?.id || null,
        buildContext: ([rule]) => ({ 'automod.rule.id': rule.id, 'automod.rule.name': rule.name, 'guild.id': rule.guild?.id || '' }),
    },
    'automod.rule_update': {
        discordEvent: 'autoModerationRuleUpdate',
        getGuildId: ([, newRule]) => newRule?.guild?.id || null,
        buildContext: ([, newRule]) => ({ 'automod.rule.id': newRule.id, 'automod.rule.name': newRule.name, 'guild.id': newRule.guild?.id || '' }),
    },

    // Music events — dispatched internally via custom EventEmitter on the music service
    'music.track_start':      { discordEvent: 'bhMusic:trackStart',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicTrackContext },
    'music.track_finish':     { discordEvent: 'bhMusic:trackFinish',      getGuildId: ([p]) => p?.guildId || null, buildContext: musicTrackContext },
    'music.track_skip':       { discordEvent: 'bhMusic:trackSkip',        getGuildId: ([p]) => p?.guildId || null, buildContext: musicTrackContext },
    'music.track_error':      { discordEvent: 'bhMusic:trackError',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicTrackContext },
    'music.track_stuck':      { discordEvent: 'bhMusic:trackStuck',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicTrackContext },
    'music.playback_start':   { discordEvent: 'bhMusic:playbackStart',    getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.playback_pause':   { discordEvent: 'bhMusic:playbackPause',    getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.playback_resume':  { discordEvent: 'bhMusic:playbackResume',   getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.playback_stop':    { discordEvent: 'bhMusic:playbackStop',     getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.queue_add':        { discordEvent: 'bhMusic:queueAdd',         getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.queue_remove':     { discordEvent: 'bhMusic:queueRemove',      getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.queue_finish':     { discordEvent: 'bhMusic:queueFinish',      getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.queue_shuffle':    { discordEvent: 'bhMusic:queueShuffle',     getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.autoplay_toggle':  { discordEvent: 'bhMusic:autoplayToggle',   getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.autoleave_toggle': { discordEvent: 'bhMusic:autoleaveToggle',  getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.seek':             { discordEvent: 'bhMusic:seek',             getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.filter_change':    { discordEvent: 'bhMusic:filterChange',     getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.volume_change':    { discordEvent: 'bhMusic:volumeChange',     getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.loop_change':      { discordEvent: 'bhMusic:loopChange',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.connect':          { discordEvent: 'bhMusic:connect',          getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.disconnect':       { discordEvent: 'bhMusic:disconnect',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.move':             { discordEvent: 'bhMusic:move',             getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.player_create':    { discordEvent: 'bhMusic:playerCreate',     getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.player_destroy':   { discordEvent: 'bhMusic:playerDestroy',    getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.player_node_switch':{ discordEvent: 'bhMusic:playerNodeSwitch',getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.player_ws_close':  { discordEvent: 'bhMusic:playerWsClose',    getGuildId: ([p]) => p?.guildId || null, buildContext: musicPlayerContext },
    'music.mute_change':      { discordEvent: 'bhMusic:muteChange',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicVoiceContext },
    'music.deaf_change':      { discordEvent: 'bhMusic:deafChange',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicVoiceContext },
    'music.user_join_vc':     { discordEvent: 'bhMusic:userJoinVc',       getGuildId: ([p]) => p?.guildId || null, buildContext: musicVoiceContext },
    'music.user_leave_vc':    { discordEvent: 'bhMusic:userLeaveVc',      getGuildId: ([p]) => p?.guildId || null, buildContext: musicVoiceContext },
};

function musicTrackContext([player, track]) {
    return {
        'track.title':        track?.title || '',
        'track.url':          track?.uri || track?.url || '',
        'track.duration':     String(track?.duration || ''),
        'track.requester.id': track?.requester?.id || '',
        'guild.id':           player?.guildId || '',
        'queue.size':         String(player?.queue?.size ?? player?.queue?.length ?? ''),
    };
}

function musicPlayerContext([player]) {
    return {
        'guild.id':   player?.guildId || '',
        'queue.size': String(player?.queue?.size ?? player?.queue?.length ?? ''),
    };
}

function musicVoiceContext([player, member]) {
    return {
        'guild.id':   player?.guildId || '',
        'member.id':  member?.id || '',
        'member.name': member?.user?.username || '',
    };
}

// ── Variable resolution ───────────────────────────────────────────────────────
function resolveVars(text, context, localVars, globalVars) {
    if (typeof text !== 'string') return String(text ?? '');
    return text
        .replace(/\{([a-z0-9_.]+)\}/gi, (_, key) => {
            const lk = key.toLowerCase();
            if (localVars?.has(lk))  return String(localVars.get(lk)  ?? '');
            if (globalVars?.has(lk)) return String(globalVars.get(lk) ?? '');
            if (context?.[lk] !== undefined) return String(context[lk] ?? '');
            return '';
        });
}

// ── Flow executor ─────────────────────────────────────────────────────────────
async function executeFlowGraph(builderData, context, client, guildId) {
    if (!builderData || !Array.isArray(builderData.nodes)) return;

    const nodes   = builderData.nodes;
    const edges   = Array.isArray(builderData.edges) ? builderData.edges : [];
    const nodeMap = new Map(nodes.map((n) => [n.id, n]));
    const localVars  = new Map();
    const globalVars = new Map();

    // Preload global vars
    if (context?.['guild.id']) {
        try {
            // Try bot_id-scoped global variables (best effort)
            const rows = await dbQuery(
                'SELECT var_key, var_value FROM bot_global_variables WHERE guild_id = ?',
                [context['guild.id']]
            ).catch(() => []);
            if (Array.isArray(rows)) {
                for (const row of rows) globalVars.set(String(row.var_key), row.var_value != null ? String(row.var_value) : '');
            }
        } catch (_) {}
    }

    const triggerNode = nodes.find((n) => n.type === 'trigger.event');
    if (!triggerNode) return;

    function getNextNode(fromId, port) {
        const edge = edges.find((e) => e.from_node_id === fromId && e.from_port === port);
        if (!edge) return null;
        return nodeMap.get(edge.to_node_id) || null;
    }

    let currentNode = getNextNode(triggerNode.id, 'next');
    const MAX_STEPS = 200;
    let steps = 0;
    const loopStack = [];

    // Helper: resolve channel for sending
    async function resolveChannel(channelId) {
        if (!channelId || !client) return null;
        try { return await client.channels.fetch(String(channelId)); } catch (_) { return null; }
    }

    // Helper: get event channel
    function getEventChannel() {
        const chId = context?.['message.channel.id'] || context?.['channel.id'] || context?.['reaction.channel.id'];
        if (!chId || !client) return null;
        return client.channels.cache.get(chId) || null;
    }

    async function sendToChannel(cfg, content, embeds) {
        const rt = cfg.response_type || cfg.channel_id ? 'specific' : 'event';
        const chId = resolveVars(cfg.target_channel_id || cfg.channel_id || '', context, localVars, globalVars);

        let ch = null;
        if (chId) {
            ch = await resolveChannel(chId);
        } else {
            ch = getEventChannel();
        }
        if (!ch?.isTextBased?.()) return;

        const payload = {};
        if (content) payload.content = content;
        if (embeds?.length) payload.embeds = embeds;
        await ch.send(payload).catch(() => {});
    }

    while (true) {
        if (currentNode === null && loopStack.length > 0) {
            const ctx = loopStack[loopStack.length - 1];
            ctx.currentIter++;
            if (ctx.currentIter < ctx.maxIter) {
                localVars.set(ctx.idxKey, String(ctx.currentIter));
                if (ctx.mode === 'foreach' && ctx.items) localVars.set(ctx.itemKey, String(ctx.items[ctx.currentIter] ?? ''));
                currentNode = ctx.bodyStartNode;
            } else {
                loopStack.pop();
                currentNode = ctx.nextAfterLoop;
            }
            continue;
        }

        if (currentNode === null || steps >= MAX_STEPS) break;
        steps++;

        const cfg = currentNode.config || {};

        try {
            switch (currentNode.type) {
                case 'action.send_message': {
                    const content = resolveVars(cfg.content || '', context, localVars, globalVars);
                    await sendToChannel(cfg, content || '\u200B', null);
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.message.send_or_edit': {
                    const content = resolveVars(cfg.message_content || '', context, localVars, globalVars);
                    await sendToChannel(cfg, content || '\u200B', null);
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.add_role': {
                    if (guildId && client) {
                        const guild  = await client.guilds.fetch(guildId).catch(() => null);
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const roleId = resolveVars(cfg.role_id || '', context, localVars, globalVars);
                        if (guild && userId && roleId) {
                            const member = await guild.members.fetch(userId).catch(() => null);
                            if (member) await member.roles.add(roleId).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.remove_role': {
                    if (guildId && client) {
                        const guild  = await client.guilds.fetch(guildId).catch(() => null);
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const roleId = resolveVars(cfg.role_id || '', context, localVars, globalVars);
                        if (guild && userId && roleId) {
                            const member = await guild.members.fetch(userId).catch(() => null);
                            if (member) await member.roles.remove(roleId).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.kick_member': {
                    if (guildId && client) {
                        const guild  = await client.guilds.fetch(guildId).catch(() => null);
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const reason = resolveVars(cfg.reason || '', context, localVars, globalVars);
                        if (guild && userId) {
                            const member = await guild.members.fetch(userId).catch(() => null);
                            if (member) await member.kick(reason || undefined).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.ban_member': {
                    if (guildId && client) {
                        const guild  = await client.guilds.fetch(guildId).catch(() => null);
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const reason = resolveVars(cfg.reason || '', context, localVars, globalVars);
                        const delDays = Math.max(0, Math.min(7, Number(cfg.delete_message_days) || 0));
                        if (guild && userId) {
                            await guild.bans.create(userId, { reason: reason || undefined, deleteMessageDays: delDays }).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.timeout_member': {
                    if (guildId && client) {
                        const guild = await client.guilds.fetch(guildId).catch(() => null);
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const secs = Math.max(1, Number(cfg.duration_seconds) || 60);
                        if (guild && userId) {
                            const member = await guild.members.fetch(userId).catch(() => null);
                            if (member) await member.timeout(secs * 1000).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.delete_message': {
                    const msgId = resolveVars(cfg.message_id || '', context, localVars, globalVars);
                    const chId  = resolveVars(cfg.channel_id || '', context, localVars, globalVars);
                    if (msgId && chId && client) {
                        const ch = await client.channels.fetch(chId).catch(() => null);
                        if (ch?.isTextBased?.()) {
                            const msg = await ch.messages.fetch(msgId).catch(() => null);
                            if (msg) await msg.delete().catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.flow.wait': {
                    const ms = Math.max(0, Math.min(30000, Number(cfg.duration_ms) || 1000));
                    await new Promise((res) => setTimeout(res, ms));
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.flow.stop':
                    currentNode = null;
                    break;

                case 'action.flow.loop': {
                    const mode    = String(cfg.mode || 'count');
                    const maxIter = Math.max(1, Math.min(100, Number(cfg.count) || 5));
                    const idxKey  = String(cfg.idx_var_name  || 'loop.index');
                    const itemKey = String(cfg.item_var_name || 'loop.item');
                    const items   = mode === 'foreach'
                        ? (localVars.has(cfg.items_var) ? String(localVars.get(cfg.items_var)).split(',') : [])
                        : null;

                    const bodyNode  = getNextNode(currentNode.id, 'body');
                    const afterNode = getNextNode(currentNode.id, 'next');

                    if (!bodyNode || maxIter === 0) {
                        currentNode = afterNode;
                    } else {
                        localVars.set(idxKey, '0');
                        if (mode === 'foreach' && items) localVars.set(itemKey, String(items[0] ?? ''));
                        loopStack.push({ bodyStartNode: bodyNode, nextAfterLoop: afterNode, maxIter, currentIter: 0, mode, items, idxKey, itemKey });
                        currentNode = bodyNode;
                    }
                    break;
                }

                case 'action.log_message': {
                    const msg = resolveVars(cfg.message || '', context, localVars, globalVars);
                    console.log('[custom-event-service] LOG:', msg);
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'action.http_request': {
                    const url    = resolveVars(cfg.url || '', context, localVars, globalVars);
                    const method = String(cfg.method || 'GET').toUpperCase();
                    const resVar = String(cfg.result_var || 'http.response');
                    if (url) {
                        try {
                            const fetchOpts = { method };
                            if (cfg.body && method !== 'GET') {
                                fetchOpts.body = resolveVars(cfg.body, context, localVars, globalVars);
                                fetchOpts.headers = { 'Content-Type': 'application/json' };
                            }
                            const resp = await fetch(url, fetchOpts);
                            const text = await resp.text();
                            localVars.set(resVar, text);
                        } catch (err) {
                            localVars.set(resVar, '');
                            currentNode = getNextNode(currentNode.id, 'error') || getNextNode(currentNode.id, 'next');
                            break;
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.local.set': {
                    const key = resolveVars(cfg.key || '', context, localVars, globalVars);
                    const val = resolveVars(cfg.value || '', context, localVars, globalVars);
                    if (key) localVars.set(key.toLowerCase(), val);
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.local.get': {
                    const key    = resolveVars(cfg.key || '', context, localVars, globalVars);
                    const resVar = String(cfg.result_var || key);
                    if (key) localVars.set(resVar.toLowerCase(), localVars.get(key.toLowerCase()) ?? '');
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.global.set': {
                    const key = resolveVars(cfg.key || '', context, localVars, globalVars);
                    const val = resolveVars(cfg.value || '', context, localVars, globalVars);
                    if (key) {
                        globalVars.set(key.toLowerCase(), val);
                        // Persist best-effort (non-blocking)
                        if (context?.['guild.id']) {
                            dbQuery(
                                'INSERT INTO bot_global_variables (guild_id, var_key, var_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE var_value=VALUES(var_value)',
                                [context['guild.id'], key, val]
                            ).catch(() => {});
                        }
                    }
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'variable.global.get': {
                    const key    = resolveVars(cfg.key || '', context, localVars, globalVars);
                    const resVar = String(cfg.result_var || key);
                    if (key) localVars.set(resVar.toLowerCase(), globalVars.get(key.toLowerCase()) ?? '');
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
                }

                case 'condition.comparison': {
                    const left  = resolveVars(cfg.left  || '', context, localVars, globalVars);
                    const right = resolveVars(cfg.right || '', context, localVars, globalVars);
                    const op    = String(cfg.operator || '==');
                    let result = false;
                    const ln = parseFloat(left);
                    const rn = parseFloat(right);
                    const numericOk = !isNaN(ln) && !isNaN(rn);
                    switch (op) {
                        case '==':  result = left === right; break;
                        case '!=':  result = left !== right; break;
                        case '<':   result = numericOk && ln < rn; break;
                        case '<=':  result = numericOk && ln <= rn; break;
                        case '>':   result = numericOk && ln > rn; break;
                        case '>=':  result = numericOk && ln >= rn; break;
                    }
                    currentNode = getNextNode(currentNode.id, result ? 'true' : 'false');
                    break;
                }

                case 'condition.string_contains': {
                    const text = resolveVars(cfg.text || '', context, localVars, globalVars);
                    const sub  = resolveVars(cfg.substring || '', context, localVars, globalVars);
                    const cs   = cfg.case_sensitive === true || cfg.case_sensitive === 'true';
                    const result = cs ? text.includes(sub) : text.toLowerCase().includes(sub.toLowerCase());
                    currentNode = getNextNode(currentNode.id, result ? 'true' : 'false');
                    break;
                }

                case 'condition.user_has_role': {
                    let result = false;
                    if (guildId && client) {
                        const userId = resolveVars(cfg.user_id || '', context, localVars, globalVars);
                        const roleId = resolveVars(cfg.role_id || '', context, localVars, globalVars);
                        if (userId && roleId) {
                            const guild  = client.guilds.cache.get(guildId);
                            const member = await guild?.members.fetch(userId).catch(() => null);
                            result = !!member?.roles?.cache?.has(roleId);
                        }
                    }
                    currentNode = getNextNode(currentNode.id, result ? 'true' : 'false');
                    break;
                }

                case 'condition.channel_is': {
                    const chId    = resolveVars(cfg.channel_id || '', context, localVars, globalVars);
                    const current = context?.['message.channel.id'] || context?.['channel.id'] || '';
                    currentNode = getNextNode(currentNode.id, chId && current === chId ? 'true' : 'false');
                    break;
                }

                case 'utility.error_handler':
                    currentNode = null;
                    break;

                default:
                    currentNode = getNextNode(currentNode.id, 'next');
                    break;
            }
        } catch (err) {
            console.error('[custom-event-service] Flow error at node', currentNode?.type, ':', err instanceof Error ? err.message : String(err));
            const errNode = getNextNode(currentNode?.id || '', 'error');
            currentNode = errNode || null;
        }
    }
}

// ── CustomEventService class ──────────────────────────────────────────────────
class CustomEventService {
    constructor(client, botId) {
        this.client    = client;
        this.botId     = Number(botId);
        // eventType → [{ id, name, event_type, builder_data }]
        this.events    = new Map();
        // discordEvent → bound listener fn
        this.listeners = new Map();
    }

    /**
     * Load all enabled custom events for this bot from the database.
     */
    async load() {
        this.events.clear();

        try {
            const rows = await dbQuery(
                `SELECT
                    e.id, e.name, e.event_type, e.is_enabled,
                    b.builder_json
                 FROM bot_custom_events AS e
                 LEFT JOIN bot_custom_event_builders AS b ON b.custom_event_id = e.id
                 WHERE e.bot_id = ? AND e.is_enabled = 1
                 ORDER BY e.id ASC`,
                [this.botId]
            );

            if (!Array.isArray(rows)) return;

            for (const row of rows) {
                const eventType = String(row.event_type || '').trim();
                if (!eventType) continue;

                let builderData = null;
                if (row.builder_json) {
                    try {
                        const parsed = JSON.parse(String(row.builder_json));
                        if (parsed && typeof parsed === 'object') builderData = parsed;
                    } catch (_) {}
                }

                if (!this.events.has(eventType)) this.events.set(eventType, []);
                this.events.get(eventType).push({
                    id:           Number(row.id),
                    name:         String(row.name || ''),
                    event_type:   eventType,
                    builder_data: builderData,
                });
            }
        } catch (err) {
            console.error('[custom-event-service] Load error:', err instanceof Error ? err.message : String(err));
        }
    }

    /**
     * Re-register all Discord listeners after a reload.
     */
    async reload() {
        this.unregisterListeners();
        await this.load();
        this.registerListeners();
    }

    /**
     * Register Discord.js (or internal) event listeners for each unique event type.
     */
    registerListeners() {
        const emitter = this.client;
        if (!emitter?.on) return;

        // Group event types by their Discord event name to avoid duplicate listeners
        const discordEventToTypes = new Map();
        for (const [eventType] of this.events) {
            const descriptor = EVENT_TYPE_MAP[eventType];
            if (!descriptor) continue;
            const de = descriptor.discordEvent;
            if (!discordEventToTypes.has(de)) discordEventToTypes.set(de, []);
            discordEventToTypes.get(de).push(eventType);
        }

        for (const [discordEvent, eventTypes] of discordEventToTypes) {
            if (this.listeners.has(discordEvent)) continue; // already registered

            const listener = async (...args) => {
                for (const eventType of eventTypes) {
                    const descriptor = EVENT_TYPE_MAP[eventType];
                    const eventRecords = this.events.get(eventType);
                    if (!descriptor || !eventRecords?.length) continue;

                    // Apply optional filter
                    if (typeof descriptor.filter === 'function') {
                        try { if (!descriptor.filter(args)) continue; } catch (_) { continue; }
                    }

                    const guildId  = descriptor.getGuildId ? descriptor.getGuildId(args) : null;
                    let   context  = {};
                    try { context = descriptor.buildContext(args) || {}; } catch (_) {}

                    for (const record of eventRecords) {
                        if (!record.builder_data) continue;
                        try {
                            await executeFlowGraph(record.builder_data, context, this.client, guildId);
                        } catch (err) {
                            console.error(`[custom-event-service] Error in event "${record.name}" (${record.event_type}):`, err instanceof Error ? err.message : String(err));
                        }
                    }
                }
            };

            emitter.on(discordEvent, listener);
            this.listeners.set(discordEvent, listener);
        }
    }

    /**
     * Remove all registered listeners from the client/emitter.
     */
    unregisterListeners() {
        const emitter = this.client;
        if (!emitter?.off) return;
        for (const [discordEvent, listener] of this.listeners) {
            emitter.off(discordEvent, listener);
        }
        this.listeners.clear();
    }

    /**
     * Manually dispatch an event type with a pre-built context (for internal use).
     */
    async dispatch(eventType, context, guildId) {
        const records = this.events.get(eventType);
        if (!records?.length) return;
        for (const record of records) {
            if (!record.builder_data) continue;
            try {
                await executeFlowGraph(record.builder_data, context, this.client, guildId);
            } catch (err) {
                console.error(`[custom-event-service] dispatch error (${eventType}):`, err instanceof Error ? err.message : String(err));
            }
        }
    }
}

module.exports = { CustomEventService, EVENT_TYPE_MAP, executeFlowGraph };
