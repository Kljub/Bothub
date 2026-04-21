// PFAD: /core/installer/src/services/music-service.js

// Ensure ffmpeg-static binary is found by @discordjs/voice (prism-media)
// prism-media resolves ffmpeg via PATH, not FFMPEG_PATH env var
try {
    const ffmpegPath = require('ffmpeg-static');
    if (ffmpegPath) {
        const ffmpegDir = require('path').dirname(ffmpegPath);
        process.env.FFMPEG_PATH = ffmpegPath;
        if (!process.env.PATH.split(':').includes(ffmpegDir)) {
            process.env.PATH = ffmpegDir + ':' + process.env.PATH;
        }
    }
} catch (_) {}

// Ensure /usr/local/bin is in PATH (yt-dlp lives there; some node launchers strip it)
for (const extraDir of ['/usr/local/bin', '/usr/bin']) {
    if (!process.env.PATH.split(':').includes(extraDir)) {
        process.env.PATH = extraDir + ':' + process.env.PATH;
    }
}

// Resolve yt-dlp binary — prefer the locally bundled binary downloaded by postinstall
const YTDLP_BIN = (() => {
    const { existsSync } = require('fs');
    const path = require('path');

    // 1. Local bin/ downloaded by scripts/download-yt-dlp.js (always preferred in AMP/container)
    const localBin = path.join(__dirname, '..', '..', 'bin', 'yt-dlp');
    if (existsSync(localBin)) return localBin;

    // 2. Common system paths
    for (const p of ['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', '/opt/homebrew/bin/yt-dlp', '/snap/bin/yt-dlp']) {
        if (existsSync(p)) return p;
    }

    // 3. Ask the shell (handles pyenv, pipx, etc.)
    try {
        const { execSync } = require('child_process');
        const found = execSync('which yt-dlp 2>/dev/null || command -v yt-dlp 2>/dev/null', { shell: '/bin/bash', encoding: 'utf8', timeout: 3000 }).trim();
        if (found) return found;
    } catch (_) {}

    return 'yt-dlp';
})();
console.log('[music] yt-dlp binary:', YTDLP_BIN);

// ── YouTube OAuth token management ────────────────────────────────────────────
const _ytOAuth = { token: null, expiry: 0 };

async function _ytGetAdminSetting(key) {
    try {
        const rows = await dbQuery('SELECT setting_value FROM admin_settings WHERE setting_key = ?', [key]);
        return (rows && rows[0]) ? String(rows[0].setting_value || '') : '';
    } catch (_) { return ''; }
}

async function _ytWriteTokenFile(accessToken, refreshToken) {
    try {
        const os   = require('os');
        const path = require('path');
        const fs   = require('fs');
        const dir  = path.join(os.homedir(), '.cache', 'yt-dlp');
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(
            path.join(dir, 'youtube-oauth2.token'),
            JSON.stringify({ access_token: accessToken, refresh_token: refreshToken, token_type: 'Bearer' }),
            'utf8'
        );
    } catch (err) {
        console.warn('[music] yt-dlp token file write failed:', err.message);
    }
}

async function getYoutubeOAuthToken() {
    const now = Date.now();
    // Return cached token if still valid (with 2-min buffer)
    if (_ytOAuth.token && _ytOAuth.expiry > now + 120_000) {
        return _ytOAuth.token;
    }

    try {
        const rows = await dbQuery('SELECT * FROM system_youtube_token ORDER BY id DESC LIMIT 1');
        if (!rows || rows.length === 0) return null;

        const row       = rows[0];
        const expiresAt = new Date(row.expires_at).getTime();

        if (expiresAt > now + 120_000) {
            // Token still valid
            _ytOAuth.token  = String(row.access_token);
            _ytOAuth.expiry = expiresAt;
            await _ytWriteTokenFile(row.access_token, row.refresh_token);
            return _ytOAuth.token;
        }

        // Token expired — refresh
        if (!row.refresh_token) return null;
        const clientId     = await _ytGetAdminSetting('google_oauth_client_id');
        const clientSecret = await _ytGetAdminSetting('google_oauth_client_secret');
        if (!clientId || !clientSecret) return null;

        const resp = await fetch('https://oauth2.googleapis.com/token', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({
                client_id:     clientId,
                client_secret: clientSecret,
                refresh_token: row.refresh_token,
                grant_type:    'refresh_token',
            }).toString(),
        });

        const data = await resp.json();
        if (!data.access_token) {
            console.warn('[music] YouTube token refresh failed:', data.error_description || data.error);
            return null;
        }

        const newExpiry    = Date.now() + (Number(data.expires_in) || 3600) * 1000;
        const newExpiresAt = new Date(newExpiry).toISOString().slice(0, 19).replace('T', ' ');

        await dbQuery(
            'UPDATE system_youtube_token SET access_token = ?, expires_at = ? WHERE id = ?',
            [data.access_token, newExpiresAt, row.id]
        );

        _ytOAuth.token  = String(data.access_token);
        _ytOAuth.expiry = newExpiry;
        await _ytWriteTokenFile(data.access_token, row.refresh_token);
        console.log('[music] YouTube OAuth token refreshed.');
        return _ytOAuth.token;

    } catch (err) {
        console.warn('[music] YouTube OAuth error:', err.message);
        return null;
    }
}

