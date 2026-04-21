// PFAD: /core/installer/src/services/pokemia-service.js
const { dbQuery }     = require('../db');
const { getSpecies }  = require('../data/pokemia-species');
const { randomNature } = require('../data/pokemia-moves');

// ── User management ────────────────────────────────────────────────────────────
async function getOrCreatePkmUser(botId, userId) {
    await dbQuery(
        `INSERT INTO pokemia_users (bot_id, user_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE id = id`,
        [botId, userId]
    );
    const rows = await dbQuery(
        'SELECT * FROM pokemia_users WHERE bot_id = ? AND user_id = ? LIMIT 1',
        [botId, userId]
    );
    return rows[0] || null;
}

async function setSelectedPokemon(botId, userId, pokemonId) {
    await dbQuery(
        'UPDATE pokemia_users SET selected_id = ? WHERE bot_id = ? AND user_id = ?',
        [pokemonId, botId, userId]
    );
}

async function getSelectedPokemon(botId, userId) {
    const user = await getOrCreatePkmUser(botId, userId);
    if (!user?.selected_id) return null;
    const rows = await dbQuery(
        'SELECT * FROM pokemia_pokemon WHERE id = ? AND bot_id = ? LIMIT 1',
        [user.selected_id, botId]
    );
    return rows[0] || null;
}

