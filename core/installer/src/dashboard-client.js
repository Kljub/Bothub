// PFAD: /core/installer/src/dashboard-client.js

const { dashboardBaseUrl, appKey } = require('./config');

const REQUEST_TIMEOUT_MS = 10_000;

/**
 * Returns a descriptive string from any thrown value, including the
 * `error.cause` that Node.js attaches to failed fetch() calls
 * (e.g. "connect ECONNREFUSED 127.0.0.1:80").
 */
function describeError(error) {
    if (!(error instanceof Error)) return String(error);
    let msg = error.message;
    if (error.cause instanceof Error) {
        msg += ` — ${error.cause.message}`;
    } else if (error.cause != null) {
        msg += ` — ${String(error.cause)}`;
    }
    return msg;
}

async function fetchWithTimeout(url, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
        if (error instanceof Error && error.name === 'AbortError') {
            throw new Error(`Request timed out after ${REQUEST_TIMEOUT_MS / 1000}s: ${url}`);
        }
        throw error;
    } finally {
        clearTimeout(timer);
    }
}

async function parseJsonResponse(response) {
    let payload = null;
    try {
        payload = await response.json();
    } catch (_) {
        throw new Error(`Dashboard returned invalid JSON (HTTP ${response.status})`);
    }
    return payload;
}

async function pingDashboard() {
    const response = await fetchWithTimeout(`${dashboardBaseUrl}/api/v1/core_ping.php`, {
        method: 'GET',
        headers: {
            'Authorization': `Bearer ${appKey}`,
            'Accept': 'application/json',
        },
    });

    const payload = await parseJsonResponse(response);

    if (!response.ok) {
        const message = payload && payload.error ? payload.error : `HTTP ${response.status}`;
        throw new Error(`Dashboard ping failed: ${message}`);
    }

    return payload;
}

async function registerCoreRunner(runnerName, endpoint) {
    const response = await fetchWithTimeout(`${dashboardBaseUrl}/api/v1/core_register.php`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${appKey}`,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ runner_name: runnerName, endpoint }),
    });

    const payload = await parseJsonResponse(response);

    if (!response.ok) {
        const message = payload && payload.message
            ? payload.message
            : (payload && payload.error ? payload.error : `HTTP ${response.status}`);
        throw new Error(`Core register failed: ${message}`);
    }

    return payload;
}

module.exports = {
    pingDashboard,
    registerCoreRunner,
    describeError,
};