const {
    createAudioPlayer,
    createAudioResource,
    joinVoiceChannel,
    getVoiceConnection,
    AudioPlayerStatus,
    VoiceConnectionStatus,
    StreamType,
    entersState,
    NoSubscriberBehavior,
} = require('@discordjs/voice');
const { Readable } = require('stream');
const { spawn } = require('child_process');
const playdl = require('play-dl');
const { dbQuery } = require('../db');
const { searchPlexForPlay } = require('./plex-service');

/* ── Per-guild queue ── */
class GuildMusicQueue {
    constructor(guildId, botId) {
        this.guildId     = guildId;
        this.botId       = botId;
        this.tracks      = [];   // Array of { title, url, duration, thumbnail, requestedBy }
        this.currentIdx  = 0;
        this.loopMode    = 'off'; // 'off' | 'track' | 'queue'
        this.volume      = 50;
        this.textChannel = null;
        this.connection  = null;
        this.player      = createAudioPlayer({ behaviors: { noSubscriber: NoSubscriberBehavior.Pause } });
        this._destroyed  = false;
        this._retrying   = false;

        this.player.on(AudioPlayerStatus.Idle, () => this._onTrackFinish());
        this.player.on('error', (err) => {
            console.error(`[music] Player error guild ${guildId}:`, err.message);
            this._onTrackFinish();
        });
    }

    async _onTrackFinish() {
        if (this._destroyed || this._retrying) return;

        if (this.loopMode === 'track' && this.tracks.length > 0) {
            await this._playIdx(this.currentIdx);
            return;
        }

        const next = this.currentIdx + 1;

        if (next < this.tracks.length) {
            this.currentIdx = next;
            await this._playIdx(this.currentIdx);
        } else if (this.loopMode === 'queue' && this.tracks.length > 0) {
            this.currentIdx = 0;
            await this._playIdx(0);
        } else {
            this.tracks     = [];
            this.currentIdx = 0;
            this.player.stop(true);
        }
    }

