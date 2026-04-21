// PFAD: /core/installer/src/services/ticket-service.js
'use strict';

const {
    EmbedBuilder,
    ActionRowBuilder,
    ButtonBuilder,
    ButtonStyle,
    ChannelType,
    PermissionFlagsBits,
} = require('discord.js');
const { dbQuery } = require('../db');

// ── DB helpers ────────────────────────────────────────────────────────────────

async function getSettings(botId) {
    const rows = await dbQuery(
        'SELECT * FROM bot_ticket_settings WHERE bot_id = ? LIMIT 1',
        [Number(botId)]
    );
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
}

async function getTicketByChannel(botId, channelId) {
    const rows = await dbQuery(
        'SELECT * FROM bot_tickets WHERE bot_id = ? AND channel_id = ? LIMIT 1',
        [Number(botId), String(channelId)]
    );
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
}

async function isFeatureEnabled(botId, featureKey) {
    try {
        const rows = await dbQuery(
            'SELECT is_enabled FROM bot_ticket_features WHERE bot_id = ? AND feature_key = ? LIMIT 1',
            [Number(botId), String(featureKey)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return true; // default enabled
        return Number(rows[0].is_enabled) === 1;
    } catch (_) {
        return true;
    }
}

async function incrementTicketCount(botId) {
    await dbQuery(
        'UPDATE bot_ticket_settings SET ticket_count = ticket_count + 1 WHERE bot_id = ?',
        [Number(botId)]
    );
    const rows = await dbQuery(
        'SELECT ticket_count FROM bot_ticket_settings WHERE bot_id = ? LIMIT 1',
        [Number(botId)]
    );
    return Array.isArray(rows) && rows.length > 0 ? Number(rows[0].ticket_count || 0) : 0;
}

// ── Command handlers ──────────────────────────────────────────────────────────

async function handleSetup(interaction, botId) {
    if (!interaction.memberPermissions.has(PermissionFlagsBits.ManageGuild)) {
        await interaction.reply({ content: 'You need **Manage Server** permission to run this command.', ephemeral: true });
        return;
    }

    const channel = interaction.options.getChannel('channel', true);
    const title   = interaction.options.getString('title') || 'Support Tickets';
    const desc    = interaction.options.getString('description') || 'Click the button below to open a support ticket.';

    const embed = new EmbedBuilder()
        .setTitle(title)
        .setDescription(desc)
        .setColor(0x6366f1);

    const row = new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId('ticket_open')
            .setLabel('Open Ticket')
            .setStyle(ButtonStyle.Primary)
            .setEmoji('🎫')
    );

    try {
        await channel.send({ embeds: [embed], components: [row] });
        await interaction.reply({ content: `Ticket panel sent to ${channel}.`, ephemeral: true });
    } catch {
        await interaction.reply({ content: 'Could not send panel to that channel. Check my permissions.', ephemeral: true });
    }
}

async function handleCreate(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_create')) {
        await interaction.reply({ content: 'The `/ticket create` command is currently disabled.', ephemeral: true });
        return;
    }

    const guild    = interaction.guild;
    const settings = await getSettings(botId);

    if (!settings) {
        await interaction.reply({ content: 'Ticket system is not configured yet. Ask an admin to run `/ticket setup`.', ephemeral: true });
        return;
    }

    await interaction.deferReply({ ephemeral: true });

    try {
        const ticketNum = await incrementTicketCount(botId);
        const channelName = `ticket-${String(ticketNum).padStart(4, '0')}`;

        const permissionOverwrites = [
            { id: guild.id, deny: [PermissionFlagsBits.ViewChannel] },
            { id: interaction.user.id, allow: [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages] },
        ];

        if (settings.support_role_id) {
            permissionOverwrites.push({
                id: settings.support_role_id,
                allow: [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages],
            });
        }

        const ticketChannel = await guild.channels.create({
            name: channelName,
            type: ChannelType.GuildText,
            parent: settings.category_id || null,
            permissionOverwrites,
        });

        await dbQuery(
            `INSERT INTO bot_tickets (bot_id, guild_id, ticket_num, channel_id, creator_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ticket_num = VALUES(ticket_num)`,
            [Number(botId), guild.id, ticketNum, ticketChannel.id, interaction.user.id]
        );

        // Send open message
        const openMsg = String(settings.open_message || 'Thanks for creating a ticket!\nSupport will be with you shortly.');
        const embed   = new EmbedBuilder()
            .setDescription(openMsg)
            .setColor(0x6366f1)
            .setFooter({ text: `Ticket #${String(ticketNum).padStart(4, '0')}` });

        const closeRow = new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId('ticket_close')
                .setLabel('Close Ticket')
                .setStyle(ButtonStyle.Danger)
                .setEmoji('🔒')
        );

        await ticketChannel.send({
            content: `${interaction.user} — ${settings.support_role_id ? `<@&${settings.support_role_id}>` : ''}`,
            embeds: [embed],
            components: [closeRow],
        });

        await interaction.editReply({ content: `Your ticket has been created: ${ticketChannel}` });
    } catch (err) {
        await interaction.editReply({ content: `Failed to create ticket: ${err instanceof Error ? err.message : String(err)}` });
    }
}

