const type = 'action.http.request';

async function execute(ctx) {
    const { cfg, node, getNextNode, localVars, rv } = ctx;

    const varName         = String(cfg.var_name || '').trim();
    const method          = String(cfg.method   || 'GET').toUpperCase();
    const optVarsUrl      = cfg.opt_vars_url      !== false;
    const optVarsParams   = cfg.opt_vars_params   !== false;
    const optVarsHeaders  = cfg.opt_vars_headers  !== false;
    const optVarsBody     = cfg.opt_vars_body     !== false;
    const optExclude      = cfg.opt_exclude_empty !== false;
    const optSanitize     = !!cfg.opt_sanitize;

    let url = (optVarsUrl ? rv(String(cfg.url || '')) : String(cfg.url || '')).trim();

    const params   = Array.isArray(cfg.params)  ? cfg.params  : [];
    const qsParts  = [];
    for (const p of params) {
        const k = optVarsParams ? rv(String(p.key   || '')) : String(p.key   || '');
        const v = optVarsParams ? rv(String(p.value || '')) : String(p.value || '');
        if (optExclude && (k === '' || v === '')) continue;
        qsParts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
    if (qsParts.length > 0) url += (url.includes('?') ? '&' : '?') + qsParts.join('&');

    const fetchHeaders = {};
    for (const h of Array.isArray(cfg.headers) ? cfg.headers : []) {
        const k = optVarsHeaders ? rv(String(h.key   || '')) : String(h.key   || '');
        const v = optVarsHeaders ? rv(String(h.value || '')) : String(h.value || '');
        if (optExclude && (k === '' || v === '')) continue;
        fetchHeaders[k] = v;
    }

    let fetchBody;
    const bodyStr = (optVarsBody ? rv(String(cfg.body || '')) : String(cfg.body || '')).trim();
    if (bodyStr && method !== 'GET' && method !== 'DELETE') {
        const bodyType = String(cfg.body_type || 'json');
        if (bodyType === 'json' && !fetchHeaders['Content-Type']) fetchHeaders['Content-Type'] = 'application/json';
        else if (bodyType === 'form' && !fetchHeaders['Content-Type']) fetchHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
        fetchBody = bodyStr;
    }

    let responseText = '';
    let statusCode   = 0;
    let statusText   = '';
    try {
        const res = await fetch(url, { method, headers: fetchHeaders, body: fetchBody });
        statusCode   = res.status;
        statusText   = res.statusText;
        responseText = await res.text();
        if (optSanitize) responseText = responseText.replace(/\{[^}]*\}/g, (m) => m.replace(/\{/g, '(').replace(/\}/g, ')'));
    } catch (fetchErr) {
        statusText = String(fetchErr instanceof Error ? fetchErr.message : fetchErr);
    }

    if (varName) {
        localVars.set(varName + '.response',   responseText);
        localVars.set(varName + '.status',     String(statusCode));
        localVars.set(varName + '.statusText', statusText);
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
