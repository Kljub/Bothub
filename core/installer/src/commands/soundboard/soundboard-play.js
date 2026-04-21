const { SlashCommandBuilder } = require('discord.js');
const { dbQuery } = require('../../db');
const { playSound } = require('../../services/soundboard-service');

async function isCommandEnabled(botId, key) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [Number(botId), key]
    );
    if (!rows || rows.length === 0) return true;
    return Number(rows[0].is_enabled) === 1;
}

module.exports = {
    key: 'soundboard-play',

    data: new SlashCommandBuilder()
        .setName('soundboard-play')
        .setDescription('Spielt einen Sound aus dem Soundboard ab.')
        .addStringOption((o) =>
            o.setName('sound')
                .setDescription('Name des Sounds')
                .setRequired(true)
                .setAutocomplete(true)
        ),

    async autocomplete(interaction, botId) {
        try {
            const focused = String(interaction.options.getFocused() || '').toLowerCase();
            const rows = await dbQuery(
                'SELECT id, name FROM bot_soundboard_sounds WHERE bot_id = ? ORDER BY name ASC LIMIT 100',
                [Number(botId)]
            );
            const choices = (rows || [])
                .filter((r) => String(r.name || '').toLowerCase().includes(focused))
                .slice(0, 25)
                .map((r) => ({ name: String(r.name), value: String(r.id) }));
            await interaction.respond(choices);
        } catch (_) {
            try { await interaction.respond([]); } catch (_2) {}
        }
    },

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'soundboard-play')) {
            await interaction.reply({ content: 'Der `/soundboard-play` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const voiceChannel = interaction.member?.voice?.channel;
        if (!voiceChannel) {
            await interaction.reply({ content: '❌ Du musst in einem Voice Channel sein.', ephemeral: true });
            return;
        }

        const soundIdStr = interaction.options.getString('sound', true);
        const soundId    = parseInt(soundIdStr, 10);

        if (!Number.isInteger(soundId) || soundId <= 0) {
            await interaction.reply({ content: '❌ Ungültiger Sound.', ephemeral: true });
            return;
        }

        const rows = await dbQuery(
            'SELECT id, name, emoji, volume, file_data FROM bot_soundboard_sounds WHERE id = ? AND bot_id = ? LIMIT 1',
            [soundId, Number(botId)]
        );

        if (!rows || rows.length === 0) {
            await interaction.reply({ content: '❌ Sound nicht gefunden.', ephemeral: true });
            return;
        }

        const sound      = rows[0];
        const fileData   = Buffer.isBuffer(sound.file_data) ? sound.file_data : Buffer.from(sound.file_data || '');

        if (fileData.length === 0) {
            await interaction.reply({ content: '❌ Keine Audiodaten für diesen Sound vorhanden.', ephemeral: true });
            return;
        }

        await interaction.deferReply();

        try {
            await playSound(
                interaction.client,
                interaction.guildId,
                voiceChannel.id,
                fileData,
                Number(sound.volume || 100),
                String(sound.name),
                Number(botId)
            );

            const emoji = String(sound.emoji || '🔊');
            await interaction.editReply(`${emoji} **${sound.name}** wird abgespielt.`);
        } catch (err) {
            await interaction.editReply(`❌ Fehler: ${err.message}`);
        }
    },
};
