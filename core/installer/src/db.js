// PFAD: /core/installer/src/db.js

const mysql = require('mysql2/promise');
const { db } = require('./config');

let pool = null;

function getDbPool() {
    if (pool) {
        return pool;
    }

    pool = mysql.createPool({
        host: db.host,
        port: db.port,
        database: db.name,
        user: db.user,
        password: db.pass,
        charset: 'utf8mb4',
        timezone: 'Z',         // all DATETIME values treated as UTC
        waitForConnections: true,
        connectionLimit: 10,
        queueLimit: 0
    });

    return pool;
}

async function dbQuery(sql, params = []) {
    const currentPool = getDbPool();
    const [rows] = await currentPool.execute(sql, params);
    return rows;
}

async function botLog(botId, level, message, context = null) {
    try {
        await dbQuery(
            'INSERT INTO bot_logs (bot_id, level, message, context_json) VALUES (?, ?, ?, ?)',
            [botId, level, String(message).slice(0, 65535), context ? JSON.stringify(context) : null]
        );
    } catch (_) {}
}

const _METRIC_FIELDS = new Set(['cmd_calls', 'errors', 'uptime_ok']);

async function bumpMetric(botId, field) {
    if (!_METRIC_FIELDS.has(field)) return;
    const now = new Date();
    // Round down to the nearest 5-minute boundary in UTC
    now.setUTCSeconds(0, 0);
    now.setUTCMinutes(Math.floor(now.getUTCMinutes() / 5) * 5);
    const bucket = now.toISOString().slice(0, 19).replace('T', ' ');
    try {
        await dbQuery(
            `INSERT INTO bot_metrics_5m (bot_id, bucket_at, ${field})
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE ${field} = ${field} + 1`,
            [Number(botId), bucket]
        );
    } catch (_) {}
}

module.exports = {
    getDbPool,
    dbQuery,
    botLog,
    bumpMetric,
};