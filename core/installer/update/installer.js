// PFAD: /core/update/installer.js
//
// BotHub Core Update-Installer
// ─────────────────────────────────────────────────────────────────────────────
// Standalone HTTP server that manages the BotHub Core process lifecycle:
//   - Extracts a core.zip and runs `npm install` (called by PHP after install/update)
//   - Restarts / stops / starts the core process on demand
//   - Reports process status
//
// Endpoints (all require Bearer APP_KEY):
//   GET  /status             → is core running?
//   POST /start              → start core (no-op if already running)
//   POST /restart            → stop + start core
//   POST /stop               → stop core
//   POST /install            → body = ZIP binary; extract → npm install → restart
//
// Configuration (via .env in parent dir):
//   APP_KEY          required  shared secret (same as core)
//   UPDATER_PORT     optional  HTTP port for this service (default: 3099)
//   CORE_DIR         optional  absolute path to core dir (default: parent of this file)
//
// Usage:
//   node core/update/installer.js
// ─────────────────────────────────────────────────────────────────────────────
'use strict';

const fs      = require('fs');
const path    = require('path');
const os      = require('os');
const http    = require('http');
const { exec, spawn } = require('child_process');

// ── Load .env from parent (same .env the core uses) ──────────────────────────
try {
    require('dotenv').config({ path: path.resolve(__dirname, '..', '.env') });
} catch (_) {
    // dotenv not installed – env vars must come from shell
}

const APP_KEY     = (process.env.APP_KEY     || '').trim();
const UPDATER_PORT = parseInt(process.env.UPDATER_PORT || '3099', 10);
const CORE_DIR    = (process.env.CORE_DIR || path.resolve(__dirname, '..')).trim();

if (APP_KEY === '') {
    console.error('[updater] APP_KEY is not set. Exiting.');
    process.exit(1);
}

// ── Core process handle ───────────────────────────────────────────────────────
let coreProc   = null;
let coreStatus = 'stopped'; // 'stopped' | 'running' | 'starting' | 'stopping'

function spawnCore() {
    if (coreProc !== null) {
        console.log('[updater] Core already running (pid=' + coreProc.pid + ')');
        return;
    }

    console.log('[updater] Spawning core: node src/index.js in ' + CORE_DIR);
    coreStatus = 'starting';

    coreProc = spawn('node', ['src/index.js'], {
        cwd:   CORE_DIR,
        stdio: 'inherit',
        env:   process.env,
        detached: false,
    });

    coreProc.on('spawn', () => {
        coreStatus = 'running';
        console.log('[updater] Core started (pid=' + coreProc.pid + ')');
    });

    coreProc.on('error', (err) => {
        console.error('[updater] Core spawn error:', err.message);
        coreProc   = null;
        coreStatus = 'stopped';
    });

    coreProc.on('exit', (code, signal) => {
        console.log('[updater] Core exited — code=' + code + ' signal=' + signal);
        coreProc   = null;
        coreStatus = 'stopped';
    });
}

function stopCore(cb) {
    if (coreProc === null) {
        coreStatus = 'stopped';
        if (cb) cb();
        return;
    }

    coreStatus = 'stopping';
    console.log('[updater] Stopping core (pid=' + coreProc.pid + ')...');

    const proc = coreProc;

    const killTimeout = setTimeout(() => {
        console.warn('[updater] Core did not exit in time — sending SIGKILL');
        try { proc.kill('SIGKILL'); } catch (_) {}
    }, 8000);

    proc.once('exit', () => {
        clearTimeout(killTimeout);
        coreProc   = null;
        coreStatus = 'stopped';
        console.log('[updater] Core stopped.');
        if (cb) cb();
    });

    try { proc.kill('SIGTERM'); } catch (_) {}
}

function restartCore(cb) {
    stopCore(() => {
        setTimeout(() => {
            spawnCore();
            if (cb) cb();
        }, 1000);
    });
}

// ── HTTP helpers ──────────────────────────────────────────────────────────────
function sendJson(res, status, body) {
    const raw = JSON.stringify(body);
    res.writeHead(status, {
        'Content-Type':   'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(raw),
    });
    res.end(raw);
}

function authorized(req) {
    const auth = (req.headers['authorization'] || '').trim();
    const match = auth.match(/^Bearer\s+(.+)$/i);
    if (!match) return false;
    // Constant-time compare
    const received = match[1].trim();
    if (received.length !== APP_KEY.length) return false;
    let diff = 0;
    for (let i = 0; i < APP_KEY.length; i++) {
        diff |= APP_KEY.charCodeAt(i) ^ received.charCodeAt(i);
    }
    return diff === 0;
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        req.on('data', c => chunks.push(c));
        req.on('end',  () => resolve(Buffer.concat(chunks)));
        req.on('error', reject);
    });
}

