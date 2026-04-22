// PFAD: /src/index.js
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const http = require('http');
const os = require('os');
const { URL } = require('url');

const {
    nodeEnv,
    dashboardBaseUrl,
    corePort,
    jobPollIntervalMs,
    runnerName,
    runnerEndpoint,
    appKey
} = require('./config');

const { pingDashboard, registerCoreRunner, describeError } = require('./dashboard-client');
const { getDbPool, dbQuery } = require('./db');
const sbService = require('./services/soundboard-service');
const { initStatus } = require('./services/status-service');
const { BotManager } = require('./bot-manager');
const { clearSettingsCache } = require('./services/temp-voice-service');
const { JobPoller } = require('./job-poller');
const { loadCommands } = require('./command-loader');
const { syncSlashCommands } = require('./slash-sync');

// ── Core version ──────────────────────────────────────────────────────────────
const VERSION_FILE = path.join(__dirname, '..', 'version.json');

function readCoreVersion() {
    try {
        const raw = fs.readFileSync(VERSION_FILE, 'utf8');
        const parsed = JSON.parse(raw);
        return typeof parsed.version === 'string' ? parsed.version.trim() : '';
    } catch (_) {
        return '';
    }
}

function writeCoreVersion(version) {
    try {
        fs.writeFileSync(VERSION_FILE, JSON.stringify({ version }), 'utf8');
    } catch (e) {
        console.warn('[core-update] Version konnte nicht gespeichert werden:', e.message);
    }
}

let coreVersion = readCoreVersion();

function sendJson(res, statusCode, payload) {
    const body = JSON.stringify(payload);

    res.writeHead(statusCode, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(body, 'utf8')
    });

    res.end(body);
}

function buildHealthPayload(botManager) {
    const mem = process.memoryUsage();
    const loadavg = os.loadavg();
    const botStats = botManager.getStats();

    return {
        ok: true,
        service: 'bothub-core',
        runner: runnerName,
        version: coreVersion || null,
        endpoint: 'health',
        env: nodeEnv,
        dashboard: dashboardBaseUrl,
        time: new Date().toISOString(),
        uptime_seconds: Math.floor(process.uptime()),
        system: {
            hostname: os.hostname(),
            platform: os.platform(),
            arch: os.arch(),
            cpus: Array.isArray(os.cpus()) ? os.cpus().length : 0
        },
        cpu: {
            loadavg_1: Number(loadavg[0] || 0),
            loadavg_5: Number(loadavg[1] || 0),
            loadavg_15: Number(loadavg[2] || 0)
        },
        memory: {
            rss_bytes: Number(mem.rss || 0),
            heap_total_bytes: Number(mem.heapTotal || 0),
            heap_used_bytes: Number(mem.heapUsed || 0),
            external_bytes: Number(mem.external || 0),
            array_buffers_bytes: Number(mem.arrayBuffers || 0)
        },
        bots: {
            total_known: botStats.totalKnown,
            running: botStats.running,
            desired_running: botStats.desiredRunning
        }
    };
}

function getBearerToken(req) {
    const authHeader = typeof req.headers.authorization === 'string'
        ? req.headers.authorization.trim()
        : '';

    if (authHeader === '') {
        return '';
    }

    const match = authHeader.match(/^Bearer\s+(.+)$/i);
    if (!match) {
        return '';
    }

    return String(match[1] || '').trim();
}

function isReloadAuthorized(req) {
    if (typeof appKey !== 'string' || appKey.trim() === '') {
        return false;
    }

    const token = getBearerToken(req);
    return token !== '' && token === appKey;
}

function sendUnauthorized(res) {
    sendJson(res, 401, {
        ok: false,
        error: 'unauthorized'
    });
}

async function handleReloadAll(res, botManager) {
    try {
        clearSettingsCache();
        const result = await botManager.reloadAllBots();

        sendJson(res, 200, {
            ok: true,
            reloaded: 'all',
            result
        });
    } catch (error) {
        sendJson(res, 500, {
            ok: false,
            error: error instanceof Error ? error.message : String(error)
        });
    }
}

async function handleReloadBot(res, botManager, botId) {
    try {
        clearSettingsCache(botId);
        const result = await botManager.reloadBotById(botId);

        sendJson(res, 200, {
            ok: true,
            reloaded: 'bot',
            bot_id: Number(botId),
            result
        });
    } catch (error) {
        sendJson(res, 500, {
            ok: false,
            bot_id: Number(botId),
            error: error instanceof Error ? error.message : String(error)
        });
    }
}