async function handleClose(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_close')) {
        await interaction.reply({ content: 'The `/ticket close` command is currently disabled.', ephemeral: true });
        return;
    }

    const ticket = await getTicketByChannel(botId, interaction.channelId);
    if (!ticket) {
        await interaction.reply({ content: 'This channel is not a ticket.', ephemeral: true });
        return;
    }
    if (ticket.resolved) {
        await interaction.reply({ content: 'This ticket is already closed.', ephemeral: true });
        return;
    }

    const reason = interaction.options.getString('reason') || 'No reason provided';

    await dbQuery(
        'UPDATE bot_tickets SET resolved = 1 WHERE bot_id = ? AND channel_id = ?',
        [Number(botId), interaction.channelId]
    );

    await interaction.reply({ content: `Ticket closed. Reason: ${reason}` });

    try {
        await interaction.channel.permissionOverwrites.edit(ticket.creator_id, {
            SendMessages: false,
        });
    } catch (_) {}

    // Send DM to creator
    try {
        const settings = await getSettings(botId);
        const dmMsg    = settings ? String(settings.dm_message || '') : '';
        if (dmMsg) {
            const creator = await interaction.client.users.fetch(ticket.creator_id);
            await creator.send(dmMsg);
        }
    } catch (_) {}
}

async function handleReopen(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_reopen')) {
        await interaction.reply({ content: 'The `/ticket reopen` command is currently disabled.', ephemeral: true });
        return;
    }

    const ticket = await getTicketByChannel(botId, interaction.channelId);
    if (!ticket) {
        await interaction.reply({ content: 'This channel is not a ticket.', ephemeral: true });
        return;
    }
    if (!ticket.resolved) {
        await interaction.reply({ content: 'This ticket is already open.', ephemeral: true });
        return;
    }

    await dbQuery(
        'UPDATE bot_tickets SET resolved = 0 WHERE bot_id = ? AND channel_id = ?',
        [Number(botId), interaction.channelId]
    );

    try {
        await interaction.channel.permissionOverwrites.edit(ticket.creator_id, {
            SendMessages: true,
            ViewChannel:  true,
        });
    } catch (_) {}

    await interaction.reply({ content: 'Ticket reopened.' });
}

