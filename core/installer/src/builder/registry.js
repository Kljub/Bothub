// Auto-discovers and registers all node handler files from ./nodes/**/*.js
// Each file must export: { type: 'node.type', execute: async (ctx) => {} }
// Optionally: { aliases: ['alt.type.name', ...] } for multiple type keys.

const fs   = require('fs');
const path = require('path');

const _handlers = new Map();
let   _loaded   = false;

function _scanDir(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            _scanDir(full);
        } else if (entry.name.endsWith('.js')) {
            try {
                const mod = require(full);
                if (!mod || typeof mod.execute !== 'function') continue;

                const types = Array.isArray(mod.aliases)
                    ? [mod.type, ...mod.aliases]
                    : [mod.type];

                for (const t of types) {
                    if (typeof t === 'string' && t) {
                        _handlers.set(t, mod.execute);
                    }
                }
            } catch (err) {
                console.warn(`[builder/registry] Failed to load "${full}":`, err.message);
            }
        }
    }
}

function loadHandlers() {
    if (_loaded) return;
    _loaded = true;
    _scanDir(path.join(__dirname, 'nodes'));
    console.log(`[builder/registry] Loaded ${_handlers.size} node handler(s).`);
}

function getHandler(type) {
    return _handlers.get(type) || null;
}

module.exports = { loadHandlers, getHandler };
