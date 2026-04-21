// PFAD: /core/installer/scripts/download-yt-dlp.js
// Downloads yt-dlp binary into bin/ during npm install so it works in any environment.

const https  = require('https');
const fs     = require('fs');
const path   = require('path');
const { execSync } = require('child_process');

const BIN_DIR  = path.join(__dirname, '..', 'bin');
const BIN_PATH = path.join(BIN_DIR, 'yt-dlp');

// Skip if already present and executable
if (fs.existsSync(BIN_PATH)) {
    try {
        const ver = execSync(`"${BIN_PATH}" --version 2>/dev/null`, { encoding: 'utf8', timeout: 5000 }).trim();
        if (ver) {
            console.log(`[yt-dlp] Already installed: ${ver}`);
            process.exit(0);
        }
    } catch (_) {}
}

// Determine download URL based on platform/arch
const platform = process.platform;
const arch     = process.arch;

let url;
if (platform === 'linux') {
    url = arch === 'arm64'
        ? 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux_aarch64'
        : 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux';
} else if (platform === 'darwin') {
    url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_macos';
} else {
    console.warn('[yt-dlp] Unsupported platform for auto-download:', platform);
    process.exit(0);
}

fs.mkdirSync(BIN_DIR, { recursive: true });

console.log(`[yt-dlp] Downloading from ${url} ...`);

function download(downloadUrl, dest, redirects = 0) {
    if (redirects > 10) {
        console.error('[yt-dlp] Too many redirects.');
        process.exit(0);
    }
    https.get(downloadUrl, (res) => {
        if (res.statusCode === 301 || res.statusCode === 302 || res.statusCode === 307 || res.statusCode === 308) {
            return download(res.headers.location, dest, redirects + 1);
        }
        if (res.statusCode !== 200) {
            console.error(`[yt-dlp] Download failed: HTTP ${res.statusCode}`);
            process.exit(0);
        }
        const tmp = dest + '.tmp';
        const out = fs.createWriteStream(tmp);
        res.pipe(out);
        out.on('finish', () => {
            out.close(() => {
                fs.renameSync(tmp, dest);
                fs.chmodSync(dest, 0o755);
                try {
                    const ver = execSync(`"${dest}" --version 2>/dev/null`, { encoding: 'utf8', timeout: 5000 }).trim();
                    console.log(`[yt-dlp] Installed: ${ver} → ${dest}`);
                } catch (_) {
                    console.log(`[yt-dlp] Installed → ${dest}`);
                }
            });
        });
        out.on('error', (err) => {
            console.error('[yt-dlp] Write error:', err.message);
            try { fs.unlinkSync(tmp); } catch (_) {}
        });
    }).on('error', (err) => {
        console.error('[yt-dlp] Download error:', err.message);
    });
}

download(url, BIN_PATH);