async function handleDelete(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_delete')) {
        await interaction.reply({ content: 'The `/ticket delete` command is currently disabled.', ephemeral: true });
        return;
    }

    if (!interaction.memberPermissions.has(PermissionFlagsBits.ManageChannels)) {
        await interaction.reply({ content: 'You need **Manage Channels** permission to delete tickets.', ephemeral: true });
        return;
    }

    const ticket = await getTicketByChannel(botId, interaction.channelId);
    if (!ticket) {
        await interaction.reply({ content: 'This channel is not a ticket.', ephemeral: true });
        return;
    }

    await interaction.reply({ content: 'Deleting ticket in 5 seconds…' });
    await new Promise(r => setTimeout(r, 5000));

    await dbQuery(
        'DELETE FROM bot_tickets WHERE bot_id = ? AND channel_id = ?',
        [Number(botId), interaction.channelId]
    );

    try {
        await interaction.channel.delete('Ticket deleted via /ticket delete');
    } catch (_) {}
}

async function handleAdd(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_add')) {
        await interaction.reply({ content: 'The `/ticket add` command is currently disabled.', ephemeral: true });
        return;
    }

    const ticket = await getTicketByChannel(botId, interaction.channelId);
    if (!ticket) {
        await interaction.reply({ content: 'This channel is not a ticket.', ephemeral: true });
        return;
    }

    const user = interaction.options.getUser('user', true);
    try {
        await interaction.channel.permissionOverwrites.edit(user.id, {
            ViewChannel:  true,
            SendMessages: true,
        });
        await interaction.reply({ content: `Added ${user} to the ticket.` });
    } catch {
        await interaction.reply({ content: 'Could not add user to the ticket.', ephemeral: true });
    }
}

async function handleRemove(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_remove')) {
        await interaction.reply({ content: 'The `/ticket remove` command is currently disabled.', ephemeral: true });
        return;
    }

    const ticket = await getTicketByChannel(botId, interaction.channelId);
    if (!ticket) {
        await interaction.reply({ content: 'This channel is not a ticket.', ephemeral: true });
        return;
    }

    const user = interaction.options.getUser('user', true);
    try {
        await interaction.channel.permissionOverwrites.delete(user.id);
        await interaction.reply({ content: `Removed ${user} from the ticket.` });
    } catch {
        await interaction.reply({ content: 'Could not remove user from the ticket.', ephemeral: true });
    }
}

async function handleAutomation(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_automation')) {
        await interaction.reply({ content: 'The `/ticket automation` command is currently disabled.', ephemeral: true });
        return;
    }

    const embed = new EmbedBuilder()
        .setTitle('Ticket Automation')
        .setDescription('Configure automated actions in the dashboard under **Ticket System → Events**.')
        .setColor(0x6366f1);

    await interaction.reply({ embeds: [embed], ephemeral: true });
}

async function handleUpdateCounts(interaction, botId) {
    if (!await isFeatureEnabled(botId, 'cmd_update_counts')) {
        await interaction.reply({ content: 'The `/ticket update-counts` command is currently disabled.', ephemeral: true });
        return;
    }

    if (!interaction.memberPermissions.has(PermissionFlagsBits.ManageGuild)) {
        await interaction.reply({ content: 'You need **Manage Server** permission to run this command.', ephemeral: true });
        return;
    }

    await interaction.deferReply({ ephemeral: true });

    try {
        const openRows = await dbQuery(
            'SELECT COUNT(*) AS total FROM bot_tickets WHERE bot_id = ? AND guild_id = ? AND resolved = 0',
            [Number(botId), interaction.guildId]
        );
        const totalRows = await dbQuery(
            'SELECT COUNT(*) AS total FROM bot_tickets WHERE bot_id = ? AND guild_id = ?',
            [Number(botId), interaction.guildId]
        );

        const openCount  = openRows[0]  ? Number(openRows[0].total)  : 0;
        const totalCount = totalRows[0] ? Number(totalRows[0].total) : 0;

        await interaction.editReply({
            content: `Ticket counts updated.\n**Open:** ${openCount}\n**Total:** ${totalCount}`,
        });
    } catch (err) {
        await interaction.editReply({ content: `Failed: ${err instanceof Error ? err.message : String(err)}` });
    }
}

// ── Button handler (ticket_open / ticket_close) ───────────────────────────────