// ── Pokemon CRUD ───────────────────────────────────────────────────────────────
async function catchPokemon(botId, userId, speciesId, options = {}) {
    const species = getSpecies(speciesId);
    if (!species) throw new Error(`Unknown species ${speciesId}`);

    const nature = options.nature || randomNature();
    const shiny  = options.shiny ?? (Math.random() < 0.005 ? 1 : 0);
    const gender = Math.random() < 0.5 ? 'female' : 'male';
    const level  = options.level || 5;

    const ivs = {
        hp:    Math.floor(Math.random() * 32),
        atk:   Math.floor(Math.random() * 32),
        def:   Math.floor(Math.random() * 32),
        spatk: Math.floor(Math.random() * 32),
        spdef: Math.floor(Math.random() * 32),
        spd:   Math.floor(Math.random() * 32),
    };

    const learnedMoves = (species.learnset || [])
        .filter(([lv]) => lv <= level)
        .map(([, id]) => id);
    const moves = [...new Set(learnedMoves)].slice(-4);

    const result = await dbQuery(
        `INSERT INTO pokemia_pokemon
         (bot_id, owner_id, species_id, level, xp, nature, shiny, gender,
          iv_hp, iv_atk, iv_def, iv_spatk, iv_spdef, iv_spd, moves_json)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [botId, userId, speciesId, level, nature, shiny, gender,
         ivs.hp, ivs.atk, ivs.def, ivs.spatk, ivs.spdef, ivs.spd,
         JSON.stringify(moves)]
    );
    const newId = result.insertId;

    // Auto-select if this is the first pokemon
    const user = await getOrCreatePkmUser(botId, userId);
    if (!user.selected_id) await setSelectedPokemon(botId, userId, newId);

    const rows = await dbQuery('SELECT * FROM pokemia_pokemon WHERE id = ? LIMIT 1', [newId]);
    return rows[0] || null;
}

async function getUserPokemon(botId, userId, limit = 20, offset = 0) {
    const rows = await dbQuery(
        `SELECT * FROM pokemia_pokemon
         WHERE bot_id = ? AND owner_id = ?
         ORDER BY caught_at ASC
         LIMIT ? OFFSET ?`,
        [botId, userId, limit, offset]
    );
    return Array.isArray(rows) ? rows : [];
}

async function getUserPokemonCount(botId, userId) {
    const rows = await dbQuery(
        'SELECT COUNT(*) AS cnt FROM pokemia_pokemon WHERE bot_id = ? AND owner_id = ?',
        [botId, userId]
    );
    return Number(rows[0]?.cnt || 0);
}

async function getPokemonByIndex(botId, userId, index) {
    // index is 1-based
    const rows = await dbQuery(
        `SELECT * FROM pokemia_pokemon
         WHERE bot_id = ? AND owner_id = ?
         ORDER BY caught_at ASC
         LIMIT 1 OFFSET ?`,
        [botId, userId, index - 1]
    );
    return rows[0] || null;
}

// ── XP & Leveling ──────────────────────────────────────────────────────────────
function xpToNextLevel(level) {
    return Math.floor(Math.pow(level, 3) / 5) + 10;
}

async function addXp(botId, pokemonRow, xpGain) {
    let { level, xp, id, species_id } = pokemonRow;
    xp += xpGain;

    const messages = [];
    const species  = getSpecies(species_id);
    let   evolved  = null;
    let   currentMoves = typeof pokemonRow.moves_json === 'string'
        ? JSON.parse(pokemonRow.moves_json) : (pokemonRow.moves_json || []);

    while (xp >= xpToNextLevel(level) && level < 100) {
        xp -= xpToNextLevel(level);
        level++;
        messages.push(`Level ${level}!`);

        if (species) {
            const newMoves = (species.learnset || [])
                .filter(([lv]) => lv === level)
                .map(([, moveId]) => moveId);
            if (newMoves.length > 0) {
                currentMoves = [...new Set([...currentMoves, ...newMoves])].slice(-4);
                await dbQuery(
                    'UPDATE pokemia_pokemon SET moves_json = ? WHERE id = ?',
                    [JSON.stringify(currentMoves), id]
                );
            }
        }
    }

    await dbQuery('UPDATE pokemia_pokemon SET level = ?, xp = ? WHERE id = ?', [level, xp, id]);

    // Check evolution
    if (species?.evo && level >= species.evo.lv && !evolved) {
        await dbQuery('UPDATE pokemia_pokemon SET species_id = ? WHERE id = ?', [species.evo.into, id]);
        const newSpecies = getSpecies(species.evo.into);
        messages.push(`✨ Entwicklung zu **${newSpecies?.name || '???'}**!`);
        evolved = species.evo.into;
    }

    return { level, xp, messages, evolved };
}

// ── Spawn System ───────────────────────────────────────────────────────────────
async function getGuildConfig(botId, guildId) {
    const rows = await dbQuery(
        'SELECT * FROM pokemia_guild_config WHERE bot_id = ? AND guild_id = ? LIMIT 1',
        [botId, guildId]
    );
    return rows[0] || null;
}

async function getActiveSpawn(botId, guildId) {
    const rows = await dbQuery(
        `SELECT * FROM pokemia_spawn
         WHERE bot_id = ? AND guild_id = ? AND caught = 0
         ORDER BY spawned_at DESC LIMIT 1`,
        [botId, guildId]
    );
    return rows[0] || null;
}

async function createSpawn(botId, guildId, channelId, speciesId) {
    const result = await dbQuery(
        `INSERT INTO pokemia_spawn (bot_id, guild_id, channel_id, species_id) VALUES (?, ?, ?, ?)`,
        [botId, guildId, channelId, speciesId]
    );
    return result.insertId;
}

async function markSpawnCaught(spawnId) {
    await dbQuery('UPDATE pokemia_spawn SET caught = 1 WHERE id = ?', [spawnId]);
}

// ── Training ───────────────────────────────────────────────────────────────────
// XP gained = duration_minutes * (1 + level * 0.15), rounded
function calcTrainingXp(durationMinutes, level) {
    return Math.round(durationMinutes * (1 + level * 0.15));
}

async function startTraining(botId, pokemonId, durationMinutes, xpGain) {
    const until = new Date(Date.now() + durationMinutes * 60 * 1000)
        .toISOString().slice(0, 19).replace('T', ' ');
    await dbQuery(
        'UPDATE pokemia_pokemon SET training_until = ?, training_xp = ? WHERE id = ? AND bot_id = ?',
        [until, xpGain, pokemonId, botId]
    );
}

// Returns true if the pokemon is currently in training (not yet finished)
function isTraining(pokemonRow) {
    if (!pokemonRow?.training_until) return false;
    return new Date(pokemonRow.training_until) > new Date();
}

// Milliseconds remaining in training (0 if not training or done)
function trainingMsLeft(pokemonRow) {
    if (!pokemonRow?.training_until) return 0;
    return Math.max(0, new Date(pokemonRow.training_until) - Date.now());
}

// Format ms into a human-readable string e.g. "1 Std. 23 Min."
function formatTrainingTime(ms) {
    const totalSecs = Math.ceil(ms / 1000);
    const h = Math.floor(totalSecs / 3600);
    const m = Math.ceil((totalSecs % 3600) / 60);
    if (h > 0 && m > 0) return `${h} Std. ${m} Min.`;
    if (h > 0)           return `${h} Std.`;
    return `${m} Min.`;
}

// Call before any command that needs the pokemon — finishes training and grants XP if done
async function checkAndFinishTraining(botId, pokemonRow) {
    if (!pokemonRow?.training_until) return null;
    if (new Date(pokemonRow.training_until) > new Date()) return null;

    // Training finished — apply XP and clear columns
    const xpGain = Number(pokemonRow.training_xp || 0);
    await dbQuery(
        'UPDATE pokemia_pokemon SET training_until = NULL, training_xp = 0 WHERE id = ?',
        [pokemonRow.id]
    );

    if (xpGain <= 0) return { messages: ['🏋️ Training abgeschlossen!'], level: pokemonRow.level, xp: pokemonRow.xp, evolved: null };

    // Re-fetch fresh row for accurate level/xp before adding
    const fresh = await dbQuery('SELECT * FROM pokemia_pokemon WHERE id = ? LIMIT 1', [pokemonRow.id]);
    if (!fresh[0]) return null;
    return await addXp(botId, fresh[0], xpGain);
}

module.exports = {
    getOrCreatePkmUser, setSelectedPokemon, getSelectedPokemon,
    catchPokemon, getUserPokemon, getUserPokemonCount, getPokemonByIndex,
    xpToNextLevel, addXp,
    getGuildConfig, getActiveSpawn, createSpawn, markSpawnCaught,
    calcTrainingXp, startTraining, isTraining, trainingMsLeft, formatTrainingTime,
    checkAndFinishTraining,
};
