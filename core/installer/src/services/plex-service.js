// PFAD: /core/installer/src/services/plex-service.js

const { dbQuery } = require('../db');

const PLEX_COMMAND_KEYS = [
    'plex-search',
    'plex-random',
    'plex-info',
    'plex-stats',
    'plex-play',
    'plex-recently-added',
    'plex-on-deck'
];

function xmlDecode(value) {
    return String(value || '')
        .replace(/&#x([0-9a-fA-F]+);/g, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
        .replace(/&#([0-9]+);/g,         (_, dec) => String.fromCodePoint(parseInt(dec, 10)))
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'")
        .replace(/&lt;/g,   '<')
        .replace(/&gt;/g,   '>')
        .replace(/&amp;/g,  '&');
}

function parseAttributes(raw) {
    const attributes = {};
    const regex = /([A-Za-z0-9:_-]+)="([^"]*)"/g;
    let match = null;

    while ((match = regex.exec(raw)) !== null) {
        attributes[match[1]] = xmlDecode(match[2]);
    }

    return attributes;
}

function parseMediaContainerAttributes(xml) {
    const match = xml.match(/<MediaContainer([^>]*)>/i);
    return match ? parseAttributes(match[1]) : {};
}

function parseMediaItems(xml) {
    const items = [];
    const regex = /<(Video|Directory|Track|Photo)\b((?:[^>\"']|\"[^\"]*\"|'[^']*')*)\/?>/gi;
    let match = null;

    while ((match = regex.exec(xml)) !== null) {
        const tag = String(match[1] || '').trim().toLowerCase();
        const attrs = parseAttributes(String(match[2] || ''));

        items.push({
            tag,
            type: String(attrs.type || tag).trim().toLowerCase(),
            title: String(attrs.title || attrs.grandparentTitle || attrs.originalTitle || 'Unbekannt').trim(),
            year: String(attrs.year || '').trim(),
            summary: String(attrs.summary || '').trim(),
            thumb: String(attrs.thumb || '').trim(),
            ratingKey: String(attrs.ratingKey || '').trim(),
            key: String(attrs.key || '').trim()
        });
    }

    return items;
}

function sortConnections(connections) {
    const sorted = [];

    for (const connection of connections) {
        if (!connection || typeof connection !== 'object') {
            continue;
        }

        const uri = String(connection.uri || '').trim();
        if (uri === '') {
            continue;
        }

        let score = 0;
        if (String(connection.protocol || '').trim() === 'https') {
            score += 40;
        }
        if (!connection.relay) {
            score += 30;
        }
        if (connection.local) {
            score += 20;
        }

        sorted.push({ ...connection, _score: score });
    }

    sorted.sort((a, b) => b._score - a._score);
    return sorted.map((connection) => {
        delete connection._score;
        return connection;
    });
}

async function plexFetchXml(baseUri, token, endpoint, extraHeaders = {}) {
    const response = await fetch(`${baseUri}${endpoint}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/xml',
            'X-Plex-Token': token,
            'X-Plex-Product': 'BotHub Core',
            'X-Plex-Version': '1.0',
            'X-Plex-Device': 'BotHub Core',
            'X-Plex-Device-Name': 'BotHub Core',
            'X-Plex-Client-Identifier': 'bothub-core-plex',
            ...extraHeaders
        }
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const text = await response.text();
    // Newer Plex versions return total count in response header instead of XML attribute
    const headerTotal = response.headers.get('X-Plex-Container-Total-Count')
        || response.headers.get('x-plex-container-total-count');
    return { text, headerTotal };
}

async function requestFromLibraryConnection(library, endpoint, extraHeaders = {}) {
    const token = String(library.access_token || '').trim();
    if (token === '') {
        throw new Error('Missing Plex access token.');
    }

    const connections = sortConnections(Array.isArray(library.connections) ? library.connections : []);
    if (connections.length === 0) {
        throw new Error('No Plex connections available.');
    }

    let lastError = 'Unknown Plex request error.';

    for (const connection of connections) {
        const baseUri = String(connection.uri || '').trim().replace(/\/+$/, '');
        if (baseUri === '') {
            continue;
        }

        try {
            const { text, headerTotal } = await plexFetchXml(baseUri, token, endpoint, extraHeaders);
            console.log(`[plex-service] Connection OK: ${baseUri}${endpoint}`);
            return { xml: text, baseUri, headerTotal };
        } catch (error) {
            lastError = error instanceof Error ? error.message : String(error);
            console.warn(`[plex-service] Connection failed: ${baseUri}${endpoint} → ${lastError}`);
        }
    }

    throw new Error(lastError);
}

async function loadBotOwner(botId) {
    const rows = await dbQuery(
        `
        SELECT id, owner_user_id
        FROM bot_instances
        WHERE id = ?
        LIMIT 1
        `,
        [Number(botId)]
    );

    if (!Array.isArray(rows) || rows.length === 0) {
        throw new Error('Bot nicht gefunden.');
    }

    return rows[0];
}

async function loadAllowedLibraries(botId) {
    const rows = await dbQuery(
        `
        SELECT
            bpl.resource_identifier,
            bpl.server_name,
            bpl.library_key,
            bpl.library_title,
            bpl.library_type,
            bpl.plex_search_type,
            ups.access_token,
            ups.connections_json,
            ups.product,
            ups.product_version,
            ups.platform,
            ups.platform_version,
            ups.device,
            ups.presence,
            ups.owned
        FROM bot_plex_libraries AS bpl
        INNER JOIN user_plex_servers AS ups
            ON ups.plex_account_id = bpl.plex_account_id
           AND ups.resource_identifier = bpl.resource_identifier
        WHERE bpl.bot_id = ?
          AND bpl.is_allowed = 1
        ORDER BY bpl.server_name ASC, bpl.library_title ASC
        `,
        [Number(botId)]
    );

    if (!Array.isArray(rows)) {
        return [];
    }

    return rows.map((row) => {
        let connections = [];
        try {
            const decoded = JSON.parse(String(row.connections_json || '[]'));
            if (Array.isArray(decoded)) {
                connections = decoded;
            }
        } catch (error) {
            connections = [];
        }

        return {
            resource_identifier: String(row.resource_identifier || '').trim(),
            server_name: String(row.server_name || '').trim(),
            library_key: String(row.library_key || '').trim(),
            library_title: String(row.library_title || '').trim(),
            library_type: String(row.library_type || '').trim(),
            plex_search_type: row.plex_search_type != null ? Number(row.plex_search_type) : null,
            access_token: String(row.access_token || '').trim(),
            connections,
            product: String(row.product || '').trim(),
            product_version: String(row.product_version || '').trim(),
            platform: String(row.platform || '').trim(),
            platform_version: String(row.platform_version || '').trim(),
            device: String(row.device || '').trim(),
            presence: !!row.presence,
            owned: !!row.owned
        };
    });
}

async function isPlexCommandEnabled(botId, commandKey) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [Number(botId), String(commandKey)]
    );

    // Default to enabled if no row exists
    if (!Array.isArray(rows) || rows.length === 0) {
        return true;
    }

    return Number(rows[0].is_enabled || 0) === 1;
}

async function assertPlexCommandEnabled(botId, commandKey) {
    const enabled = await isPlexCommandEnabled(botId, commandKey);
    if (!enabled) {
        throw new Error('Dieser Plex Command ist für den Bot nicht aktiviert.');
    }
}

async function getPlexContext(botId) {
    const bot = await loadBotOwner(botId);
    const libraries = await loadAllowedLibraries(botId);

    return {
        botId: Number(bot.id),
        ownerUserId: Number(bot.owner_user_id),
        libraries
    };
}

async function searchAllowedLibraries(botId, query, limit = 5) {
    const context = await getPlexContext(botId);
    const search = String(query || '').trim();
    if (search === '') {
        return [];
    }

    const results = [];
    const seen = new Set();

    for (const library of context.libraries) {
        const token = String(library.access_token || '').trim();
        const typeParam = library.plex_search_type != null ? `&type=${library.plex_search_type}` : '';
        const endpoint = `/library/sections/${encodeURIComponent(library.library_key)}/search?query=${encodeURIComponent(search)}${typeParam}`;

        let xml = '';
        let baseUri = '';

        try {
            ({ xml, baseUri } = await requestFromLibraryConnection(library, endpoint));
        } catch (error) {
            continue;
        }

        const items = parseMediaItems(xml);
        for (const item of items) {
            const uniqueKey = `${library.resource_identifier}:${library.library_key}:${item.ratingKey}:${item.title}`;
            if (seen.has(uniqueKey)) {
                continue;
            }

            seen.add(uniqueKey);

            let thumbnailUrl = '';
            if (item.thumb && baseUri && token) {
                thumbnailUrl = `${baseUri}${item.thumb}?X-Plex-Token=${token}`;
            }

            results.push({
                title: item.title,
                year: item.year,
                summary: item.summary,
                type: item.type || library.library_type,
                library_title: library.library_title,
                server_name: library.server_name,
                thumbnailUrl
            });

            if (results.length >= limit) {
                return results;
            }
        }
    }

    return results;
}

async function getRandomAllowedItem(botId, libraryFilter = null) {
    const context = await getPlexContext(botId);
    if (context.libraries.length === 0) {
        return null;
    }

    let allLibraries = context.libraries;
    if (libraryFilter && typeof libraryFilter === 'string' && libraryFilter.trim() !== '') {
        const filter = libraryFilter.trim().toLowerCase();
        const filtered = allLibraries.filter(
            (lib) => lib.library_title && lib.library_title.toLowerCase().includes(filter)
        );
        if (filtered.length > 0) {
            allLibraries = filtered;
        }
    }

    const candidates = [...allLibraries];
    while (candidates.length > 0) {
        const libraryIndex = Math.floor(Math.random() * candidates.length);
        const library = candidates.splice(libraryIndex, 1)[0];

        const typeParam = library.plex_search_type != null ? `&type=${library.plex_search_type}` : '';
        // Try configured type first, then no-type as fallback
        const typeVariants = typeParam !== '' ? [typeParam, ''] : [''];

        let resultItem = null;

        for (const tp of typeVariants) {
            const sectionPath = `/library/sections/${encodeURIComponent(library.library_key)}/all`;

            // Strategy 1: sort=random — ask Plex for a random item directly (no totalSize needed)
            try {
                const { xml, baseUri } = await requestFromLibraryConnection(
                    library,
                    `${sectionPath}?sort=random&X-Plex-Container-Start=0&X-Plex-Container-Size=1${tp}`
                );
                const items = parseMediaItems(xml);
                if (items.length > 0) {
                    resultItem = { ...items[0], _baseUri: baseUri };
                    break;
                }
            } catch (_) {}

            // Strategy 2: count-based random (fallback for Plex versions that ignore sort=random)
            try {
                const { xml: cxml, headerTotal } = await requestFromLibraryConnection(
                    library,
                    `${sectionPath}?X-Plex-Container-Start=0&X-Plex-Container-Size=1${tp}`
                );
                const ca = parseMediaContainerAttributes(cxml);
                // Check all known attribute/header names for total count
                const rawTotal = headerTotal
                    || ca.totalSize || ca.total || ca.size || '0';
                const totalSize = Number.parseInt(String(rawTotal), 10);

                if (Number.isInteger(totalSize) && totalSize > 0) {
                    const randomIndex = Math.floor(Math.random() * totalSize);
                    const { xml: rxml, baseUri } = await requestFromLibraryConnection(
                        library,
                        `${sectionPath}?X-Plex-Container-Start=${randomIndex}&X-Plex-Container-Size=1${tp}`
                    );
                    const items = parseMediaItems(rxml);
                    if (items.length > 0) {
                        resultItem = { ...items[0], _baseUri: baseUri };
                        break;
                    }
                } else {
                    console.warn(
                        `[plex-service] getRandomAllowedItem ${library.library_title}${tp}: totalSize=${totalSize} ` +
                        `(header=${headerTotal}, xml=${String(cxml).slice(0, 200).replace(/\s+/g, ' ')})`
                    );
                }
            } catch (error) {
                console.warn(`[plex-service] getRandomAllowedItem ${library.library_title}: ${error.message}`);
            }
        }

        if (!resultItem) continue;

        const token = String(library.access_token || '').trim();
        const baseUri = resultItem._baseUri || '';
        const thumbnailUrl = resultItem.thumb && baseUri && token
            ? `${baseUri}${resultItem.thumb}?X-Plex-Token=${token}`
            : '';

        return {
            title:         resultItem.title,
            year:          resultItem.year,
            type:          resultItem.type || library.library_type,
            summary:       resultItem.summary || '',
            thumbnailUrl,
            library_title: library.library_title,
            server_name:   library.server_name,
        };
    }

    return null;
}

async function getPlexInfo(botId) {
    const context = await getPlexContext(botId);
    const byServer = new Map();

    for (const library of context.libraries) {
        const key = `${library.resource_identifier}`;
        if (!byServer.has(key)) {
            byServer.set(key, {
                server_name: library.server_name,
                product: library.product,
                product_version: library.product_version,
                platform: library.platform,
                platform_version: library.platform_version,
                device: library.device,
                presence: library.presence,
                owned: library.owned,
                libraries: 0
            });
        }

        byServer.get(key).libraries += 1;
    }

    return {
        servers: Array.from(byServer.values()),
        allowedLibraries: context.libraries.length
    };
}

async function getPlexStats(botId) {
    const context = await getPlexContext(botId);
    const totalsByType = {};
    const totalsByLibrary = [];

    for (const library of context.libraries) {
        let xml = '';
        let headerTotal = null;

        try {
            // Use Container-Size=1 — many Plex versions omit totalSize when Container-Size=0
            ({ xml, headerTotal } = await requestFromLibraryConnection(
                library,
                `/library/sections/${encodeURIComponent(library.library_key)}/all?X-Plex-Container-Start=0&X-Plex-Container-Size=1`
            ));
        } catch (error) {
            console.warn(`[plex-service] getPlexStats ${library.library_title}: ${error.message}`);
            continue;
        }

        const containerAttrs = parseMediaContainerAttributes(xml);
        const rawTotal = headerTotal || containerAttrs.totalSize || containerAttrs.total || '0';
        const totalSize = Number.parseInt(String(rawTotal), 10);
        const safeTotal = Number.isInteger(totalSize) && totalSize >= 0 ? totalSize : 0;

        const typeKey = String(library.library_type || 'unknown').trim().toLowerCase() || 'unknown';
        totalsByType[typeKey] = (totalsByType[typeKey] || 0) + safeTotal;

        totalsByLibrary.push({
            library_title: library.library_title,
            library_type: library.library_type,
            server_name: library.server_name,
            total: safeTotal
        });
    }

    return {
        totalsByType,
        totalsByLibrary
    };
}

// Extracts the first Part key (e.g. /library/parts/789/file.mp3) for a Track
// with the given ratingKey from raw XML. Used to build direct streaming URLs.
function extractFirstPartKey(xml, ratingKey) {
    try {
        const trackIdx = xml.indexOf(`ratingKey="${ratingKey}"`);
        if (trackIdx === -1) return null;
        // Search for a Part key attribute within the next 2000 characters
        const window = xml.slice(trackIdx, trackIdx + 2000);
        const partMatch = window.match(/key="(\/library\/parts\/[^"]+)"/);
        return partMatch ? partMatch[1] : null;
    } catch (_) {
        return null;
    }
}

// Search Plex for music playback — returns stream URLs for direct audio streaming.
// Music libraries (artist/album/music type) are searched with type=10 (tracks).
// Falls back to a type-less search if type=10 yields no results.
async function searchPlexForPlay(botId, query, limit = 1) {
    const context = await getPlexContext(botId);
    const search = String(query || '').trim();
    if (search === '') return [];

    const results = [];
    const seen = new Set();

    for (const library of context.libraries) {
        if (results.length >= limit) break;

        const token = String(library.access_token || '').trim();
        const isMusicLib = ['artist', 'album', 'music'].includes(library.library_type);

        // For music libraries try type=10 (tracks) first, then fall back to no-type.
        // For other libraries use the configured plex_search_type.
        const typeVariants = isMusicLib
            ? ['&type=10', '']
            : (library.plex_search_type != null ? [`&type=${library.plex_search_type}`] : ['']);

        let foundItems = [];
        let foundXml   = '';
        let foundBase  = '';

        for (const typeParam of typeVariants) {
            const endpoint = `/library/sections/${encodeURIComponent(library.library_key)}/search?query=${encodeURIComponent(search)}${typeParam}`;
            try {
                const { xml, baseUri } = await requestFromLibraryConnection(library, endpoint);
                const items = parseMediaItems(xml);
                if (items.length > 0) {
                    foundItems = items;
                    foundXml   = xml;
                    foundBase  = baseUri;
                    break;
                }
            } catch (_) {
                continue;
            }
        }

        for (const item of foundItems) {
            if (!item.ratingKey) continue;

            const uniqueKey = `${library.resource_identifier}:${item.ratingKey}`;
            if (seen.has(uniqueKey)) continue;
            seen.add(uniqueKey);

            // Prefer the direct Part file URL (no transcoding); fall back to download endpoint.
            const partKey   = extractFirstPartKey(foundXml, item.ratingKey);
            const streamUrl = partKey
                ? `${foundBase}${partKey}?X-Plex-Token=${token}`
                : `${foundBase}/library/metadata/${item.ratingKey}/download?X-Plex-Token=${token}`;

            const thumbnailUrl = item.thumb && foundBase && token
                ? `${foundBase}${item.thumb}?X-Plex-Token=${token}`
                : '';

            results.push({
                title: item.title,
                year: item.year,
                streamUrl,
                thumbnailUrl,
            });

            if (results.length >= limit) return results;
        }
    }

    return results;
}

// Returns recently added items across all allowed libraries (or a specific one).
// Uses /library/sections/{key}/recentlyAdded per library.
async function getRecentlyAdded(botId, libraryFilter = null, limit = 10) {
    const context = await getPlexContext(botId);
    if (context.libraries.length === 0) return [];

    let libraries = context.libraries;
    if (libraryFilter && typeof libraryFilter === 'string' && libraryFilter.trim() !== '') {
        const filter = libraryFilter.trim().toLowerCase();
        const filtered = libraries.filter(
            (lib) => lib.library_title.toLowerCase().includes(filter)
        );
        if (filtered.length > 0) libraries = filtered;
    }

    const results = [];
    const seen = new Set();

    for (const library of libraries) {
        if (results.length >= limit) break;

        const remaining = limit - results.length;
        const endpoint = `/library/sections/${encodeURIComponent(library.library_key)}/recentlyAdded`
            + `?X-Plex-Container-Start=0&X-Plex-Container-Size=${remaining}`;

        let xml = '';
        let baseUri = '';
        try {
            ({ xml, baseUri } = await requestFromLibraryConnection(library, endpoint));
        } catch (_) {
            continue;
        }

        const items = parseMediaItems(xml);
        const token = String(library.access_token || '').trim();

        for (const item of items) {
            const uniqueKey = `${library.resource_identifier}:${item.ratingKey || item.title}`;
            if (seen.has(uniqueKey)) continue;
            seen.add(uniqueKey);

            const thumbnailUrl = item.thumb && baseUri && token
                ? `${baseUri}${item.thumb}?X-Plex-Token=${token}`
                : '';

            results.push({
                title:         item.title,
                year:          item.year,
                type:          item.type || library.library_type,
                summary:       item.summary || '',
                thumbnailUrl,
                library_title: library.library_title,
                server_name:   library.server_name,
            });

            if (results.length >= limit) break;
        }
    }

    return results;
}

// Returns Plex "On Deck" items (continue watching) using /library/onDeck on each server.
// Only usable for libraries that belong to owned/accessible servers.
async function getOnDeck(botId, limit = 10) {
    const context = await getPlexContext(botId);
    if (context.libraries.length === 0) return [];

    const results = [];
    const seen = new Set();

    // Group libraries by server so we only hit /library/onDeck once per server
    const byServer = new Map();
    for (const lib of context.libraries) {
        const key = lib.resource_identifier;
        if (!byServer.has(key)) byServer.set(key, lib);
    }

    for (const library of byServer.values()) {
        if (results.length >= limit) break;

        const remaining = limit - results.length;
        const endpoint = `/library/onDeck?X-Plex-Container-Start=0&X-Plex-Container-Size=${remaining}`;

        let xml = '';
        let baseUri = '';
        try {
            ({ xml, baseUri } = await requestFromLibraryConnection(library, endpoint));
        } catch (_) {
            continue;
        }

        const items = parseMediaItems(xml);
        const token = String(library.access_token || '').trim();

        for (const item of items) {
            const uniqueKey = `${library.resource_identifier}:${item.ratingKey || item.title}`;
            if (seen.has(uniqueKey)) continue;
            seen.add(uniqueKey);

            const thumbnailUrl = item.thumb && baseUri && token
                ? `${baseUri}${item.thumb}?X-Plex-Token=${token}`
                : '';

            results.push({
                title:       item.title,
                year:        item.year,
                type:        item.type,
                summary:     item.summary || '',
                thumbnailUrl,
                server_name: library.server_name,
            });

            if (results.length >= limit) break;
        }
    }

    return results;
}

module.exports = {
    PLEX_COMMAND_KEYS,
    assertPlexCommandEnabled,
    getPlexContext,
    searchAllowedLibraries,
    searchPlexForPlay,
    getRandomAllowedItem,
    getPlexInfo,
    getPlexStats,
    getRecentlyAdded,
    getOnDeck
};
