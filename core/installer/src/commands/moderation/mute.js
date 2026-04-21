// PFAD: /core/installer/src/commands/mute.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'mute',

    data: new SlashCommandBuilder()
        .setName('mute')
        .setDescription('Mute commands')
        .addSubcommand(sub =>
            sub.setName('add')
                .setDescription('Timeout a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
                .addIntegerOption(opt => opt.setName('minutes').setDescription('Minutes').setRequired(true).setMinValue(1).setMaxValue(40320))
                .addStringOption(opt => opt.setName('reason').setDescription('Reason').setRequired(false))
        )
        .addSubcommand(sub =>
            sub.setName('remove')
                .setDescription('Remove timeout from a user')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('view')
                .setDescription('View timeout info')
                .addUserOption(opt => opt.setName('user').setDescription('User').setRequired(true))
        )
        .addSubcommand(sub =>
            sub.setName('role')
                .setDescription('Set mute role placeholder')
                .addRoleOption(opt => opt.setName('role').setDescription('Role').setRequired(true))
        ),

    async execute(interaction) {
        const sub = interaction.options.getSubcommand();

        if (sub === 'add') {
            const user = interaction.options.getUser('user', true);
            const minutes = interaction.options.getInteger('minutes', true);
            const reason = interaction.options.getString('reason') || 'No reason provided';
            const member = await interaction.guild.members.fetch(user.id).catch(() => null);

            if (!member) {
                await interaction.reply({ content: 'User not found.', ephemeral: true });
                return;
            }

            try {
                await member.timeout(minutes * 60 * 1000, reason);
                await interaction.reply({ content: `Muted ${user.tag} for ${minutes} minute(s).`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not mute user.', ephemeral: true });
            }
            return;
        }

        if (sub === 'remove') {
            const user = interaction.options.getUser('user', true);
            const member = await interaction.guild.members.fetch(user.id).catch(() => null);

            if (!member) {
                await interaction.reply({ content: 'User not found.', ephemeral: true });
                return;
            }

            try {
                await member.timeout(null);
                await interaction.reply({ content: `Removed mute from ${user.tag}.`, ephemeral: true });
            } catch {
                await interaction.reply({ content: 'Could not remove mute.', ephemeral: true });
            }
            return;
        }

        if (sub === 'view') {
            const user = interaction.options.getUser('user', true);
            const member = await interaction.guild.members.fetch(user.id).catch(() => null);

            if (!member) {
                await interaction.reply({ content: 'User not found.', ephemeral: true });
                return;
            }

            const until = member.communicationDisabledUntil
                ? member.communicationDisabledUntil.toISOString()
                : 'Not muted';

            await interaction.reply({
                content: `${user.tag} mute status: ${until}`,
                ephemeral: true
            });
            return;
        }

        if (sub === 'role') {
            const role = interaction.options.getRole('role', true);

            await interaction.reply({
                content: `Mute role set to ${role.name} (placeholder).`,
                ephemeral: true
            });
        }
    }
};
