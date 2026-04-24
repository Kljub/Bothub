// PFAD: /core/installer/src/services/ai-service.js
const { dbQuery } = require('../db');

const PROVIDER_DEFAULTS = {
    openai:    { base_url: 'https://api.openai.com/v1',                model: 'gpt-4o-mini' },
    nvidia:    { base_url: 'https://integrate.api.nvidia.com/v1',      model: 'meta/llama-3.1-70b-instruct' },
    anthropic: { base_url: 'https://api.anthropic.com/v1',             model: 'claude-haiku-4-5-20251001' },
    groq:      { base_url: 'https://api.groq.com/openai/v1',           model: 'llama-3.1-8b-instant' },
    ollama:    { base_url: 'http://localhost:11434/v1',                 model: 'llama3' },
    custom:    { base_url: '',                                          model: '' },
};

// ── Conversation cache ─────────────────────────────────────────────────────────
// key: `${botId}:${userId}` → { messages: [{role, content}], lastActivity: ms, timeoutMs: ms }
const conversationCache = new Map();

// Cleanup loop — runs every 60s, removes expired sessions
setInterval(() => {
    const now = Date.now();
    for (const [key, session] of conversationCache.entries()) {
        if (now - session.lastActivity > session.timeoutMs) {
            conversationCache.delete(key);
        }
    }
}, 60_000).unref();

function getSession(botId, userId, timeoutMs) {
    const key = `${botId}:${userId}`;
    let session = conversationCache.get(key);
    if (!session) {
        session = { messages: [], lastActivity: Date.now(), timeoutMs };
        conversationCache.set(key, session);
    } else {
        session.lastActivity = Date.now();
        session.timeoutMs = timeoutMs; // refresh timeout setting
    }
    return session;
}

function clearSession(botId, userId) {
    conversationCache.delete(`${botId}:${userId}`);
}

function clearAllConversations(botId) {
    const prefix = `${botId}:`;
    for (const key of conversationCache.keys()) {
        if (key.startsWith(prefix)) conversationCache.delete(key);
    }
}