function createHttpServer(botManager) {
    return http.createServer(async (req, res) => {
        const method = typeof req.method === 'string'
            ? req.method.toUpperCase()
            : 'GET';

        const host = typeof req.headers.host === 'string' && req.headers.host !== ''
            ? req.headers.host
            : `127.0.0.1:${corePort}`;

        const url = new URL(req.url || '/', `http://${host}`);
        const pathname = url.pathname;

        if (method === 'GET' && pathname === '/ping') {
            sendJson(res, 200, {
                ok: true,
                service: 'bothub-core',
                runner: runnerName,
                endpoint: 'ping',
                time: new Date().toISOString()
            });
            return;
        }

        if (method === 'GET' && pathname === '/health') {
            sendJson(res, 200, buildHealthPayload(botManager));
            return;
        }

        if (method === 'POST' && pathname === '/reload') {
            if (!isReloadAuthorized(req)) {
                sendUnauthorized(res);
                return;
            }

            await handleReloadAll(res, botManager);
            return;
        }

        if (method === 'POST' && /^\/reload\/bot\/\d+$/.test(pathname)) {
            if (!isReloadAuthorized(req)) {
                sendUnauthorized(res);
                return;
            }

            const parts = pathname.split('/');
            const botIdRaw = parts[parts.length - 1];
            const botId = Number.parseInt(botIdRaw, 10);

            if (!Number.isFinite(botId) || botId <= 0) {
                sendJson(res, 400, {
                    ok: false,
                    error: 'invalid_bot_id'
                });
                return;
            }

            await handleReloadBot(res, botManager, botId);
            return;
        }

        // ── Slash sync  POST /slash-sync/bot/:id ──────────────────────────
        if (method === 'POST' && /^\/slash-sync\/bot\/\d+$/.test(pathname)) {
            if (!isReloadAuthorized(req)) { sendUnauthorized(res); return; }

            const syncBotId = Number.parseInt(pathname.split('/').pop(), 10);
            if (!Number.isFinite(syncBotId) || syncBotId <= 0) {
                sendJson(res, 400, { ok: false, error: 'invalid_bot_id' });
                return;
            }

            const syncClient = botManager.getClient(syncBotId);
            if (!syncClient) {
                sendJson(res, 404, { ok: false, error: 'Bot not running' });
                return;
            }

            try {
                // Refresh command registry so newly added command files are picked up
                loadCommands(botManager);
                const customReg = botManager.customCommandRegistries.get(syncBotId) || new Map();
                const result = await syncSlashCommands(syncClient, syncBotId, botManager.commandRegistry, null, customReg);
                sendJson(res, 200, { ok: true, ...result });
            } catch (err) {
                sendJson(res, 500, { ok: false, error: err instanceof Error ? err.message : String(err) });
            }
            return;
        }

        // ── Core self-update  POST /core/update ───────────────────────────
        if (method === 'POST' && pathname === '/core/update') {
            if (!isReloadAuthorized(req)) { sendUnauthorized(res); return; }

            const coreDir = path.join(__dirname, '..');
            const { exec } = require('child_process');
            const fs       = require('fs');
            const os       = require('os');

            // Read raw binary body (the ZIP sent by PHP)
            const zipBody = await new Promise((resolve, reject) => {
                const chunks = [];
                req.on('data', c => chunks.push(c));
                req.on('end',  () => resolve(Buffer.concat(chunks)));
                req.on('error', reject);
            });

            // Persist new version if provided
            const incomingVersion = String(req.headers['x-core-version'] || '').trim();
            if (incomingVersion !== '') {
                writeCoreVersion(incomingVersion);
                coreVersion = incomingVersion;
                console.log(`[core-update] Version gesetzt: ${incomingVersion}`);
            }

            // Respond immediately so PHP doesn't time out
            sendJson(res, 200, { ok: true, message: 'Update gestartet. Core wird neu gestartet...' });

            const doNpmAndRestart = () => {
                console.log('[core-update] npm install wird ausgeführt...');
                exec('npm install --production', { cwd: coreDir }, (err, stdout, stderr) => {
                    if (err) console.error('[core-update] npm install Fehler:', err.message, stderr);
                    else     console.log('[core-update] npm install OK:', stdout.trim());
                    console.log('[core-update] Core-Neustart wird eingeleitet...');
                    setTimeout(() => process.exit(0), 500);
                });
            };

            if (zipBody.length === 0) {
                // No ZIP sent — just npm install + restart
                console.log('[core-update] Kein ZIP empfangen — nur npm install + Neustart.');
                doNpmAndRestart();
                return;
            }

            // Write ZIP to temp file, extract over coreDir, then npm install
            const tmpZip = path.join(os.tmpdir(), 'bothub_core_' + Date.now() + '.zip');
            fs.writeFile(tmpZip, zipBody, (writeErr) => {
                if (writeErr) {
                    console.error('[core-update] ZIP schreiben fehlgeschlagen:', writeErr.message);
                    doNpmAndRestart(); // fallback
                    return;
                }
                console.log(`[core-update] ZIP (${zipBody.length} Bytes) wird entpackt nach: ${coreDir}`);
                exec(`unzip -o "${tmpZip}" -d "${coreDir}"`, (unzipErr, unzipOut) => {
                    try { fs.unlinkSync(tmpZip); } catch (_) {}
                    if (unzipErr) console.error('[core-update] unzip Fehler:', unzipErr.message);
                    else          console.log('[core-update] unzip OK:', unzipOut.trim().split('\n').pop());
                    doNpmAndRestart();
                });
            });
            return;
        }

        // ── Status apply  POST /status/bot/:id/apply ─────────────────────
        if (method === 'POST' && /^\/status\/bot\/\d+\/apply$/.test(pathname)) {
            if (!isReloadAuthorized(req)) { sendUnauthorized(res); return; }

            const statusBotId = Number.parseInt(pathname.split('/')[3], 10);
            const client = botManager.getClient(statusBotId);

            if (!client) {
                sendJson(res, 404, { ok: false, error: 'Bot not running' });
                return;
            }

            try {
                console.log(`[status] Apply triggered via dashboard for bot ${statusBotId}`);
                await initStatus(client, statusBotId);
                sendJson(res, 200, { ok: true, bot_id: statusBotId });
            } catch (error) {
                sendJson(res, 500, { ok: false, error: error instanceof Error ? error.message : String(error) });
            }
            return;
        }

        // ── Soundboard routes  POST /soundboard/bot/:id/:action ──────────
        const sbMatch = pathname.match(/^\/soundboard\/bot\/(\d+)\/(play|stop|join|leave|status|guilds)$/);
        if (method === 'POST' && sbMatch) {
            if (!isReloadAuthorized(req)) { sendUnauthorized(res); return; }

            const sbBotId  = Number.parseInt(sbMatch[1], 10);
            const sbAction = sbMatch[2];

            let body = {};
            try {
                const raw = await new Promise((resolve, reject) => {
                    let buf = '';
                    req.on('data', c => { buf += c; });
                    req.on('end',  () => resolve(buf));
                    req.on('error', reject);
                });
                if (raw) body = JSON.parse(raw);
            } catch (_) { /* ignore parse errors */ }

            const client = botManager.getClient(sbBotId);

            if (!client) {
                sendJson(res, 404, { ok: false, error: 'Bot nicht geladen (id=' + sbBotId + ').' });
                return;
            }

            try {
                let result;
                switch (sbAction) {
                    case 'play': {
                        const sbSoundId = Number(body.sound_id || 0);
                        if (sbSoundId <= 0) {
                            sendJson(res, 400, { ok: false, error: 'sound_id fehlt.' });
                            return;
                        }
                        const sbRows = await dbQuery(
                            'SELECT name, volume, file_data FROM bot_soundboard_sounds WHERE id = ? AND bot_id = ? LIMIT 1',
                            [sbSoundId, sbBotId]
                        );
                        if (!sbRows || sbRows.length === 0) {
                            sendJson(res, 404, { ok: false, error: 'Sound nicht gefunden.' });
                            return;
                        }
                        const sbSound      = sbRows[0];
                        const sbFileData   = Buffer.isBuffer(sbSound.file_data) ? sbSound.file_data : Buffer.from(sbSound.file_data || '');
                        result = await sbService.playSound(
                            client,
                            String(body.guild_id   || ''),
                            String(body.channel_id || ''),
                            sbFileData,
                            Number(sbSound.volume  || 100),
                            String(sbSound.name    || body.sound_name || 'Sound'),
                            sbBotId
                        );
                        break;
                    }
                    case 'stop':
                        result = sbService.stopSound(String(body.guild_id || ''));
                        break;
                    case 'join':
                        result = await sbService.joinVc(
                            client,
                            String(body.guild_id   || ''),
                            String(body.channel_id || ''),
                            sbBotId
                        );
                        break;
                    case 'leave':
                        result = sbService.leaveVc(String(body.guild_id || ''));
                        break;
                    case 'status':
                        result = sbService.getStatus(client, sbBotId);
                        break;
                    case 'guilds':
                        result = sbService.getGuilds(client);
                        break;
                    default:
                        result = { ok: false, error: 'unknown action' };
                }
                sendJson(res, result.ok !== false ? 200 : 400, result);
            } catch (err) {
                sendJson(res, 500, { ok: false, error: err instanceof Error ? err.message : String(err) });
            }
            return;
        }

        sendJson(res, 404, {
            ok: false,
            error: 'not_found',
            path: pathname
        });
    });
}

