'use strict';

const { SlashCommandBuilder } = require('discord.js');
const {
    loadSettings,
    checkQuota,
    buildPayload,
    queueGeneration,
} = require('../../services/arcenciel-service');

module.exports = {
    key: 'imagine',

    data: new SlashCommandBuilder()
        .setName('imagine')
        .setDescription('Generiere ein Bild mit Arc en Ciel AI')
        .addStringOption(o =>
            o.setName('prompt')
                .setDescription('Beschreibung des Bildes')
                .setRequired(true)
                .setMaxLength(500)
        )
        .addStringOption(o =>
            o.setName('negative_prompt')
                .setDescription('Was vermieden werden soll')
                .setRequired(false)
                .setMaxLength(500)
        )
        .addIntegerOption(o =>
            o.setName('width')
                .setDescription('Breite in Pixel (Standard: 512)')
                .setRequired(false)
                .setMinValue(64)
                .setMaxValue(2048)
        )
        .addIntegerOption(o =>
            o.setName('height')
                .setDescription('Höhe in Pixel (Standard: 512)')
                .setRequired(false)
                .setMinValue(64)
                .setMaxValue(2048)
        )
        .addIntegerOption(o =>
            o.setName('steps')
                .setDescription('Generierungsschritte (Standard: 20)')
                .setRequired(false)
                .setMinValue(1)
                .setMaxValue(150)
        )
        .addNumberOption(o =>
            o.setName('cfg')
                .setDescription('CFG Scale — Treue zum Prompt (Standard: 7)')
                .setRequired(false)
                .setMinValue(1)
                .setMaxValue(30)
        )
        .addIntegerOption(o =>
            o.setName('seed')
                .setDescription('Seed für reproduzierbare Ergebnisse (-1 = zufällig)')
                .setRequired(false)
                .setMinValue(-1)
                .setMaxValue(2147483647)
        )
        .addStringOption(o =>
            o.setName('checkpoint')
                .setDescription('Modell / Checkpoint (überschreibt Standard)')
                .setRequired(false)
        ),

    async execute(interaction, botId) {
        const settings = await loadSettings(botId);

        if (!settings?.api_key || !settings.is_enabled) {
            return interaction.reply({
                content: '❌ ArcEnCiel ist nicht konfiguriert oder deaktiviert.',
                ephemeral: true,
            });
        }

        const userId = interaction.user.id;
        if (!checkQuota(botId, userId, settings.quota_per_hour ?? 10)) {
            return interaction.reply({
                content: `❌ Stundenlimit erreicht (${settings.quota_per_hour ?? 10} Bilder/Stunde). Bitte warte etwas.`,
                ephemeral: true,
            });
        }

        const prompt    = interaction.options.getString('prompt', true);
        const negPrompt = interaction.options.getString('negative_prompt') ?? settings.default_neg_prompt ?? '';
        const width     = interaction.options.getInteger('width')   ?? settings.default_width  ?? 512;
        const height    = interaction.options.getInteger('height')  ?? settings.default_height ?? 512;
        const steps     = interaction.options.getInteger('steps')   ?? settings.default_steps  ?? 20;
        const cfg       = interaction.options.getNumber('cfg')      ?? settings.default_cfg     ?? 7;
        const seed      = interaction.options.getInteger('seed')    ?? -1;
        const checkpoint = interaction.options.getString('checkpoint') ?? settings.default_checkpoint ?? '';

        const payload = buildPayload(prompt, {
            negativePrompt: negPrompt,
            width,
            height,
            steps,
            cfg,
            seed,
            checkpoint,
            vae:           settings.default_vae        ?? '',
        });

        await queueGeneration(interaction, botId, settings, payload, prompt, userId);
    },
};
