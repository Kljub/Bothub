'use strict';

const { SlashCommandBuilder } = require('discord.js');
const { loadSettings } = require('../../services/arcenciel-service');

const API_BASE = 'https://arcenciel.io/api';

module.exports = {
    key: 'autotag',

    data: new SlashCommandBuilder()
        .setName('autotag')
        .setDescription('Analysiert ein Bild und gibt passende Tags zurück (Arc en Ciel Tagger)')
        .addAttachmentOption(o =>
            o.setName('image')
                .setDescription('Bild zur Analyse')
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        const settings = await loadSettings(botId);

        if (!settings?.api_key || !settings.is_enabled) {
            return interaction.reply({
                content: '❌ ArcEnCiel ist nicht konfiguriert oder deaktiviert.',
                ephemeral: true,
            });
        }

        const attachment = interaction.options.getAttachment('image', true);

        await interaction.deferReply();

        try {
            const imgRes = await fetch(attachment.url);
            if (!imgRes.ok) throw new Error(`Bild nicht abrufbar: ${imgRes.status}`);
            const buf = Buffer.from(await imgRes.arrayBuffer());

            const ext = (attachment.name?.split('.').pop() ?? 'png').toLowerCase();
            const fd  = new FormData();
            fd.append('image', new Blob([buf], { type: `image/${ext}` }), attachment.name ?? 'image.png');
            fd.append('kind', 'TAGGER');

            const res = await fetch(`${API_BASE}/generator/autotag/interrogate`, {
                method: 'POST',
                headers: { 'x-api-key': settings.api_key },
                body: fd,
            });

            if (!res.ok) {
                let msg = String(res.status);
                try { const d = await res.json(); msg = d.error || d.message || msg; } catch (_) {}
                throw new Error(msg);
            }

            const data  = await res.json();
            const tags  = data.tags ?? [];
            const rating = data.rating ?? 'unknown';

            const tagList = tags.slice(0, 60).join(', ');
            const text = [
                `**Rating:** \`${rating}\``,
                `**Tags (${tags.length}):**`,
                tagList.length > 0 ? `\`\`\`${tagList}\`\`\`` : '_Keine Tags gefunden._',
            ].join('\n');

            await interaction.editReply({ content: text });
        } catch (err) {
            await interaction.editReply({ content: `❌ Autotag fehlgeschlagen: ${err.message}` }).catch(() => {});
        }
    },
};
