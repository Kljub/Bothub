'use strict';

const {
    EmbedBuilder,
    AttachmentBuilder,
    ActionRowBuilder,
    ButtonBuilder,
    ButtonStyle,
} = require('discord.js');
const { dbQuery } = require('../db');

const API_BASE     = 'https://arcenciel.io/api';
const JOB_TIMEOUT  = 10 * 60 * 1000; // 10 min
const POLL_MS      = 2_000;           // poll every 2s
const UPDATE_EVERY = 5_000;           // throttle progress messages

// ── In-process state ──────────────────────────────────────────────────────────
// jobId → { botId, apiKey, interaction, prompt, payload, startedAt, lastUpdate, lastPct }
const activeJobs = new Map();

// shortKey → { prompt, neg, width, height, steps, cfg, checkpoint }
const rerollCache = new Map();

// botId → intervalId
const pollers    = new Map();

// ── Table init ────────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_arcenciel_settings\` (
        \`bot_id\`              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        \`api_key\`             TEXT            NULL DEFAULT NULL,
        \`is_enabled\`          TINYINT(1)      NOT NULL DEFAULT 1,
        \`default_checkpoint\`  VARCHAR(200)    NOT NULL DEFAULT '',
        \`default_vae\`         VARCHAR(200)    NOT NULL DEFAULT '',
        \`default_neg_prompt\`  TEXT            NULL DEFAULT NULL,
        \`default_width\`       SMALLINT UNSIGNED NOT NULL DEFAULT 512,
        \`default_height\`      SMALLINT UNSIGNED NOT NULL DEFAULT 512,
        \`default_steps\`       TINYINT UNSIGNED  NOT NULL DEFAULT 20,
        \`default_cfg\`         DECIMAL(4,2)    NOT NULL DEFAULT 7.00,
        \`default_sampler\`     VARCHAR(100)    NOT NULL DEFAULT '',
        \`default_scheduler\`   VARCHAR(100)    NOT NULL DEFAULT '',
        \`nsfw_enabled\`        TINYINT(1)      NOT NULL DEFAULT 0,
        \`quota_per_hour\`      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        \`created_at\`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        \`updated_at\`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

// ── Load settings from DB ─────────────────────────────────────────────────────
async function loadSettings(botId) {
    const rows = await dbQuery('SELECT * FROM bot_arcenciel_settings WHERE bot_id = ? LIMIT 1', [botId]);
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
}

// ── API helpers ───────────────────────────────────────────────────────────────
async function apiGet(apiKey, path) {
    const res = await fetch(`${API_BASE}${path}`, {
        headers: { 'x-api-key': apiKey },
    });
    if (!res.ok) {
        const txt = await res.text().catch(() => String(res.status));
        throw new Error(`ArcEnCiel ${res.status}: ${txt.slice(0, 200)}`);
    }
    return res.json();
}

async function apiPost(apiKey, path, body) {
    const res = await fetch(`${API_BASE}${path}`, {
        method: 'POST',
        headers: { 'x-api-key': apiKey, 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        let errMsg = String(res.status);
        try {
            const d = await res.json();
            errMsg = d.error || d.message || d.reason || errMsg;
        } catch (_) {}
        throw new Error(`ArcEnCiel ${res.status}: ${errMsg}`);
    }
    return res.json();
}

async function downloadJobImage(apiKey, jobId) {
    const res = await fetch(`${API_BASE}/generator/jobs/${jobId}/outputs/0/download`, {
        headers: { 'x-api-key': apiKey },
    });
    if (!res.ok) throw new Error(`Download failed: ${res.status}`);
    const buf = await res.arrayBuffer();
    return Buffer.from(buf);
}

async function uploadImageFromUrl(apiKey, imageUrl) {
    const imgRes = await fetch(imageUrl);
    if (!imgRes.ok) throw new Error(`Could not fetch source image: ${imgRes.status}`);
    const buf = Buffer.from(await imgRes.arrayBuffer());

    const fd = new FormData();
    fd.append('image', new Blob([buf], { type: 'image/png' }), 'source.png');
    fd.append('kind', 'REDBOT');

    const res = await fetch(`${API_BASE}/generator/uploads`, {
        method: 'POST',
        headers: { 'x-api-key': apiKey },
        body: fd,
    });
    if (!res.ok) throw new Error(`Upload failed: ${res.status}`);
    const data = await res.json();
    return data.path;
}

// ── Quota tracking ────────────────────────────────────────────────────────────
// key: `${botId}:${userId}` → { count, resetAt }
const quotaMap = new Map();

function checkQuota(botId, userId, limit) {
    const key  = `${botId}:${userId}`;
    const now  = Date.now();
    let entry  = quotaMap.get(key);
    if (!entry || now > entry.resetAt) {
        entry = { count: 0, resetAt: now + 3_600_000 };
        quotaMap.set(key, entry);
    }
    if (entry.count >= limit) return false;
    entry.count++;
    return true;
}

// ── Job queue ─────────────────────────────────────────────────────────────────
function startPolling(botId) {
    if (pollers.has(botId)) return;
    const id = setInterval(() => pollJobs(botId).catch(() => {}), POLL_MS);
    id.unref?.();
    pollers.set(botId, id);
}

function stopPolling(botId) {
    const id = pollers.get(botId);
    if (id) clearInterval(id);
    pollers.delete(botId);
    // Clear jobs for this bot
    for (const [jobId, gen] of activeJobs) {
        if (gen.botId === botId) activeJobs.delete(jobId);
    }
}

async function pollJobs(botId) {
    const botJobs = [...activeJobs.values()].filter(j => j.botId === botId);
    if (botJobs.length === 0) return;

    const apiKey = botJobs[0].apiKey;
    let allJobs;
    try {
        const data = await apiGet(apiKey, '/generator/jobs');
        allJobs = Array.isArray(data.jobs) ? data.jobs : [];
    } catch (_) {
        return;
    }

    const byId = new Map(allJobs.map(j => [j.id, j]));

    for (const gen of botJobs) {
        const job = byId.get(gen.jobId);
        if (!job) {
            // Not yet visible in queue — check timeout
            if (Date.now() - gen.startedAt > JOB_TIMEOUT) {
                activeJobs.delete(gen.jobId);
                try { await gen.interaction.editReply({ content: '⏰ Zeitüberschreitung. Bitte erneut versuchen.', embeds: [], components: [] }); } catch (_) {}
            }
            continue;
        }
        try {
            await updateJob(gen, job, apiKey);
        } catch (err) {
            activeJobs.delete(gen.jobId);
            try { await gen.interaction.editReply({ content: `❌ Fehler: ${err.message}`, embeds: [], components: [] }); } catch (_) {}
        }
    }
}

async function updateJob(gen, job, apiKey) {
    if (Date.now() - gen.startedAt > JOB_TIMEOUT) {
        activeJobs.delete(gen.jobId);
        await gen.interaction.editReply({ content: '⏰ Zeitüberschreitung.', embeds: [], components: [] });
        return;
    }

    if (job.status === 'completed' || job.status === 'failed') {
        activeJobs.delete(gen.jobId);
        await finalizeJob(gen, job, apiKey);
        return;
    }

    // Throttle progress updates
    const now = Date.now();
    if (now - gen.lastUpdate < UPDATE_EVERY) return;
    const pct = job.progress?.percent ?? 0;
    if (pct === gen.lastPct && gen.lastUpdate > 0) return;
    gen.lastUpdate = now;
    gen.lastPct    = pct;

    const phase = job.progress?.phase || job.status;
    const pos   = job.position ?? 0;
    const etaSec = Math.ceil(((job.progress?.etaMs) || (job.queueEtaMs) || 0) / 1000);

    let line;
    if (phase === 'queued') {
        line = pos > 0 ? `⏳ Position **${pos}** in der Warteschlange` : '⏳ Warte auf Generator...';
        if (etaSec > 0) line += ` (~${etaSec}s)`;
    } else {
        line = `🎨 Generiere **${pct}%**`;
        if (etaSec > 0) line += ` (~${etaSec}s verbleibend)`;
    }

    const embed = new EmbedBuilder()
        .setColor(0x6366f1)
        .setDescription(line)
        .setFooter({ text: gen.prompt.slice(0, 100) });

    await gen.interaction.editReply({ embeds: [embed], components: [], files: [] }).catch(() => {});
}

async function finalizeJob(gen, job, apiKey) {
    if (job.status === 'failed') {
        const reason = job.safety?.reason || job.safety?.error || 'Unbekannter Fehler';
        await gen.interaction.editReply({
            content: `❌ Generierung fehlgeschlagen: \`${reason}\``,
            embeds: [], components: [], files: [],
        });
        return;
    }

    const imgBuf = await downloadJobImage(apiKey, gen.jobId);

    const ratings = Object.values(job.safety?.outputs ?? {});
    const isNsfw  = ratings.some(r => r.rating === 'sensitive' || r.rating === 'explicit');
    const file    = new AttachmentBuilder(imgBuf, { name: isNsfw ? 'SPOILER_image.png' : 'image.png' });

    const seed = gen.payload.seed ?? -1;
    const w    = gen.payload.width ?? 512;
    const h    = gen.payload.height ?? 512;

    const embed = new EmbedBuilder()
        .setColor(isNsfw ? 0xf85149 : 0x6366f1)
        .setDescription(`**${gen.prompt.slice(0, 250)}**`)
        .setImage(`attachment://${isNsfw ? 'SPOILER_image.png' : 'image.png'}`)
        .setFooter({ text: `Seed: ${seed} · ${w}×${h}` })
        .setTimestamp();

    // Cache params under a short key (avoids Discord 100-char customId limit)
    const rerollKey = Math.random().toString(36).slice(2, 10); // 8 chars
    rerollCache.set(rerollKey, {
        jobId:      gen.jobId,
        // full payload snapshot for upscale (same /generator/jobs endpoint + extra fields)
        fullPayload: { ...gen.payload },
    });
    // customIds are all ≤ 40 chars
    const row = new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId(`ace_reroll_${gen.userId}_${rerollKey}`)
            .setLabel('🔄')
            .setStyle(ButtonStyle.Secondary),
        new ButtonBuilder()
            .setCustomId(`ace_upscale_${gen.userId}_${rerollKey}`)
            .setLabel('🔍 1.5×')
            .setStyle(ButtonStyle.Primary),
        new ButtonBuilder()
            .setCustomId(`ace_delete_${gen.userId}`)
            .setLabel('🗑️')
            .setStyle(ButtonStyle.Danger),
    );

    await gen.interaction.editReply({
        embeds: [embed],
        files:  [file],
        components: [row],
        content: null,
    });
}