    async _playIdx(idx) {
        const track = this.tracks[idx];
        if (!track) return;

        try {
            let resource;

            if (track.source === 'plex') {
                // Stream directly from Plex via HTTP
                const response = await fetch(track.url);
                if (!response.ok) throw new Error(`Plex HTTP ${response.status}`);
                const nodeStream = Readable.fromWeb(response.body);
                resource = createAudioResource(nodeStream, {
                    inputType:    StreamType.Arbitrary,
                    inlineVolume: true,
                });
            } else {
                const oauthToken = await getYoutubeOAuthToken();
                // Prefer WebM/Opus — playable by @discordjs/voice natively without ffmpeg.
                // Fall back to any bestaudio if WebM/Opus is unavailable.
                const ytdlpArgs = [
                    '-f', 'bestaudio[ext=webm][acodec=opus]/bestaudio[ext=webm]/bestaudio[acodec=opus]/bestaudio',
                    '-o', '-',
                    '--quiet',
                    '--no-warnings',
                    '--no-playlist',
                ];
                if (oauthToken) {
                    ytdlpArgs.push('--username', 'oauth2', '--password', '');
                }
                ytdlpArgs.push(track.url);
                const ytdlpProc = spawn(YTDLP_BIN, ytdlpArgs);
                // Attach error handler BEFORE createAudioResource to prevent unhandled crash
                ytdlpProc.on('error', (err) => {
                    console.error('[music] yt-dlp spawn error:', err.message);
                });
                let stderrBuf = '';
                ytdlpProc.stderr.on('data', (d) => {
                    stderrBuf += d.toString();
                });
                // When yt-dlp exits with error (video unavailable etc.), try fallback then skip
                ytdlpProc.on('close', async (code) => {
                    if (code !== 0 && code !== null) {
                        const msg = stderrBuf.trim();

                        // Broken pipe = skip/stop closed the pipe intentionally — not a real error
                        if (msg.includes('Broken pipe') || msg.includes('BrokenPipeError')) return;

                        console.warn(`[music] yt-dlp exited ${code} for "${track.title}":`, msg);

                        // Only retry for actual availability errors, not generic failures
                        const isAvailabilityError = msg.includes('not available') ||
                            msg.includes('Private video') || msg.includes('has been removed') ||
                            msg.includes('Video unavailable');

                        // Try alternative YouTube video before giving up
                        if (isAvailabilityError && !this._destroyed && !track._ytFallbackTried) {
                            track._ytFallbackTried = true;
                            try {
                                const results = await playdl.search(track.title, { source: { youtube: 'video' }, limit: 5 });
                                const alt = results.find(r => r.url !== track.url);
                                if (alt) {
                                    console.log(`[music] Fallback für "${track.title}": ${alt.url}`);
                                    track.url = alt.url;
                                    this._retrying = true;
                                    this.player.stop(true);
                                    await this._playIdx(this.currentIdx);
                                    this._retrying = false;
                                    return;
                                }
                            } catch (e) {
                                console.warn('[music] Fallback-Suche fehlgeschlagen:', e.message);
                                this._retrying = false;
                            }
                        }

                        // No fallback found — skip track
                        if (this.textChannel) {
                            const reason = msg.includes('not available')   ? 'nicht verfügbar'
                                         : msg.includes('Private video')   ? 'privates Video'
                                         : msg.includes('has been removed') ? 'gelöscht'
                                         : `Fehler (Code ${code})`;
                            this.textChannel.send(`⏭️ **${track.title}** übersprungen — ${reason}.`).catch(() => {});
                        }
                        if (this.player.state.status !== AudioPlayerStatus.Playing &&
                            this.player.state.status !== AudioPlayerStatus.Paused) {
                            this._onTrackFinish().catch(() => {});
                        }
                    } else {
                        const msg = stderrBuf.trim();
                        if (msg) console.warn('[music] yt-dlp stderr:', msg);
                    }
                });
                // WebmOpus: pure JS WebM demuxer, Opus frames sent directly to Discord.
                // No inlineVolume — that would require Opus→PCM decoding (needs ffmpeg/native addon).
                resource = createAudioResource(ytdlpProc.stdout, {
                    inputType: StreamType.WebmOpus,
                });
            }

            if (resource.volume) {
                resource.volume.setVolumeLogarithmic(this.volume / 100);
            }

            this.player.play(resource);
            this._currentResource = resource;

            if (this.textChannel) {
                const settings = await loadMusicSettings(this.botId);
                if (settings && Number(settings.announce_songs) === 1) {
                    const { EmbedBuilder } = require('discord.js');
                    const embed = new EmbedBuilder()
                        .setColor('#6366f1')
                        .setTitle('🎵 Jetzt spielend')
                        .setDescription(`[${track.title}](${track.url})`)
                        .setFooter({ text: `Angefragt von ${track.requestedBy}` })
                        .setTimestamp();

                    if (track.thumbnail) embed.setThumbnail(track.thumbnail);

                    this.textChannel.send({ embeds: [embed] }).catch(() => {});
                }
            }
        } catch (err) {
            console.error(`[music] Stream error for "${track.title}":`, err.message);
            await this._onTrackFinish();
        }
    }

    async addTrack(track) {
        this.tracks.push(track);
        if (this.player.state.status === AudioPlayerStatus.Idle && this.tracks.length === 1) {
            this.currentIdx = 0;
            await this._playIdx(0);
        }
    }

    async addTracks(tracks) {
        const wasEmpty = this.tracks.length === 0;
        this.tracks.push(...tracks);
        if (wasEmpty && this.player.state.status === AudioPlayerStatus.Idle) {
            this.currentIdx = 0;
            await this._playIdx(0);
        }
    }