function runCommandSyntaxPreflight() {
    const commandsPath = path.join(__dirname, 'commands');

    if (!fs.existsSync(commandsPath)) {
        return [];
    }

    const files = fs.readdirSync(commandsPath)
        .filter((file) => file.endsWith('.js'))
        .sort((a, b) => a.localeCompare(b));

    const invalidFiles = [];

    for (const file of files) {
        const filePath = path.join(commandsPath, file);
        const check = spawnSync(process.execPath, ['--check', filePath], {
            encoding: 'utf8'
        });

        if (check.status === 0) {
            continue;
        }

        const details = [
            String(check.stdout || '').trim(),
            String(check.stderr || '').trim()
        ].filter(Boolean).join('\n');

        console.error(`[BotHub Core] Syntax error in command file ${file}:`);
        if (details !== '') {
            console.error(details);
        }

        invalidFiles.push(file);
    }

    if (invalidFiles.length > 0) {
        console.warn(`[BotHub Core] Skipping invalid command files: ${invalidFiles.join(', ')}`);
    }

    return invalidFiles;
}

async function tableExists(tableName) {
    const rows = await dbQuery(
        `SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?`,
        [tableName]
    );

    return Array.isArray(rows) && rows.length > 0;
}

async function runPlexPreflight() {
    try {
        const hasAccountTable = await tableExists('user_plex_accounts');
        if (!hasAccountTable) {
            console.log('[BotHub Core] Plex preflight: user_plex_accounts table not found.');
            return;
        }

        const accountCountRows = await dbQuery('SELECT COUNT(*) AS total FROM user_plex_accounts');
        const accountCount = Array.isArray(accountCountRows) && accountCountRows[0]
            ? Number(accountCountRows[0].total || 0)
            : 0;

        let serverCount = 0;
        if (await tableExists('user_plex_servers')) {
            const serverCountRows = await dbQuery('SELECT COUNT(*) AS total FROM user_plex_servers');
            serverCount = Array.isArray(serverCountRows) && serverCountRows[0]
                ? Number(serverCountRows[0].total || 0)
                : 0;
        }

        let allowedLibraryCount = 0;
        if (await tableExists('bot_plex_libraries')) {
            const allowedCountRows = await dbQuery('SELECT COUNT(*) AS total FROM bot_plex_libraries WHERE is_allowed = 1');
            allowedLibraryCount = Array.isArray(allowedCountRows) && allowedCountRows[0]
                ? Number(allowedCountRows[0].total || 0)
                : 0;
        }

        let enabledCommandCount = 0;
        if (await tableExists('commands')) {
            const commandCountRows = await dbQuery(
                "SELECT COUNT(*) AS total FROM commands WHERE command_key LIKE 'plex-%' AND is_enabled = 1"
            );
            enabledCommandCount = Array.isArray(commandCountRows) && commandCountRows[0]
                ? Number(commandCountRows[0].total || 0)
                : 0;
        }

        console.log(
            `[BotHub Core] Plex preflight: accounts=${accountCount}, servers=${serverCount}, allowed_libraries=${allowedLibraryCount}, enabled_commands=${enabledCommandCount}`
        );
    } catch (error) {
        console.warn(
            '[BotHub Core] Plex preflight failed:',
            error instanceof Error ? error.message : String(error)
        );
    }
}

