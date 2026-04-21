// PFAD: /core/installer/src/config.js

const path = require('path');
const dotenv = require('dotenv');

dotenv.config({
    path: path.resolve(__dirname, '..', '.env')
});

function requireEnv(name) {
    const value = process.env[name];

    if (typeof value !== 'string' || value.trim() === '') {
        throw new Error(`Missing required environment variable: ${name}`);
    }

    return value.trim();
}

function parsePort(value, fallback) {
    const raw = typeof value === 'string' ? value.trim() : '';
    if (raw === '' || raw.toLowerCase() === 'auto') {
        // 0 = let the OS pick a free port (server.address().port after listen)
        return 0;
    }

    const parsed = Number.parseInt(raw, 10);
    if (!Number.isInteger(parsed) || parsed < 0 || parsed > 65535) {
        throw new Error(`Invalid port: ${raw}`);
    }

    return parsed;
}

function parsePositiveInt(value, fallback) {
    const raw = typeof value === 'string' ? value.trim() : '';
    if (raw === '') {
        return fallback;
    }

    const parsed = Number.parseInt(raw, 10);
    if (!Number.isInteger(parsed) || parsed < 1) {
        throw new Error(`Invalid positive integer: ${raw}`);
    }

    return parsed;
}

module.exports = {
    nodeEnv: process.env.NODE_ENV ? String(process.env.NODE_ENV).trim() : 'production',
    dashboardBaseUrl: requireEnv('DASHBOARD_BASE_URL').replace(/\/+$/, ''),
    appKey: requireEnv('APP_KEY'),
    corePort: parsePort(process.env.CORE_PORT, 3000),
    runnerName: process.env.RUNNER_NAME ? String(process.env.RUNNER_NAME).trim() : 'bothub-core-1',
    runnerEndpoint: process.env.RUNNER_ENDPOINT ? String(process.env.RUNNER_ENDPOINT).trim().replace(/\/+$/, '') : '',
    jobPollIntervalMs: parsePositiveInt(process.env.JOB_POLL_INTERVAL_MS, 5000),
    db: {
        host: requireEnv('DB_HOST'),
        port: parsePort(process.env.DB_PORT, 3306),
        name: requireEnv('DB_NAME'),
        user: requireEnv('DB_USER'),
        pass: process.env.DB_PASS ? String(process.env.DB_PASS) : ''
    }
};