    skip() {
        if (this.loopMode === 'track') {
            const prev = this.loopMode;
            this.loopMode = 'off';
            this.player.stop();
            this.loopMode = prev;
        } else {
            this.player.stop();
        }
    }

    stop() {
        this._destroyed = true;
        this.player.stop(true);
        this.tracks     = [];
        this.currentIdx = 0;
        if (this.connection) {
            try { this.connection.destroy(); } catch (_) {}
            this.connection = null;
        }
    }

    pause()  { this.player.pause(true); }
    resume() { this.player.unpause(); }

    setVolume(vol) {
        this.volume = Math.max(0, Math.min(100, vol));
        if (this._currentResource && this._currentResource.volume) {
            this._currentResource.volume.setVolumeLogarithmic(this.volume / 100);
        }
    }

    shuffle() {
        const current = this.tracks[this.currentIdx];
        const rest    = this.tracks.filter((_, i) => i !== this.currentIdx);
        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }
        this.tracks     = [current, ...rest];
        this.currentIdx = 0;
    }

    setLoop(mode) {
        this.loopMode = mode;
    }

    // Remove a track by 1-based position. Returns false if out of range or currently playing.
    removeTrack(position) {
        const idx = position - 1; // convert to 0-based
        if (idx < 0 || idx >= this.tracks.length) return false;
        if (idx === this.currentIdx) return false; // cannot remove currently playing track

        this.tracks.splice(idx, 1);

        // Adjust currentIdx if we removed a track before it
        if (idx < this.currentIdx) {
            this.currentIdx = Math.max(0, this.currentIdx - 1);
        }

        return true;
    }

    getCurrentTrack() {
        return this.tracks[this.currentIdx] || null;
    }

    isPlaying() {
        return this.player.state.status === AudioPlayerStatus.Playing;
    }

    isPaused() {
        return this.player.state.status === AudioPlayerStatus.Paused;
    }

    destroy() {
        this.stop();
    }
}

/* ── Queue store: botId → Map<guildId → GuildMusicQueue> ── */
const _queues = new Map(); // botId → Map<guildId → GuildMusicQueue>

function getBotQueues(botId) {
    if (!_queues.has(botId)) _queues.set(botId, new Map());
    return _queues.get(botId);
}

function getQueue(botId, guildId) {
    return getBotQueues(botId).get(guildId) || null;
}

function createQueue(botId, guildId) {
    const q = new GuildMusicQueue(guildId, botId);
    getBotQueues(botId).set(guildId, q);
    return q;
}

function deleteQueue(botId, guildId) {
    const q = getQueue(botId, guildId);
    if (q) {
        q.destroy();
        getBotQueues(botId).delete(guildId);
    }
}

function destroyAllQueues(botId) {
    const m = _queues.get(botId);
    if (!m) return;
    for (const q of m.values()) q.destroy();
    m.clear();
    _queues.delete(botId);
}

/* ── DB helpers ── */
async function loadMusicSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_music_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) {
        return null;
    }
}

async function isMusicEnabled(botId) {
    const s = await loadMusicSettings(botId);
    return s ? Number(s.enabled) === 1 : false;
}

async function isCommandEnabled(botId, cmdKey) {
    const s = await loadMusicSettings(botId);
    if (!s) return false;
    if (Number(s.enabled) !== 1) return false;
    return Number(s[`cmd_${cmdKey}`]) === 1;
}

/* ── Spotify: get access token ── */
async function _spotifyToken(clientId, clientSecret) {
    const res = await fetch('https://accounts.spotify.com/api/token', {
        method:  'POST',
        headers: {
            'Content-Type':  'application/x-www-form-urlencoded',
            'Authorization': 'Basic ' + Buffer.from(`${clientId}:${clientSecret}`).toString('base64'),
        },
        body: 'grant_type=client_credentials',
    });
    if (!res.ok) throw new Error(`Spotify token error: ${res.status}`);
    const data = await res.json();
    const token = String(data.access_token || '');
    if (!token) throw new Error('No Spotify access token received.');
    return token;
}

