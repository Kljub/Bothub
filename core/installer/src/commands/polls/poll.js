// PFAD: /core/installer/src/commands/poll.js
const { SlashCommandBuilder, EmbedBuilder, PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../../db');

async function getPollsSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_polls_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) {
        return null;
    }
}

async function isPollCommandEnabled(botId, commandKey) {
    try {
        const rows = await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), commandKey]
        );
        if (!Array.isArray(rows) || rows.length === 0) return true;
        return Number(rows[0].is_enabled) === 1;
    } catch (_) {
        return true;
    }
}

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return Array.isArray(arr) ? arr : [];
    } catch (_) {
        return [];
    }
}

const DEFAULT_EMOJIS = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣'];

module.exports = {
    key: 'poll-create',

    data: new SlashCommandBuilder()
        .setName('poll-create')
        .setDescription('Create a poll with up to 6 choices.')
        .addStringOption(o =>
            o.setName('question').setDescription('The poll question').setRequired(true)
        )
        .addStringOption(o =>
            o.setName('choice1').setDescription('Choice 1').setRequired(true)
        )
        .addStringOption(o =>
            o.setName('choice2').setDescription('Choice 2').setRequired(true)
        )
        .addStringOption(o =>
            o.setName('choice3').setDescription('Choice 3')
        )
        .addStringOption(o =>
            o.setName('choice4').setDescription('Choice 4')
        )
        .addStringOption(o =>
            o.setName('choice5').setDescription('Choice 5')
        )
        .addStringOption(o =>
            o.setName('choice6').setDescription('Choice 6')
        ),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Only usable in a server.', ephemeral: true });
        }

        if (!await isPollCommandEnabled(botId, 'poll-create')) {
            return interaction.reply({ content: '❌ Der `/poll-create` Befehl ist deaktiviert.', ephemeral: true });
        }

        const settings = await getPollsSettings(botId);

        // Check channel whitelist
        if (settings) {
            const wl = parseJsonArray(settings.whitelisted_channels);
            if (wl.length > 0 && !wl.includes(interaction.channelId)) {
                return interaction.reply({ content: '❌ Polls are not allowed in this channel.', ephemeral: true });
            }
        }

        // Check manager roles
        if (settings) {
            const managerRoles = parseJsonArray(settings.manager_roles);
            if (managerRoles.length > 0) {
                const member = interaction.member;
                const memberRoles = member && member.roles
                    ? (member.roles.cache ? [...member.roles.cache.keys()] : [])
                    : [];
                const hasRole = managerRoles.some(r => memberRoles.includes(r));
                const isAdmin = member && member.permissions
                    ? member.permissions.has(PermissionFlagsBits.ManageGuild)
                    : false;
                if (!hasRole && !isAdmin) {
                    return interaction.reply({ content: '❌ You are not allowed to create polls.', ephemeral: true });
                }
            }
        }

        const question = interaction.options.getString('question', true);
        const choices = [];
        for (let i = 1; i <= 6; i++) {
            const c = interaction.options.getString(`choice${i}`);
            if (c) choices.push(c);
        }

        const reactionEmojis = settings ? parseJsonArray(settings.choice_reactions) : [];
        const emojis = reactionEmojis.length >= choices.length
            ? reactionEmojis.slice(0, choices.length)
            : DEFAULT_EMOJIS.slice(0, choices.length);

        // Build embed
        const embedColor = settings && settings.embed_color ? String(settings.embed_color).trim() : '#EE3636';
        const validColor = /^#[0-9a-fA-F]{6}$/.test(embedColor) ? embedColor : '#EE3636';

        const rawTitle = settings && settings.embed_title
            ? String(settings.embed_title)
            : '🗳️ Poll - {poll.question}';
        const embedTitle = rawTitle.replace(/\{poll\.question\}/gi, question);

        const rawFooter = settings && settings.embed_footer
            ? String(settings.embed_footer)
            : 'Participate in the poll by reacting with one of the options specified below.';

        const choicesText = choices
            .map((c, i) => `${emojis[i]}  ${c}`)
            .join('\n');

        const embed = new EmbedBuilder()
            .setColor(validColor)
            .setTitle(embedTitle)
            .setDescription(choicesText)
            .setFooter({ text: rawFooter });

        if (settings && Number(settings.show_poster_name) === 1) {
            embed.setAuthor({
                name: interaction.user.username,
                iconURL: interaction.user.displayAvatarURL({ size: 64 }),
            });
        }

        embed.setTimestamp();

        await interaction.deferReply();
        const msg = await interaction.fetchReply().catch(() => null);

        // Send embed
        const sent = await interaction.editReply({ embeds: [embed] });
        const sentMsg = sent || await interaction.fetchReply().catch(() => null);

        // Add reactions
        if (sentMsg) {
            for (const emoji of emojis) {
                try { await sentMsg.react(emoji); } catch (_) { /* ignore */ }
            }
        }

        // Store poll in DB
        try {
            const res = await dbQuery(
                `INSERT INTO bot_polls
                    (bot_id, guild_id, channel_id, message_id, question, choices, creator_user_id, creator_username)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
                [
                    Number(botId),
                    interaction.guildId,
                    interaction.channelId,
                    sentMsg ? sentMsg.id : '',
                    question,
                    JSON.stringify(choices.map((c, i) => ({ emoji: emojis[i], text: c }))),
                    interaction.user.id,
                    interaction.user.username,
                ]
            );
        } catch (e) {
            console.error('[Polls] Failed to store poll:', e?.message);
        }
    },
};
