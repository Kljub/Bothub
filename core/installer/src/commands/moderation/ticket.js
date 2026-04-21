// PFAD: /core/installer/src/commands/moderation/ticket.js
'use strict';

const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const ticketService = require('../../services/ticket-service');

module.exports = {
    key: 'ticket',

    data: new SlashCommandBuilder()
        .setName('ticket')
        .setDescription('Ticket system commands')
        .addSubcommand(sub =>
            sub.setName('setup')
                .setDescription('Send a ticket panel to a channel')
                .addChannelOption(opt =>
                    opt.setName('channel')
                        .setDescription('Channel to send the panel to')
                        .setRequired(true)
                )
                .addStringOption(opt =>
                    opt.setName('title')
                        .setDescription('Panel title')
                        .setRequired(false)
                )
                .addStringOption(opt =>
                    opt.setName('description')
                        .setDescription('Panel description')
                        .setRequired(false)
                )
        )
        .addSubcommand(sub =>
            sub.setName('create')
                .setDescription('Create a ticket')
                .addStringOption(opt =>
                    opt.setName('reason')
                        .setDescription('Reason for opening the ticket')
                        .setRequired(false)
                )
        )
        .addSubcommand(sub =>
            sub.setName('close')
                .setDescription('Close the current ticket')
                .addStringOption(opt =>
                    opt.setName('reason')
                        .setDescription('Reason for closing')
                        .setRequired(false)
                )
        )
        .addSubcommand(sub =>
            sub.setName('reopen')
                .setDescription('Reopen the current ticket')
        )
        .addSubcommand(sub =>
            sub.setName('delete')
                .setDescription('Delete the current ticket channel')
        )
        .addSubcommand(sub =>
            sub.setName('add')
                .setDescription('Add a user to the current ticket')
                .addUserOption(opt =>
                    opt.setName('user')
                        .setDescription('User to add')
                        .setRequired(true)
                )
        )
        .addSubcommand(sub =>
            sub.setName('remove')
                .setDescription('Remove a user from the current ticket')
                .addUserOption(opt =>
                    opt.setName('user')
                        .setDescription('User to remove')
                        .setRequired(true)
                )
        )
        .addSubcommand(sub =>
            sub.setName('automation')
                .setDescription('Show automated actions configured for this ticket')
        )
        .addSubcommand(sub =>
            sub.setName('update-counts')
                .setDescription('Update ticket count channels for this bot')
        ),

    async execute(interaction, botId) {
        const sub = interaction.options.getSubcommand();

        if (sub === 'setup') {
            await ticketService.handleSetup(interaction, botId);
            return;
        }

        if (sub === 'create') {
            await ticketService.handleCreate(interaction, botId);
            return;
        }

        if (sub === 'close') {
            await ticketService.handleClose(interaction, botId);
            return;
        }

        if (sub === 'reopen') {
            await ticketService.handleReopen(interaction, botId);
            return;
        }

        if (sub === 'delete') {
            await ticketService.handleDelete(interaction, botId);
            return;
        }

        if (sub === 'add') {
            await ticketService.handleAdd(interaction, botId);
            return;
        }

        if (sub === 'remove') {
            await ticketService.handleRemove(interaction, botId);
            return;
        }

        if (sub === 'automation') {
            await ticketService.handleAutomation(interaction, botId);
            return;
        }

        if (sub === 'update-counts') {
            await ticketService.handleUpdateCounts(interaction, botId);
            return;
        }

        await interaction.reply({ content: 'Unknown subcommand.', ephemeral: true });
    },
};
