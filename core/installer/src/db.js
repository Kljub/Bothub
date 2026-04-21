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

module.exports = {
    getDbPool,
    dbQuery
};