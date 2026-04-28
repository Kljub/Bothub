// PFAD: /core/installer/src/community-apps/services/community-service-loader.js

const fs   = require('fs');
const path = require('path');

const SELF = path.basename(__filename);

function loadCommunityServices() {
    const dir = __dirname;
    let files;
    try {
        files = fs.readdirSync(dir).filter(f => f.endsWith('.js') && f !== SELF);
    } catch (_) {
        return [];
    }

    const services = [];

    for (const file of files) {
        const filePath = path.join(dir, file);
        try {
            delete require.cache[require.resolve(filePath)];
        } catch (_) {}

        let mod;
        try {
            mod = require(filePath);
        } catch (err) {
            console.warn(`[Community] Fehler beim Laden von Service ${file}:`, err.message);
            continue;
        }

        if (!mod || typeof mod.id !== 'string' || mod.id.trim() === '') {
            console.warn(`[Community] Service ${file} übersprungen: fehlende 'id'.`);
            continue;
        }

        if (typeof mod.onReady        !== 'function') mod.onReady        = () => {};
        if (typeof mod.onInteraction  !== 'function') mod.onInteraction  = async () => false;
        if (typeof mod.onStop         !== 'function') mod.onStop         = () => {};

        services.push(mod);
        console.log(`[Community] Service geladen: ${mod.id}`);
    }

    return services;
}

module.exports = { loadCommunityServices };