// ── Table init ─────────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_ai_settings\` (
        \`bot_id\`               BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        \`active_provider\`      VARCHAR(50)     NOT NULL DEFAULT 'openai',
        \`system_prompt\`        TEXT            NULL DEFAULT NULL,
        \`max_tokens\`           INT             NOT NULL DEFAULT 1000,
        \`temperature\`          DECIMAL(3,2)    NOT NULL DEFAULT 0.70,
        \`history_length\`       INT             NOT NULL DEFAULT 10,
        \`session_timeout_min\`  INT             NOT NULL DEFAULT 30,
        \`web_search_enabled\`   TINYINT(1)      NOT NULL DEFAULT 0,
        \`brave_api_key\`        TEXT            NULL DEFAULT NULL,
        \`searxng_url\`          VARCHAR(500)    NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_ai_providers\` (
        \`id\`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        \`bot_id\`         BIGINT UNSIGNED NOT NULL,
        \`provider\`       VARCHAR(50)     NOT NULL,
        \`api_key\`        TEXT            NULL DEFAULT NULL,
        \`base_url\`       VARCHAR(500)    NULL DEFAULT NULL,
        \`selected_model\` VARCHAR(255)    NULL DEFAULT NULL,
        UNIQUE KEY \`uq_bot_provider\` (\`bot_id\`, \`provider\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_ai_allowed_channels\` (
        \`bot_id\`     BIGINT UNSIGNED NOT NULL,
        \`channel_id\` VARCHAR(20)     NOT NULL,
        PRIMARY KEY (\`bot_id\`, \`channel_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    // Add new columns if table existed before this migration
    for (const col of [
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `history_length`          INT        NOT NULL DEFAULT 10",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `session_timeout_min`     INT        NOT NULL DEFAULT 30",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `web_search_enabled`      TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `brave_api_key`           TEXT       NULL DEFAULT NULL",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `searxng_url`             VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `mention_enabled`         TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `mention_context_messages` INT       NOT NULL DEFAULT 10",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `web_search_always`        TINYINT(1) NOT NULL DEFAULT 0",
    ]) {
        await dbQuery(col).catch(() => {});
    }
}

// ── Load settings + provider config ───────────────────────────────────────────
async function loadAIConfig(botId) {
    const numericId = Number(botId);

    const settingsRows = await dbQuery(
        'SELECT * FROM bot_ai_settings WHERE bot_id = ? LIMIT 1',
        [numericId]
    );
    const settings = settingsRows[0] || {
        active_provider: 'openai', system_prompt: null,
        max_tokens: 1000, temperature: 0.70,
        history_length: 10, session_timeout_min: 30,
        web_search_enabled: 0, brave_api_key: null,
    };

    const providerRows = await dbQuery(
        'SELECT * FROM bot_ai_providers WHERE bot_id = ? AND provider = ? LIMIT 1',
        [numericId, settings.active_provider]
    );
    const providerRow = providerRows[0] || {};

    const defaults = PROVIDER_DEFAULTS[settings.active_provider] || PROVIDER_DEFAULTS.custom;
    const baseUrl   = (providerRow.base_url || defaults.base_url || '').replace(/\/$/, '');
    const model     = providerRow.selected_model || defaults.model || '';
    const apiKey    = providerRow.api_key || '';

    return { settings, baseUrl, model, apiKey };
}

// ── Web search ─────────────────────────────────────────────────────────────────
// Priority: Brave Search (if key) → SearXNG (if URL) → DuckDuckGo (free fallback)
async function webSearch(query, { braveApiKey = '', searxngUrl = '' } = {}) {
    const fetchFn = typeof fetch !== 'undefined' ? fetch : require('node-fetch');
    const q = encodeURIComponent(query);

    // 1. Brave Search API
    if (braveApiKey) {
        try {
            const resp = await fetchFn(
                `https://api.search.brave.com/res/v1/web/search?q=${q}&count=4`,
                { headers: { 'Accept': 'application/json', 'X-Subscription-Token': braveApiKey } }
            );
            if (resp.ok) {
                const data = await resp.json();
                const results = (data.web?.results || []).slice(0, 4).map(r => ({
                    title: r.title, snippet: r.description || '', url: r.url,
                }));
                if (results.length) return results;
            }
        } catch (_) {}
    }

    // 2. SearXNG
    if (searxngUrl) {
        try {
            const base = searxngUrl.replace(/\/$/, '');
            const resp = await fetchFn(
                `${base}/search?q=${q}&format=json&categories=general`,
                { headers: { 'Accept': 'application/json', 'User-Agent': 'BotHub/1.0' } }
            );
            if (resp.ok) {
                const data = await resp.json();
                const results = (data.results || []).slice(0, 4).map(r => ({
                    title: r.title || '', snippet: r.content || '', url: r.url || '',
                }));
                if (results.length) return results;
            }
        } catch (_) {}
    }

    // 3. DuckDuckGo Instant Answers (free, no key)
    try {
        const resp = await fetchFn(
            `https://api.duckduckgo.com/?q=${q}&format=json&no_html=1&skip_disambig=1`,
            { headers: { 'User-Agent': 'BotHub/1.0' } }
        );
        if (!resp.ok) return [];
        const data = await resp.json();
        const results = [];
        if (data.AbstractText) {
            results.push({ title: data.Heading || query, snippet: data.AbstractText, url: data.AbstractURL });
        }
        for (const r of (data.RelatedTopics || [])) {
            if (r.Text && results.length < 4) {
                results.push({ title: r.Text.split(' - ')[0] || '', snippet: r.Text, url: r.FirstURL || '' });
            }
        }
        return results;
    } catch (_) {
        return [];
    }
}

function buildSearchContext(query, results) {
    if (!results.length) return `[Websuche für "${query}" lieferte keine Ergebnisse]`;
    const lines = results.map((r, i) =>
        `${i + 1}. **${r.title}**\n   ${r.snippet}${r.url ? `\n   ${r.url}` : ''}`
    );
    return `[Websuche: "${query}"]\n${lines.join('\n\n')}`;
}