// ── Queue a new generation ────────────────────────────────────────────────────
async function queueGeneration(interaction, botId, settings, payload, prompt, userId) {
    await interaction.deferReply();

    try {
        const data = await apiPost(settings.api_key, '/generator/jobs', payload);
        const job  = data.job;
        if (!job?.id) throw new Error('Keine Job-ID erhalten.');

        const embed = new EmbedBuilder()
            .setColor(0x6366f1)
            .setDescription('⏳ Bildanfrage gesendet...')
            .setFooter({ text: prompt.slice(0, 100) });

        await interaction.editReply({ embeds: [embed] });

        activeJobs.set(job.id, {
            botId,
            apiKey:    settings.api_key,
            jobId:     job.id,
            interaction,
            prompt,
            payload,
            userId,
            startedAt: Date.now(),
            lastUpdate: 0,
            lastPct:   -1,
        });

        startPolling(botId);
    } catch (err) {
        await interaction.editReply({ content: `❌ Fehler: ${err.message}`, embeds: [] }).catch(() => {});
    }
}

// ── Button handler ────────────────────────────────────────────────────────────
async function handleArcEnCielButton(interaction, botId) {
    const id = interaction.customId;

    if (id.startsWith('ace_delete_')) {
        const authorId = id.replace('ace_delete_', '');
        if (interaction.user.id !== authorId) {
            await interaction.reply({ content: '❌ Du kannst nur deine eigenen Bilder löschen.', ephemeral: true });
            return;
        }
        await interaction.message.delete().catch(() => {});
        await interaction.deferUpdate().catch(() => {});
        return;
    }

    if (id.startsWith('ace_reroll_')) {
        // ace_reroll_{userId}_{8-char key}
        const parts = id.split('_');
        const rerollUserId  = parts[2];
        const rerollKey     = parts[3];

        await interaction.deferReply();

        const cached = rerollCache.get(rerollKey);
        if (!cached?.fullPayload) {
            await interaction.editReply({ content: '❌ Reroll-Daten abgelaufen. Bitte neu generieren.' });
            return;
        }

        const settings = await loadSettings(botId).catch(() => null);
        if (!settings?.api_key) {
            await interaction.editReply({ content: '❌ ArcEnCiel ist nicht konfiguriert.', ephemeral: true });
            return;
        }

        const prompt  = String(cached.fullPayload.prompt || '');
        const payload = { ...cached.fullPayload, seed: -1 }; // new seed on reroll

        try {
            const data = await apiPost(settings.api_key, '/generator/jobs', payload);
            const job  = data.job;
            if (!job?.id) throw new Error('Keine Job-ID.');

            const embed = new EmbedBuilder().setColor(0x6366f1).setDescription('⏳ Reroll läuft...');
            await interaction.editReply({ embeds: [embed] });

            activeJobs.set(job.id, {
                botId,
                apiKey:    settings.api_key,
                jobId:     job.id,
                interaction,
                prompt,
                payload,
                userId:    interaction.user.id,
                startedAt: Date.now(),
                lastUpdate: 0,
                lastPct:   -1,
            });

            startPolling(botId);
        } catch (err) {
            await interaction.editReply({ content: `❌ Reroll fehlgeschlagen: ${err.message}` }).catch(() => {});
        }
        return;
    }

    if (id.startsWith('ace_upscale_')) {
        // ace_upscale_{userId}_{8-char key}
        const parts      = id.split('_');
        const upscaleKey = parts[3];

        await interaction.deferReply();

        const cached = rerollCache.get(upscaleKey);
        if (!cached?.fullPayload) {
            await interaction.editReply({ content: '❌ Upscale-Daten abgelaufen. Bitte neu generieren.' });
            return;
        }

        const settings = await loadSettings(botId).catch(() => null);
        if (!settings?.api_key) {
            await interaction.editReply({ content: '❌ ArcEnCiel ist nicht konfiguriert.' });
            return;
        }

        // Upscale = same /generator/jobs endpoint with original payload + scaleFactor + upscaleProfiles
        const upscalePayload = {
            ...cached.fullPayload,
            scaleFactor:     1.5,
            upscaleProfiles: [{ modelName: '2x-AnimeSharpV4_RCAN.safetensors', denoise: 0.20 }],
        };

        try {
            const data = await apiPost(settings.api_key, '/generator/jobs', upscalePayload);
            const job = data.job;
            if (!job?.id) throw new Error('Keine Job-ID erhalten.');

            const embed = new EmbedBuilder().setColor(0x6366f1).setDescription('🔍 Upscaling läuft...');
            await interaction.editReply({ embeds: [embed] });

            const orig = cached.fullPayload;
            activeJobs.set(job.id, {
                botId,
                apiKey:     settings.api_key,
                jobId:      job.id,
                interaction,
                prompt:     String(orig.prompt ?? ''),
                payload:    { ...orig, width: (orig.width ?? 512) * 2, height: (orig.height ?? 512) * 2 },
                userId:     interaction.user.id,
                startedAt:  Date.now(),
                lastUpdate: 0,
                lastPct:    -1,
            });

            startPolling(botId);
        } catch (err) {
            await interaction.editReply({ content: `❌ Upscale fehlgeschlagen: ${err.message}` }).catch(() => {});
        }
        return;
    }
}