/* ── Spotify URL resolver (track / playlist / album) ── */
async function resolveSpotifyUrl(query, clientId, clientSecret) {
    // Handle locale-prefixed URLs like open.spotify.com/intl-de/track/ID
    const match = query.match(/spotify\.com\/(?:intl-[a-z]{2}\/)?(?:[a-z]{2}\/)?(?:user\/[^/]+\/)?(track|playlist|album)\/([A-Za-z0-9]+)/);
    if (!match) throw new Error('Unbekannte Spotify-URL.');


    const [, type, id] = match;
    console.log(`[spotify] Resolving ${type} id=${id}`);
    const token = await _spotifyToken(clientId, clientSecret);
    const headers = { 'Authorization': `Bearer ${token}` };

    let rawTracks = [];

    if (type === 'track') {
        const r = await fetch(`https://api.spotify.com/v1/tracks/${id}`, { headers });
        if (!r.ok) throw new Error(`Spotify track error: ${r.status}`);
        rawTracks = [await r.json()];

    } else if (type === 'playlist') {
        let url = `https://api.spotify.com/v1/playlists/${id}/tracks?limit=50`;
        while (url && rawTracks.length < 50) {
            const r = await fetch(url, { headers });
            if (!r.ok) {
                console.warn(`[spotify] Playlist fetch failed: ${r.status}`);
                break;
            }
            const data = await r.json();
            const items = (data.items || []).map((i) => i.track).filter(Boolean);
            rawTracks.push(...items);
            url = data.next || null;
        }
        console.log(`[spotify] Playlist fetched ${rawTracks.length} tracks`);

    } else if (type === 'album') {
        const r = await fetch(`https://api.spotify.com/v1/albums/${id}/tracks?limit=50`, { headers });
        if (!r.ok) throw new Error(`Spotify album error: ${r.status}`);
        const data = await r.json();
        rawTracks = data.items || [];
        console.log(`[spotify] Album fetched ${rawTracks.length} tracks`);
    }

    if (rawTracks.length === 0) {
        throw new Error('Spotify-Playlist/Album ist leer oder nicht öffentlich zugänglich.');
    }

    const results = [];
    let firstSearchError = null;
    for (const t of rawTracks.slice(0, 50)) {
        if (!t || !t.name) continue;
        const name      = String(t.name);
        const artist    = String(t.artists?.[0]?.name || '');
        const searchStr = artist ? `${name} ${artist}` : name;

        const yt = await playdl.search(searchStr, { source: { youtube: 'video' }, limit: 1 }).catch((e) => {
            if (!firstSearchError) firstSearchError = e.message;
            return [];
        });
        if (yt && yt[0]) {
            results.push({
                title:       yt[0].title || name,
                url:         yt[0].url,
                duration:    yt[0].durationInSec,
                thumbnail:   yt[0].thumbnails?.[0]?.url || null,
                requestedBy: '',
                source:      'spotify',
            });
        }
        // Small pause to avoid YouTube rate-limiting on large playlists
        if (results.length % 10 === 0 && results.length > 0) {
            await new Promise(r => setTimeout(r, 300));
        }
    }
    console.log(`[spotify] Resolved ${results.length}/${rawTracks.length} tracks via YouTube`);
    if (results.length === 0) {
        const hint = firstSearchError ? ` (YouTube: ${firstSearchError})` : '';
        throw new Error(`Keine passenden YouTube-Videos für diese Spotify-Playlist gefunden.${hint}`);
    }
    return results;
}

/* ── Spotify client-credentials text search ── */
async function searchSpotifyTrack(query, clientId, clientSecret) {
    const tokenRes = await fetch('https://accounts.spotify.com/api/token', {
        method:  'POST',
        headers: {
            'Content-Type':  'application/x-www-form-urlencoded',
            'Authorization': 'Basic ' + Buffer.from(`${clientId}:${clientSecret}`).toString('base64'),
        },
        body: 'grant_type=client_credentials',
    });

    if (!tokenRes.ok) throw new Error(`Spotify token error: ${tokenRes.status}`);
    const tokenData = await tokenRes.json();
    const accessToken = String(tokenData.access_token || '');
    if (!accessToken) throw new Error('No Spotify access token received.');

    const searchRes = await fetch(
        `https://api.spotify.com/v1/search?q=${encodeURIComponent(query)}&type=track&limit=1`,
        { headers: { 'Authorization': `Bearer ${accessToken}` } }
    );

    if (!searchRes.ok) throw new Error(`Spotify search error: ${searchRes.status}`);
    const searchData = await searchRes.json();
    const track = searchData.tracks?.items?.[0];
    if (!track) return null;

    const name   = String(track.name || 'Unknown');
    const artist = String(track.artists?.[0]?.name || '');
    return { name, artist, searchStr: artist ? `${name} ${artist}` : name };
}

