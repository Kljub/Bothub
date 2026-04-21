// PFAD: /core/installer/src/commands/avatar.js

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');

module.exports = {
    key: 'avatar',

    data: new SlashCommandBuilder()
        .setName('avatar')
        .setDescription('Shows the avatar of a user.')
        .addUserOption(option =>
            option
                .setName('user')
                .setDescription('The user whose avatar to show (leave empty for your own)')
                .setRequired(false)
        ),

    async execute(interaction) {
        const targetUser   = interaction.options.getUser('user') || interaction.user;
        const targetMember = interaction.options.getMember('user') ||
                             (interaction.inGuild() ? interaction.member : null);

        // Global avatar URL
        const globalAvatar = targetUser.displayAvatarURL({ size: 1024, forceStatic: false });

        // Guild-specific avatar (may differ from global avatar)
        const guildAvatar = targetMember?.displayAvatarURL
            ? targetMember.displayAvatarURL({ size: 1024, forceStatic: false })
            : null;

        const embed = new EmbedBuilder()
            .setTitle(`${targetUser.username}'s Avatar`)
            .setImage(guildAvatar || globalAvatar)
            .setColor(0x5865f2);

        // If the user has a different guild avatar, show both
        if (guildAvatar && guildAvatar !== globalAvatar) {
            embed.setDescription(
                `[Global Avatar](${globalAvatar}) · [Server Avatar](${guildAvatar})`
            );
        } else {
            embed.setDescription(`[Open full size](${globalAvatar})`);
        }

        await interaction.reply({ embeds: [embed] });
    },
};