async function handleTicketButton(interaction, botId) {
    const id = interaction.customId;

    if (id === 'ticket_open') {
        // Simulate create via button press
        await interaction.deferReply({ ephemeral: true });

        const guild    = interaction.guild;
        const settings = await getSettings(botId);

        if (!settings) {
            await interaction.editReply({ content: 'Ticket system is not configured.' });
            return;
        }

        // Check if user already has an open ticket
        const existing = await dbQuery(
            'SELECT id FROM bot_tickets WHERE bot_id = ? AND guild_id = ? AND creator_id = ? AND resolved = 0 LIMIT 1',
            [Number(botId), guild.id, interaction.user.id]
        );
        if (Array.isArray(existing) && existing.length > 0) {
            await interaction.editReply({ content: 'You already have an open ticket.' });
            return;
        }

        try {
            const ticketNum = await incrementTicketCount(botId);
            const channelName = `ticket-${String(ticketNum).padStart(4, '0')}`;

            const permissionOverwrites = [
                { id: guild.id, deny: [PermissionFlagsBits.ViewChannel] },
                { id: interaction.user.id, allow: [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages] },
            ];
            if (settings.support_role_id) {
                permissionOverwrites.push({
                    id: settings.support_role_id,
                    allow: [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages],
                });
            }

            const ticketChannel = await guild.channels.create({
                name: channelName,
                type: ChannelType.GuildText,
                parent: settings.category_id || null,
                permissionOverwrites,
            });

            await dbQuery(
                `INSERT INTO bot_tickets (bot_id, guild_id, ticket_num, channel_id, creator_id)
                 VALUES (?, ?, ?, ?, ?)`,
                [Number(botId), guild.id, ticketNum, ticketChannel.id, interaction.user.id]
            );

            const openMsg = String(settings.open_message || 'Thanks for creating a ticket!\nSupport will be with you shortly.');
            const embed   = new EmbedBuilder()
                .setDescription(openMsg)
                .setColor(0x6366f1)
                .setFooter({ text: `Ticket #${String(ticketNum).padStart(4, '0')}` });

            const closeRow = new ActionRowBuilder().addComponents(
                new ButtonBuilder()
                    .setCustomId('ticket_close')
                    .setLabel('Close Ticket')
                    .setStyle(ButtonStyle.Danger)
                    .setEmoji('🔒')
            );

            await ticketChannel.send({
                content: `${interaction.user}${settings.support_role_id ? ` <@&${settings.support_role_id}>` : ''}`,
                embeds: [embed],
                components: [closeRow],
            });

            await interaction.editReply({ content: `Ticket created: ${ticketChannel}` });
        } catch (err) {
            await interaction.editReply({ content: `Failed to create ticket: ${err instanceof Error ? err.message : String(err)}` });
        }
        return;
    }

    if (id === 'ticket_close') {
        const ticket = await getTicketByChannel(botId, interaction.channelId);
        if (!ticket || ticket.resolved) {
            await interaction.reply({ content: 'This ticket is already closed or does not exist.', ephemeral: true });
            return;
        }

        await dbQuery(
            'UPDATE bot_tickets SET resolved = 1 WHERE bot_id = ? AND channel_id = ?',
            [Number(botId), interaction.channelId]
        );

        await interaction.reply({ content: 'Ticket closed.' });

        try {
            await interaction.channel.permissionOverwrites.edit(ticket.creator_id, { SendMessages: false });
        } catch (_) {}

        try {
            const settings = await getSettings(botId);
            const dmMsg    = settings ? String(settings.dm_message || '') : '';
            if (dmMsg) {
                const creator = await interaction.client.users.fetch(ticket.creator_id);
                await creator.send(dmMsg);
            }
        } catch (_) {}
    }
}

// ── Event handlers ────────────────────────────────────────────────────────────