async function main() {
    console.log(`[BotHub Core] Version ${coreVersion || 'unbekannt'}`);
    console.log('[BotHub Core] Starting...');
    console.log(`[BotHub Core] Environment: ${nodeEnv}`);
    console.log(`[BotHub Core] Runner: ${runnerName}`);
    console.log(`[BotHub Core] Dashboard: ${dashboardBaseUrl}`);
    console.log(`[BotHub Core] HTTP Port: ${corePort}`);
    console.log(`[BotHub Core] Job Poll Interval: ${jobPollIntervalMs}ms`);

    await getDbPool().getConnection().then((conn) => conn.release());
    console.log('[BotHub Core] Database connection OK.');

    try {
        const pingResult = await pingDashboard();
        console.log('[BotHub Core] Dashboard connection OK:', pingResult);
    } catch (err) {
        console.warn('[BotHub Core] Dashboard ping failed:', err instanceof Error ? err.message : String(err));
    }

    await runPlexPreflight();

    const invalidCommandFiles = runCommandSyntaxPreflight();
    if (invalidCommandFiles.length > 0) {
        console.warn('[BotHub Core] Continue startup without invalid command files.');
    }

    const botManager = new BotManager();
    botManager.commandRegistry = new Map();
    loadCommands(botManager, invalidCommandFiles);

    await botManager.claimUnassignedBots();
    await botManager.syncAllBots();
    console.log(`[BotHub Core] ${botManager.clients.size}/${botManager.lastDesiredStats.desiredRunning} Bots geladen.`);

    const server = createHttpServer(botManager);

    server.listen(corePort, '0.0.0.0', async () => {
        const boundPort = server.address().port;
        console.log(`[BotHub Core] HTTP server listening on port ${boundPort}`);
        console.log('[BotHub Core] Ping endpoint: /ping');
        console.log('[BotHub Core] Health endpoint: /health');
        console.log('[BotHub Core] Reload endpoint: POST /reload');
        console.log('[BotHub Core] Reload bot endpoint: POST /reload/bot/:id');

        // When CORE_PORT=0/auto, patch the runner endpoint with the actual bound port
        let effectiveEndpoint = runnerEndpoint;
        if (corePort === 0 && effectiveEndpoint !== '') {
            try {
                const url = new URL(effectiveEndpoint);
                url.port = String(boundPort);
                effectiveEndpoint = url.toString().replace(/\/$/, '');
                console.log(`[BotHub Core] Auto-port detected: runner endpoint updated to ${effectiveEndpoint}`);
            } catch (_) {
                // URL parse failed — keep original
            }
        }

        if (effectiveEndpoint !== '') {
            // Retry registration up to 3 times — dashboard may not be immediately reachable on startup
            const maxAttempts = 3;
            const retryDelayMs = 5000;
            let registered = false;

            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    const registerResult = await registerCoreRunner(runnerName, effectiveEndpoint);
                    console.log('[BotHub Core] Runner registered:', registerResult);
                    registered = true;
                    break;
                } catch (error) {
                    const msg = describeError(error);
                    if (attempt < maxAttempts) {
                        console.warn(`[BotHub Core] Runner register failed (attempt ${attempt}/${maxAttempts}): ${msg} — retry in ${retryDelayMs / 1000}s`);
                        await new Promise(resolve => setTimeout(resolve, retryDelayMs));
                    } else {
                        console.warn(`[BotHub Core] Runner register failed after ${maxAttempts} attempts: ${msg}`);
                        console.warn(`[BotHub Core] Prüfe DASHBOARD_BASE_URL in der .env (aktuell: ${dashboardBaseUrl})`);
                        console.warn('[BotHub Core] Der Core läuft weiter — Registrierung wird beim nächsten Heartbeat erneut versucht.');
                    }
                }
            }
        } else {
            console.warn('[BotHub Core] RUNNER_ENDPOINT ist leer — Runner-Registrierung übersprungen.');
        }
    });

    const poller = new JobPoller(botManager);

    setInterval(async () => {
        try {
            await poller.pollOnce();
        } catch (error) {
            console.error('[BotHub Core] Job poll error:', error instanceof Error ? error.message : String(error));
        }
    }, jobPollIntervalMs);

    // Periodic heartbeat: re-register runner every 5 minutes to keep last_ping fresh
    if (runnerEndpoint !== '') {
        const heartbeatIntervalMs = 5 * 60 * 1000;
        // Log first failure immediately, then throttle: once every 12 ticks (~60 min)
        let heartbeatFailures = 0;

        setInterval(async () => {
            try {
                await registerCoreRunner(runnerName, runnerEndpoint);
                if (heartbeatFailures > 0) {
                    console.log('[BotHub Core] Heartbeat recovered after', heartbeatFailures, 'failure(s).');
                    heartbeatFailures = 0;
                }
            } catch (error) {
                heartbeatFailures++;
                if (heartbeatFailures === 1 || heartbeatFailures % 12 === 0) {
                    const msg = describeError(error);
                    console.warn(`[BotHub Core] Heartbeat failed (×${heartbeatFailures}): ${msg}`);
                    if (heartbeatFailures === 1) {
                        console.warn('[BotHub Core] Heartbeat-Fehler: Prüfe DASHBOARD_BASE_URL in der .env — der Core läuft normal weiter.');
                    }
                }
            }
        }, heartbeatIntervalMs);
    }
}

main().catch((error) => {
    console.error('[BotHub Core] Fatal error:', error instanceof Error ? error.message : String(error));
    process.exit(1);
});