// ── Install routine ───────────────────────────────────────────────────────────
function runInstall(zipBuffer, cb) {
    const doNpmInstall = (next) => {
        console.log('[updater] Running npm install --production in', CORE_DIR);
        exec('npm install --production', { cwd: CORE_DIR, timeout: 120000 }, (err, stdout, stderr) => {
            if (err) console.error('[updater] npm install error:', err.message, stderr.trim());
            else     console.log('[updater] npm install OK.', stdout.trim().split('\n').pop());
            next();
        });
    };

    if (!zipBuffer || zipBuffer.length === 0) {
        console.log('[updater] No ZIP — only npm install + restart.');
        doNpmInstall(() => restartCore(cb));
        return;
    }

    const tmpZip = path.join(os.tmpdir(), 'bothub_core_update_' + Date.now() + '.zip');
    fs.writeFile(tmpZip, zipBuffer, (writeErr) => {
        if (writeErr) {
            console.error('[updater] Could not write ZIP:', writeErr.message);
            doNpmInstall(() => restartCore(cb));
            return;
        }

        console.log('[updater] Extracting ZIP (' + zipBuffer.length + ' bytes) to', CORE_DIR);
        exec('unzip -o "' + tmpZip + '" -d "' + CORE_DIR + '"', { timeout: 60000 }, (unzipErr, out) => {
            try { fs.unlinkSync(tmpZip); } catch (_) {}

            if (unzipErr) console.error('[updater] unzip error:', unzipErr.message);
            else          console.log('[updater] unzip OK.');

            doNpmInstall(() => restartCore(cb));
        });
    });
}

// ── HTTP server ───────────────────────────────────────────────────────────────
const server = http.createServer(async (req, res) => {
    const method   = (req.method || 'GET').toUpperCase();
    const pathname = (req.url || '/').split('?')[0];

    if (!authorized(req)) {
        sendJson(res, 401, { ok: false, error: 'unauthorized' });
        return;
    }

    // GET /status
    if (method === 'GET' && pathname === '/status') {
        sendJson(res, 200, {
            ok:      true,
            status:  coreStatus,
            running: coreProc !== null,
            pid:     coreProc ? coreProc.pid : null,
            core_dir: CORE_DIR,
            uptime:  Math.floor(process.uptime()),
        });
        return;
    }

    // POST /start
    if (method === 'POST' && pathname === '/start') {
        if (coreProc !== null) {
            sendJson(res, 200, { ok: true, message: 'Core already running.', pid: coreProc.pid });
            return;
        }
        spawnCore();
        sendJson(res, 200, { ok: true, message: 'Core start initiated.' });
        return;
    }

    // POST /stop
    if (method === 'POST' && pathname === '/stop') {
        sendJson(res, 200, { ok: true, message: 'Core stop initiated.' });
        stopCore(() => {});
        return;
    }

    // POST /restart
    if (method === 'POST' && pathname === '/restart') {
        sendJson(res, 200, { ok: true, message: 'Core restart initiated.' });
        restartCore(() => {});
        return;
    }

    // POST /install  (ZIP body optional)
    if (method === 'POST' && pathname === '/install') {
        let zipBuffer;
        try {
            zipBuffer = await readBody(req);
        } catch (e) {
            sendJson(res, 500, { ok: false, error: 'body_read_failed' });
            return;
        }

        // Stop core first, then extract + npm install + restart
        console.log('[updater] Install triggered (zip=' + zipBuffer.length + ' bytes)');
        stopCore(() => {
            runInstall(zipBuffer.length > 0 ? zipBuffer : null, () => {
                console.log('[updater] Install complete.');
            });
        });

        // Respond immediately so the caller doesn't time out
        sendJson(res, 200, { ok: true, message: 'Install started. Core will restart shortly.' });
        return;
    }

    sendJson(res, 404, { ok: false, error: 'not_found' });
});

server.listen(UPDATER_PORT, '0.0.0.0', () => {
    console.log('[updater] BotHub Core Updater listening on port ' + UPDATER_PORT);
    console.log('[updater] Core directory: ' + CORE_DIR);
    console.log('[updater] Endpoints: GET /status  POST /start /stop /restart /install');

    // Auto-start core on launch
    spawnCore();
});

process.on('SIGTERM', () => {
    console.log('[updater] SIGTERM received — stopping core and exiting...');
    stopCore(() => process.exit(0));
});

process.on('SIGINT', () => {
    console.log('[updater] SIGINT received — stopping core and exiting...');
    stopCore(() => process.exit(0));
});
