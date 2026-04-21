// PFAD: /core/installer/src/services/soundboard-service.js

const { spawn }  = require('child_process');
const { Readable } = require('stream');
const {
    createAudioPlayer,
    createAudioResource,
    StreamType,
    joinVoiceChannel,
    getVoiceConnection,
    AudioPlayerStatus,
    VoiceConnectionStatus,
    entersState,
    NoSubscriberBehavior,
} = require('@discordjs/voice');

// Per-guild soundboard state: Map<guildId, { connection, player, botId }>
const guildStates = new Map();

/**
 * Resolve the ffmpeg binary path.
 * Tries ffmpeg-static first, then falls back to the system 'ffmpeg'.
 */
function _ffmpegBin() {
    try {
        const p = require('ffmpeg-static');
        if (p) return p;
    } catch (_) {}
    return 'ffmpeg';
}

const FFMPEG_BIN = _ffmpegBin();

/**
 * Transcode an audio Buffer to raw s16le PCM (48 kHz, 2 ch) via FFmpeg.
 * Returns a Readable stream of PCM data.
 */
function _transcodeBuffer(audioBuffer) {
    const proc = spawn(FFMPEG_BIN, [
        '-loglevel', 'error',
        '-i', 'pipe:0',
        '-f', 's16le',
        '-ar', '48000',
        '-ac', '2',
        'pipe:1',
    ], { stdio: ['pipe', 'pipe', 'pipe'] });

    // Feed audio data into stdin
    Readable.from(audioBuffer).pipe(proc.stdin);

    proc.stderr.on('data', (d) => {
        const msg = d.toString().trim();
        if (msg) console.warn('[soundboard/ffmpeg]', msg);
    });

    proc.on('error', (err) => {
        console.error('[soundboard/ffmpeg] spawn error:', err.message);
    });

    return proc.stdout;
}

/**
 * Join a voice channel.
 * @param {import('discord.js').Client} client
 * @param {string} guildId
 * @param {string} channelId
 * @param {number} botId
 */
async function joinVc(client, guildId, channelId, botId) {
    const guild   = client.guilds.cache.get(guildId);
    if (!guild) throw new Error('Guild not found: ' + guildId);

    const channel = guild.channels.cache.get(channelId);
    if (!channel) throw new Error('Channel not found: ' + channelId);
    if (!channel.isVoiceBased()) throw new Error('Channel ist kein Voice Channel.');

    // Reuse existing connection if already in this channel
    let state = guildStates.get(guildId);
    if (state && state.connection) {
        const existing = getVoiceConnection(guildId);
        if (existing && existing.joinConfig && existing.joinConfig.channelId === channelId) {
            return { ok: true, message: 'Bereits in diesem Channel.' };
        }
        // Disconnect from current channel first
        existing?.destroy();
    }

    const connection = joinVoiceChannel({
        channelId,
        guildId,
        adapterCreator: guild.voiceAdapterCreator,
        selfDeaf: false,
    });

    try {
        await entersState(connection, VoiceConnectionStatus.Ready, 10_000);
    } catch (err) {
        connection.destroy();
        throw new Error('Konnte Voice Channel nicht betreten: ' + err.message);
    }

    const player = createAudioPlayer({ behaviors: { noSubscriber: NoSubscriberBehavior.Stop } });
    connection.subscribe(player);

    connection.on(VoiceConnectionStatus.Disconnected, async () => {
        try {
            await Promise.race([
                entersState(connection, VoiceConnectionStatus.Signalling, 5_000),
                entersState(connection, VoiceConnectionStatus.Connecting, 5_000),
            ]);
        } catch {
            connection.destroy();
            guildStates.delete(guildId);
        }
    });

    guildStates.set(guildId, { connection, player, botId, channelId });
    return { ok: true, message: 'Voice Channel beigetreten.', channel_id: channelId };
}

/**
 * Play a sound in the current VC.
 * @param {import('discord.js').Client} client
 * @param {string} guildId
 * @param {string} channelId
 * @param {Buffer} audioBuffer  - Raw audio data from MySQL file_data BLOB
 * @param {number} volume       - 1–200
 * @param {string} soundName
 * @param {number} botId
 */
async function playSound(client, guildId, channelId, audioBuffer, volume, soundName, botId) {
    if (!Buffer.isBuffer(audioBuffer) || audioBuffer.length === 0) {
        throw new Error('Keine Audiodaten verfügbar (file_data ist leer).');
    }

    // Join if not already in a VC
    let state = guildStates.get(guildId);
    if (!state || !getVoiceConnection(guildId)) {
        await joinVc(client, guildId, channelId, botId);
        state = guildStates.get(guildId);
    }

    if (!state) throw new Error('Kein Voice-State verfügbar.');

    // Stop any currently playing sound
    state.player.stop(true);

    // Transcode to raw PCM via FFmpeg, then let @discordjs/voice encode to Opus.
    // Using StreamType.Raw bypasses prism-media's FFmpeg auto-detection entirely.
    const pcmStream = _transcodeBuffer(audioBuffer);
    const resource  = createAudioResource(pcmStream, {
        inputType:    StreamType.Raw,
        inlineVolume: true,
    });
    resource.volume?.setVolume(Math.min((volume || 100) / 100, 2.0));

    state.player.play(resource);

    await entersState(state.player, AudioPlayerStatus.Playing, 5_000);

    console.log(`[soundboard] Playing "${soundName}" in guild ${guildId} (vol: ${volume}%)`);
    return { ok: true, message: 'Wird abgespielt: ' + soundName };
}

/**
 * Stop current playback.
 */
function stopSound(guildId) {
    const state = guildStates.get(guildId);
    if (!state) return { ok: false, message: 'Bot ist in keinem Voice Channel.' };
    state.player.stop(true);
    return { ok: true, message: 'Wiedergabe gestoppt.' };
}

/**
 * Leave the current voice channel.
 */
function leaveVc(guildId) {
    const state = guildStates.get(guildId);
    const conn  = getVoiceConnection(guildId);
    if (conn) conn.destroy();
    if (state) guildStates.delete(guildId);
    return { ok: true, message: 'Voice Channel verlassen.' };
}

/**
 * Get VC status for a guild.
 */
function getStatus(client, botId) {
    const result = [];
    for (const [guildId, state] of guildStates.entries()) {
        if (state.botId !== botId) continue;
        const conn    = getVoiceConnection(guildId);
        const guild   = client.guilds.cache.get(guildId);
        const channel = guild?.channels.cache.get(state.channelId);
        result.push({
            guild_id:     guildId,
            guild_name:   guild?.name || guildId,
            channel_id:   state.channelId,
            channel_name: channel?.name || state.channelId,
            connected:    !!conn,
            playing:      state.player.state.status === AudioPlayerStatus.Playing,
        });
    }
    return { ok: true, states: result };
}

/**
 * Return all guilds the bot is in, with their voice channels.
 */
function getGuilds(client) {
    const guilds = [];
    for (const guild of client.guilds.cache.values()) {
        const vcs = guild.channels.cache
            .filter(c => c.isVoiceBased())
            .map(c => ({ id: c.id, name: c.name }))
            .sort((a, b) => a.name.localeCompare(b.name));
        guilds.push({ id: guild.id, name: guild.name, voice_channels: vcs });
    }
    guilds.sort((a, b) => a.name.localeCompare(b.name));
    return { ok: true, guilds };
}

module.exports = { joinVc, playSound, stopSound, leaveVc, getStatus, getGuilds };
