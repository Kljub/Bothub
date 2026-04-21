// PFAD: /core/installer/src/commands/ban.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'ban',

    data: new SlashCommandBuilder()
        .setName('ban')
        .setDescription('Ban commands')
        .addSubcommand(sub =>
            sub.setName('add')
                .setDescription('Ban a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
                .addStringOption(opt => opt.setName('reason').setDescription('Reason').setRequired(false))
        )
        .addSubcommand(sub =>
            sub.setName('temp')
                .setDescription('Temp-ban a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
                .addStringOption(opt => opt.setName('duration').setDescription('Duration').setRequired(true))
                .addStringOption(opt => opt.setName('reason').setDescription('Reason').setRequired(false))
        )
        .addSubcommand(sub =>
            sub.setName('remove')
                .setDescription('Unban a user')
                .addStringOption(opt => opt.setName('user_id').setDescription('User ID').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('list')
                .setDescription('List bans')
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();

        if (sub === 'add') {
            const user = interaction.options.getUser('user', true);
            const reason = interaction.options.getString('reason') || 'No reason provided';

            try {
                await interaction.guild.members.ban(user.id, { reason });
                await interaction.reply({ content: `Banned ${user.tag}.`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not ban user.', ephemeral: true });
            }
            return;
        }

        if (sub === 'temp') {
            const user = interaction.options.getUser('user', true);
            const duration = interaction.options.getString('duration', true);
            const reason = interaction.options.getString('reason') || 'No reason provided';

            try {
                await interaction.guild.members.ban(user.id, { reason: `[TEMP ${duration}] ${reason}` });
                await interaction.reply({ content: `Temp-banned ${user.tag} for ${duration}.`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not temp-ban user.', ephemeral: true });
            }
            return;
        }

        if (sub === 'remove') {
            const userId = interaction.options.getString('user_id', true);

            try {
                await interaction.guild.members.unban(userId);
                await interaction.reply({ content: `Removed ban for ${userId}.`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not remove ban.', ephemeral: true });
            }
            return;
        }

        if (sub === 'list') {
            try {
                const bans = await interaction.guild.bans.fetch();
                const content = bans.size === 0
                    ? 'No active bans.'
                    : `Active bans: ${bans.map((b) => `${b.user.tag}`).join(', ')}`;
                await interaction.reply({ content, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not fetch bans.', ephemeral: true });
            }
        }
    }
};