/* ── Source resolver ── */
async function resolveQuery(query, botId) {
    const settings = await loadMusicSettings(botId);

    // ── Spotify URL detection (regex-based, independent of play-dl validate) ──
    const SPOTIFY_URL_RE = /spotify\.com\/(?:intl-[a-z]{2}\/)?(?:[a-z]{2}\/)?(?:user\/[^/]+\/)?(track|playlist|album)\/([A-Za-z0-9]+)/;
    const urlType = await playdl.validate(query).catch(() => false);
    const isSpotifyUrl = SPOTIFY_URL_RE.test(query) ||
        urlType === 'sp_track' || urlType === 'sp_playlist' || urlType === 'sp_album';

    if (isSpotifyUrl) {
        if (!settings || Number(settings.src_spotify) !== 1) {
            throw new Error('Spotify ist nicht aktiviert. Bitte aktiviere Spotify in den Musikeinstellungen des Dashboards.');
        }
        if (!settings.spotify_client_id || !settings.spotify_client_secret) {
            throw new Error('Spotify-Zugangsdaten fehlen. Bitte trage Client ID und Secret in den Musikeinstellungen ein.');
        }
        return await resolveSpotifyUrl(query, String(settings.spotify_client_id), String(settings.spotify_client_secret));
    }

    // Direct URL handling
    if (urlType && urlType !== false) {
        // YouTube video
        if (urlType === 'yt_video') {
            const info = await playdl.video_info(query);
            return [{
                title:       info.video_details.title || 'Unknown',
                url:         info.video_details.url,
                duration:    info.video_details.durationInSec,
                thumbnail:   info.video_details.thumbnails?.[0]?.url || null,
                requestedBy: '',
            }];
        }

        // YouTube playlist
        if (urlType === 'yt_playlist') {
            const pl = await playdl.playlist_info(query, { incomplete: true });
            const videos = await pl.all_videos();
            return videos.map((v) => ({
                title:       v.title || 'Unknown',
                url:         v.url,
                duration:    v.durationInSec,
                thumbnail:   v.thumbnails?.[0]?.url || null,
                requestedBy: '',
            }));
        }

        // SoundCloud
        if (urlType === 'so_track' && settings && Number(settings.src_soundcloud) === 1) {
            const info = await playdl.soundcloud(query);
            return [{
                title:       info.name || 'Unknown',
                url:         info.url,
                duration:    info.durationInSec,
                thumbnail:   info.thumbnail || null,
                requestedBy: '',
            }];
        }

        // Deezer track
        if ((urlType === 'dz_track' || urlType === 'dz_playlist' || urlType === 'dz_album') &&
            settings && Number(settings.src_deezer) === 1) {
            const dz = await playdl.deezer(query);
            let tracks = [];

            if (urlType === 'dz_track') {
                tracks = [dz];
            } else {
                tracks = await dz.all_tracks();
            }

            const results = [];
            for (const t of tracks.slice(0, 50)) {
                const searchStr = `${t.title || 'Unknown'} ${t.artist?.name || ''}`.trim();
                const yt = await playdl.search(searchStr, { source: { youtube: 'video' }, limit: 1 });
                if (yt && yt[0]) {
                    results.push({
                        title:       yt[0].title || t.title,
                        url:         yt[0].url,
                        duration:    yt[0].durationInSec,
                        thumbnail:   yt[0].thumbnails?.[0]?.url || null,
                        requestedBy: '',
                    });
                }
            }
            return results;
        }
    }

    // ── Plain text search: Plex → Spotify → YouTube ──────────────────────────

    // 1. Plex (absolute priority)
    if (settings && Number(settings.src_plex) === 1) {
        try {
            const plexResults = await searchPlexForPlay(botId, query, 1);
            if (plexResults.length > 0) {
                const pr = plexResults[0];
                return [{
                    title:       pr.year ? `${pr.title} (${pr.year})` : pr.title,
                    url:         pr.streamUrl,
                    duration:    0,
                    thumbnail:   pr.thumbnailUrl || null,
                    requestedBy: '',
                    source:      'plex',
                }];
            }
        } catch (err) {
            console.warn('[music] Plex search failed, falling back:', err.message);
        }
    }

    // 2. Spotify text search → find YouTube equivalent
    console.log(`[music] Spotify check: src_spotify=${settings?.src_spotify}, has_id=${!!settings?.spotify_client_id}, has_secret=${!!settings?.spotify_client_secret}`);
    if (settings && Number(settings.src_spotify) === 1
            && settings.spotify_client_id && settings.spotify_client_secret) {
        try {
            const spTrack = await searchSpotifyTrack(
                query,
                String(settings.spotify_client_id),
                String(settings.spotify_client_secret)
            );
            if (spTrack) {
                const yt = await playdl.search(spTrack.searchStr, { source: { youtube: 'video' }, limit: 1 });
                if (yt && yt[0]) {
                    return [{
                        title:       yt[0].title || spTrack.name,
                        url:         yt[0].url,
                        duration:    yt[0].durationInSec,
                        thumbnail:   yt[0].thumbnails?.[0]?.url || null,
                        requestedBy: '',
                        source:      'spotify',
                    }];
                }
            }
        } catch (err) {
            console.warn('[music] Spotify search failed, falling back:', err.message);
        }
    }

    // 3. YouTube fallback
    if (!settings || Number(settings.src_youtube) === 1) {
        const results = await playdl.search(query, { source: { youtube: 'video' }, limit: 5 });
        if (!results || results.length === 0) return [];

        return [{
            title:       results[0].title || 'Unknown',
            url:         results[0].url,
            duration:    results[0].durationInSec,
            thumbnail:   results[0].thumbnails?.[0]?.url || null,
            requestedBy: '',
        }];
    }

    return [];
}