async function handleCreatorLeaves(member, botId) {
    if (!await isFeatureEnabled(botId, 'evt_creator_leaves_1') &&
        !await isFeatureEnabled(botId, 'evt_creator_leaves_2')) return;

    try {
        const openTickets = await dbQuery(
            'SELECT * FROM bot_tickets WHERE bot_id = ? AND guild_id = ? AND creator_id = ? AND resolved = 0',
            [Number(botId), member.guild.id, member.user.id]
        );

        for (const ticket of openTickets) {
            const channel = member.guild.channels.cache.get(ticket.channel_id);
            if (!channel) continue;

            if (await isFeatureEnabled(botId, 'evt_creator_leaves_1')) {
                await channel.send(`⚠️ The ticket creator <@${member.user.id}> has left the server.`).catch(() => {});
            }

            if (await isFeatureEnabled(botId, 'evt_creator_leaves_2')) {
                // Auto-close ticket when creator leaves
                await dbQuery(
                    'UPDATE bot_tickets SET resolved = 1 WHERE bot_id = ? AND channel_id = ?',
                    [Number(botId), ticket.channel_id]
                );
                await channel.send('Ticket automatically closed because the creator left the server.').catch(() => {});
            }
        }
    } catch (err) {
        console.error(`[ticket-service] creatorLeaves error (bot ${botId}):`, err instanceof Error ? err.message : String(err));
    }
}

async function handleTranscriptMessage(message, botId, eventKey) {
    if (!await isFeatureEnabled(botId, eventKey)) return;

    try {
        const ticket = await getTicketByChannel(botId, message.channel.id);
        if (!ticket || ticket.resolved) return;

        const settings = await getSettings(botId);
        if (!settings || !settings.log_channel_id) return;

        const logChannel = message.guild.channels.cache.get(settings.log_channel_id);
        if (!logChannel) return;

        const actionMap = {
            evt_transcripts_new:    'New Message',
            evt_transcripts_update: 'Message Updated',
            evt_transcripts_delete: 'Message Deleted',
        };

        const embed = new EmbedBuilder()
            .setTitle(`Transcript — ${actionMap[eventKey] || eventKey}`)
            .addFields(
                { name: 'Ticket', value: `<#${ticket.channel_id}> (#${String(ticket.ticket_num).padStart(4, '0')})`, inline: true },
                { name: 'User',   value: `<@${message.author ? message.author.id : 'unknown'}>`, inline: true }
            )
            .setDescription(message.content ? String(message.content).slice(0, 1000) : '*[no text content]*')
            .setColor(0x6366f1)
            .setTimestamp();

        await logChannel.send({ embeds: [embed] });
    } catch (err) {
        console.error(`[ticket-service] transcript (${eventKey}) error (bot ${botId}):`, err instanceof Error ? err.message : String(err));
    }
}

// ── Attach Discord.js event listeners ────────────────────────────────────────

function attachTicketEvents(client, botId) {
    client.on('guildMemberRemove', async (member) => {
        try {
            await handleCreatorLeaves(member, botId);
        } catch (_) {}
    });

    client.on('messageCreate', async (message) => {
        if (!message.guild || message.author.bot) return;
        try {
            await handleTranscriptMessage(message, botId, 'evt_transcripts_new');
        } catch (_) {}
    });

    client.on('messageUpdate', async (oldMessage, newMessage) => {
        if (!newMessage.guild) return;
        const msg = newMessage.partial ? oldMessage : newMessage;
        if (!msg || msg.author.bot) return;
        try {
            await handleTranscriptMessage(msg, botId, 'evt_transcripts_update');
        } catch (_) {}
    });

    client.on('messageDelete', async (message) => {
        if (!message.guild) return;
        if (message.author && message.author.bot) return;
        try {
            await handleTranscriptMessage(message, botId, 'evt_transcripts_delete');
        } catch (_) {}
    });
}

module.exports = {
    handleSetup,
    handleCreate,
    handleClose,
    handleReopen,
    handleDelete,
    handleAdd,
    handleRemove,
    handleAutomation,
    handleUpdateCounts,
    handleTicketButton,
    attachTicketEvents,
};