// ── Call AI ────────────────────────────────────────────────────────────────────
async function askAI(botId, userId, userMessage, { useWeb = false } = {}) {
    await ensureTables();

    const { settings, baseUrl, model, apiKey } = await loadAIConfig(botId);

    if (!baseUrl) throw new Error('Kein AI-Anbieter konfiguriert.');
    if (!model)   throw new Error('Kein Modell ausgewählt.');

    const historyLength  = Number(settings.history_length)      || 10;
    const timeoutMs      = (Number(settings.session_timeout_min) || 30) * 60 * 1000;
    const webEnabled     = Boolean(settings.web_search_enabled);
    const webAlways      = Boolean(settings.web_search_always);
    const shouldSearch   = webEnabled && (useWeb || webAlways);

    // Build message array
    const messages = [];

    // System prompt
    if (settings.system_prompt) {
        messages.push({ role: 'system', content: settings.system_prompt });
    }

    // Inject web search context as a system message before the conversation
    if (shouldSearch) {
        const results = await webSearch(userMessage, {
            braveApiKey: settings.brave_api_key || '',
            searxngUrl:  settings.searxng_url   || '',
        }).catch(() => []);
        const ctx = buildSearchContext(userMessage, results);
        messages.push({ role: 'system', content: `Nutze folgende aktuelle Websuche-Ergebnisse für deine Antwort:\n\n${ctx}` });
    }

    // Conversation history
    const session  = getSession(botId, userId, timeoutMs);
    const history  = session.messages.slice(-(historyLength * 2)); // keep last N pairs
    messages.push(...history);

    // New user message
    messages.push({ role: 'user', content: userMessage });

    const provider = settings.active_provider;
    const headers  = { 'Content-Type': 'application/json' };
    if (provider === 'anthropic') {
        headers['x-api-key']         = apiKey;
        headers['anthropic-version'] = '2023-06-01';
    } else if (apiKey) {
        headers['Authorization'] = `Bearer ${apiKey}`;
    }

    const body = JSON.stringify({
        model,
        messages,
        max_tokens:  Number(settings.max_tokens) || 1000,
        temperature: parseFloat(settings.temperature) || 0.7,
    });

    const fetchFn = typeof fetch !== 'undefined' ? fetch : require('node-fetch');

    let endpoint;
    if (provider === 'anthropic') {
        endpoint = `${baseUrl}/messages`;
    } else if (provider === 'ollama') {
        const ollamaBase = baseUrl.replace(/\/v1\/?$/, '');
        endpoint = `${ollamaBase}/v1/chat/completions`;
    } else {
        endpoint = `${baseUrl}/chat/completions`;
    }

    const resp = await fetchFn(endpoint, { method: 'POST', headers, body });

    if (!resp.ok) {
        const text = await resp.text().catch(() => '');
        throw new Error(`AI API error ${resp.status}: ${text.slice(0, 200)}`);
    }

    const json = await resp.json();

    const answer = provider === 'anthropic'
        ? (json.content?.[0]?.text || '(keine Antwort)')
        : (json.choices?.[0]?.message?.content || '(keine Antwort)');

    // Save exchange to session history
    session.messages.push({ role: 'user',      content: userMessage });
    session.messages.push({ role: 'assistant', content: answer });
    // Trim history to prevent unbounded growth
    if (session.messages.length > historyLength * 2 + 10) {
        session.messages.splice(0, session.messages.length - historyLength * 2);
    }

    return answer;
}

// ── Mention handler ────────────────────────────────────────────────────────────
function attachMentionEvents(client, botId) {
    const numericId = Number(botId);

    ensureTables().catch(err => console.warn(`[AI] Bot ${numericId}: table init:`, err.message));

    client.on('messageCreate', async (message) => {
        try {
            // Ignore bots and messages without a bot mention
            if (message.author.bot) return;
            if (!message.mentions.has(client.user)) return;

            // Load settings
            const settingsRows = await dbQuery(
                'SELECT * FROM bot_ai_settings WHERE bot_id = ? LIMIT 1',
                [numericId]
            );
            const settings = settingsRows[0] || {};
            if (!settings.mention_enabled) return;

            // Check allowed channels
            const allowedRows = await dbQuery(
                'SELECT channel_id FROM bot_ai_allowed_channels WHERE bot_id = ?',
                [numericId]
            );
            if (allowedRows.length > 0) {
                const allowed = allowedRows.map(r => r.channel_id);
                if (!allowed.includes(message.channelId)) return;
            }

            // Strip the @mention from the message text
            const rawText = message.content
                .replace(/<@!?\d+>/g, '')
                .trim();

            if (!rawText) return;

            // Check for web: prefix or always-on setting
            const hasWebPrefix = /^web:/i.test(rawText);
            const useWeb   = hasWebPrefix || Boolean(settings.web_search_always && settings.web_search_enabled);
            const question = hasWebPrefix ? rawText.replace(/^web:\s*/i, '').trim() : rawText;

            if (!question) return;

            // Fetch channel context (last N messages before this one)
            const contextCount = Number(settings.mention_context_messages) || 10;
            let contextBlock = '';
            if (contextCount > 0 && message.channel?.messages) {
                const fetched = await message.channel.messages
                    .fetch({ limit: contextCount + 1, before: message.id })
                    .catch(() => null);

                if (fetched && fetched.size > 0) {
                    const lines = [...fetched.values()]
                        .reverse()
                        .map(m => `${m.author.username}: ${m.content.replace(/<@!?\d+>/g, '').trim()}`)
                        .filter(l => l.length > 2);

                    if (lines.length > 0) {
                        contextBlock = `[Channel-Verlauf der letzten ${lines.length} Nachrichten]\n${lines.join('\n')}\n\n`;
                    }
                }
            }

            const fullQuestion = contextBlock ? `${contextBlock}Neue Frage: ${question}` : question;

            await message.channel.sendTyping().catch(() => {});

            const answer = await askAI(numericId, message.author.id, fullQuestion, { useWeb });

            const webTag = useWeb ? ' 🌐' : '';
            const text   = answer.length > 1900 ? answer.slice(0, 1900) + '…' : answer;

            await message.reply(`🤖${webTag} ${text}`);
        } catch (err) {
            console.error(`[AI] Bot ${numericId}: mention error:`, err.message);
        }
    });
}

module.exports = { ensureTables, askAI, clearSession, clearAllConversations, attachMentionEvents, PROVIDER_DEFAULTS };