// ── Payload builder ───────────────────────────────────────────────────────────
function buildPayload(prompt, opts = {}) {
    const payload = {
        prompt:          `masterpiece, best quality, ${prompt}`,
        negativePrompt:  opts.negativePrompt || 'lowres, bad anatomy, bad hands, text, error, missing fingers',
        width:           opts.width  ?? 512,
        height:          opts.height ?? 512,
        steps:           opts.steps  ?? 20,
        cfg:             opts.cfg    ?? 7,
        seed:            opts.seed  ?? -1,
    };
    if (opts.checkpoint)    payload.checkpoint    = opts.checkpoint;
    if (opts.vae)           payload.vae           = opts.vae;
    if (opts.imagePath)     payload.imagePath     = opts.imagePath;
    if (opts.denoise != null) payload.denoiseStrength = opts.denoise;
    return payload;
}

// ── Service attach (called from bot-manager on bot ready) ─────────────────────
function attachArcEnCielEvents(client, botId) {
    // Start polling when bot is ready — polling only runs when jobs exist
    startPolling(botId);
}

// ── Register a pre-submitted job (for callers that defer+POST before calling) ──
function registerJob(apiKey, jobId, botId, interaction, prompt, payload, userId) {
    activeJobs.set(jobId, {
        botId,
        apiKey,
        jobId,
        interaction,
        prompt,
        payload,
        userId,
        startedAt:  Date.now(),
        lastUpdate: 0,
        lastPct:    -1,
    });
    startPolling(botId);
}

module.exports = {
    ensureTables,
    loadSettings,
    attachArcEnCielEvents,
    stopPolling,
    handleArcEnCielButton,
    queueGeneration,
    registerJob,
    buildPayload,
    uploadImageFromUrl,
    checkQuota,
    apiPost,
};