/* ── Join voice channel helper ── */
async function ensureVoiceConnection(member, _client, queue) {
    const voiceChannel = member.voice?.channel;
    if (!voiceChannel) {
        throw new Error('Du musst in einem Voice Channel sein.');
    }

    // Check bot permissions in the voice channel
    const me = voiceChannel.guild.members.me;
    if (me) {
        const perms = voiceChannel.permissionsFor(me);
        if (!perms?.has('Connect')) {
            throw new Error('Ich habe keine Berechtigung, dem Voice Channel beizutreten.');
        }
        if (!perms?.has('Speak')) {
            throw new Error('Ich habe keine Berechtigung, im Voice Channel zu sprechen.');
        }
    }

    // Reuse an existing Ready connection; destroy stale ones
    const existing = getVoiceConnection(voiceChannel.guild.id);
    if (existing && existing.state.status === VoiceConnectionStatus.Ready) {
        queue.connection = existing;
        queue.connection.subscribe(queue.player);
        return;
    }
    if (existing) {
        existing.destroy();
    }

    const conn = joinVoiceChannel({
        channelId:      voiceChannel.id,
        guildId:        voiceChannel.guild.id,
        adapterCreator: voiceChannel.guild.voiceAdapterCreator,
        selfDeaf:       true,
        selfMute:       false,
    });

    conn.on('stateChange', (oldState, newState) => {
        console.log(`[music-voice] ${oldState.status} → ${newState.status}`);
    });
    conn.on('error', (err) => {
        console.error('[music-voice] Connection error:', err.message);
    });

    try {
        await entersState(conn, VoiceConnectionStatus.Ready, 15_000);
    } catch (err) {
        const status = conn.state.status;
        console.error(`[music-voice] Failed to reach Ready. Last status: ${status}`, err.message);
        conn.destroy();
        throw new Error(
            'Voice-Verbindung konnte nicht hergestellt werden. ' +
            'Bitte stelle sicher, dass der Bot die Berechtigungen hat und keine Firewall UDP-Ports (50000–65535) blockiert.'
        );
    }

    conn.on(VoiceConnectionStatus.Disconnected, async () => {
        try {
            await Promise.race([
                entersState(conn, VoiceConnectionStatus.Signalling, 5_000),
                entersState(conn, VoiceConnectionStatus.Connecting, 5_000),
            ]);
        } catch (_) {
            deleteQueue(queue.botId, queue.guildId);
            if (conn.state.status !== VoiceConnectionStatus.Destroyed) {
                try { conn.destroy(); } catch (_2) {}
            }
        }
    });

    queue.connection = conn;
    queue.connection.subscribe(queue.player);
}

/* ── Exports ── */
module.exports = {
    getQueue,
    createQueue,
    deleteQueue,
    destroyAllQueues,
    loadMusicSettings,
    isMusicEnabled,
    isCommandEnabled,
    resolveQuery,
    ensureVoiceConnection,
};
