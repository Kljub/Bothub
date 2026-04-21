// PFAD: /core/installer/src/services/economy-service.js

const { dbQuery } = require('../db');

// ── In-memory game states (ephemeral, keyed by `${botId}_${userId}`) ─────────
const blackjackGames = new Map();
const minesGames     = new Map();
const hangmanGames   = new Map();

const MINES_SIZE = 16; // 4×4 grid

// ── Command Enable Check + Settings ───────────────────────────────────────────
async function getCommandSetting(botId, commandKey, settingKey, defaultValue) {
    try {
        const rows = await dbQuery(
            'SELECT settings_json FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), String(commandKey)]
        );
        if (Array.isArray(rows) && rows[0]?.settings_json) {
            const data = typeof rows[0].settings_json === 'string'
                ? JSON.parse(rows[0].settings_json)
                : rows[0].settings_json;
            if (data[settingKey] !== undefined && data[settingKey] !== null) return data[settingKey];
        }
    } catch (_) {}
    return defaultValue;
}

async function isCommandEnabled(botId, commandKey) {
    try {
        const rows = await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
            [Number(botId), commandKey]
        );
        if (!Array.isArray(rows) || rows.length === 0) return false;
        return Number(rows[0].is_enabled) === 1;
    } catch (_) {
        return false;
    }
}

// ── Settings ──────────────────────────────────────────────────────────────────
const DEFAULT_SETTINGS = {
    currency_symbol:    '🪙',
    currency_name:      'Coins',
    daily_amount:       200,
    work_min:           50,
    work_max:           150,
    bank_interest_rate: 0,
};

async function getEcoSettings(botId, guildId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM eco_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
            [botId, guildId]
        );
        if (Array.isArray(rows) && rows.length > 0) {
            return { ...DEFAULT_SETTINGS, ...rows[0] };
        }
    } catch (_) { /* table may not exist yet */ }
    return { ...DEFAULT_SETTINGS };
}

// ── Wallet ────────────────────────────────────────────────────────────────────
async function getOrCreateWallet(botId, guildId, userId) {
    await dbQuery(
        `INSERT INTO eco_wallets (bot_id, guild_id, user_id, wallet, bank)
         VALUES (?, ?, ?, 0, 0)
         ON DUPLICATE KEY UPDATE id = id`,
        [botId, guildId, userId]
    );
    const rows = await dbQuery(
        'SELECT wallet, bank FROM eco_wallets WHERE bot_id = ? AND guild_id = ? AND user_id = ? LIMIT 1',
        [botId, guildId, userId]
    );
    return {
        wallet: Number(rows[0].wallet || 0),
        bank:   Number(rows[0].bank   || 0),
    };
}

async function adjustWallet(botId, guildId, userId, walletDelta, bankDelta = 0) {
    await getOrCreateWallet(botId, guildId, userId);
    await dbQuery(
        `UPDATE eco_wallets
         SET wallet = wallet + ?, bank = bank + ?, updated_at = NOW()
         WHERE bot_id = ? AND guild_id = ? AND user_id = ?`,
        [walletDelta, bankDelta, botId, guildId, userId]
    );
}

// ── Cooldown ──────────────────────────────────────────────────────────────────
async function getCooldownExpiry(botId, guildId, userId, action) {
    const rows = await dbQuery(
        'SELECT expires_at FROM eco_cooldowns WHERE bot_id = ? AND guild_id = ? AND user_id = ? AND action = ? LIMIT 1',
        [botId, guildId, userId, action]
    );
    if (!Array.isArray(rows) || rows.length === 0) return null;
    const expiry = new Date(rows[0].expires_at);
    return expiry > new Date() ? expiry : null;
}

async function setCooldown(botId, guildId, userId, action, seconds) {
    const expiresStr = new Date(Date.now() + seconds * 1000)
        .toISOString().slice(0, 19).replace('T', ' ');
    await dbQuery(
        `INSERT INTO eco_cooldowns (bot_id, guild_id, user_id, action, expires_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)`,
        [botId, guildId, userId, action, expiresStr]
    );
}

// ── Leaderboard ───────────────────────────────────────────────────────────────
async function getLeaderboard(botId, guildId, limit = 10) {
    const rows = await dbQuery(
        `SELECT user_id, wallet, bank, (wallet + bank) AS total
         FROM eco_wallets
         WHERE bot_id = ? AND guild_id = ?
         ORDER BY total DESC
         LIMIT ?`,
        [botId, guildId, limit]
    );
    return Array.isArray(rows) ? rows : [];
}

// ── Shop ──────────────────────────────────────────────────────────────────────
async function getShopItems(botId, guildId) {
    const rows = await dbQuery(
        'SELECT * FROM eco_shop_items WHERE bot_id = ? AND guild_id = ? AND is_active = 1 ORDER BY price ASC',
        [botId, guildId]
    );
    return Array.isArray(rows) ? rows : [];
}

async function getShopItem(botId, guildId, itemId) {
    const rows = await dbQuery(
        'SELECT * FROM eco_shop_items WHERE id = ? AND bot_id = ? AND guild_id = ? AND is_active = 1 LIMIT 1',
        [itemId, botId, guildId]
    );
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
}

async function buyItem(botId, guildId, userId, itemId) {
    const item = await getShopItem(botId, guildId, itemId);
    if (!item) throw new Error('Item nicht gefunden.');

    const wallet = await getOrCreateWallet(botId, guildId, userId);
    if (wallet.wallet < Number(item.price)) {
        throw new Error(`Nicht genug Geld. Du brauchst ${item.price}, hast aber nur ${wallet.wallet}.`);
    }

    const stock = Number(item.stock);
    if (stock === 0) throw new Error('Dieses Item ist ausverkauft.');
    if (stock > 0) {
        await dbQuery('UPDATE eco_shop_items SET stock = stock - 1 WHERE id = ? AND stock > 0', [itemId]);
    }

    await adjustWallet(botId, guildId, userId, -Number(item.price));
    await dbQuery(
        `INSERT INTO eco_inventory (bot_id, guild_id, user_id, item_id, quantity)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE quantity = quantity + 1`,
        [botId, guildId, userId, itemId]
    );
    return item;
}

async function getInventory(botId, guildId, userId) {
    const rows = await dbQuery(
        `SELECT i.item_id, i.quantity, s.name, s.description, s.emoji
         FROM eco_inventory i
         JOIN eco_shop_items s ON s.id = i.item_id
         WHERE i.bot_id = ? AND i.guild_id = ? AND i.user_id = ?
         ORDER BY s.name ASC`,
        [botId, guildId, userId]
    );
    return Array.isArray(rows) ? rows : [];
}

// ── Jobs ──────────────────────────────────────────────────────────────────────
async function getJobs(botId, guildId) {
    const rows = await dbQuery(
        'SELECT * FROM eco_jobs WHERE bot_id = ? AND guild_id = ? AND is_active = 1 ORDER BY name ASC',
        [botId, guildId]
    );
    return Array.isArray(rows) ? rows : [];
}

async function getUserJob(botId, guildId, userId) {
    const rows = await dbQuery(
        `SELECT uj.job_id, uj.assigned_at, uj.last_worked_at,
                j.name, j.description, j.min_wage, j.max_wage, j.cooldown_seconds, j.emoji
         FROM eco_user_jobs uj
         JOIN eco_jobs j ON j.id = uj.job_id
         WHERE uj.bot_id = ? AND uj.guild_id = ? AND uj.user_id = ?
         LIMIT 1`,
        [botId, guildId, userId]
    );
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
}

async function assignJob(botId, guildId, userId, jobId) {
    const jobs = await dbQuery(
        'SELECT id FROM eco_jobs WHERE id = ? AND bot_id = ? AND guild_id = ? AND is_active = 1 LIMIT 1',
        [jobId, botId, guildId]
    );
    if (!Array.isArray(jobs) || jobs.length === 0) throw new Error('Job nicht gefunden.');
    await dbQuery(
        `INSERT INTO eco_user_jobs (bot_id, guild_id, user_id, job_id, assigned_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE job_id = VALUES(job_id), assigned_at = NOW(), last_worked_at = NULL`,
        [botId, guildId, userId, jobId]
    );
}

// ── Formatting ────────────────────────────────────────────────────────────────
function formatMoney(amount, settings) {
    const sym = String(settings?.currency_symbol || '🪙');
    return `${Number(amount).toLocaleString('de-DE')} ${sym}`;
}

function formatRemaining(expiry) {
    const diff = Math.max(0, Math.ceil((expiry.getTime() - Date.now()) / 1000));
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
}

function randInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

// ── Fishing ───────────────────────────────────────────────────────────────────
const FISH_TABLE = [
    { name: 'Altes Boot',        emoji: '🥾', value: 0,   weight: 15 },
    { name: 'Kleiner Fisch',     emoji: '🐟', value: 15,  weight: 35 },
    { name: 'Lachs',             emoji: '🐠', value: 40,  weight: 25 },
    { name: 'Kugelfisch',        emoji: '🐡', value: 80,  weight: 15 },
    { name: 'Thunfisch',         emoji: '🦈', value: 150, weight: 7  },
    { name: 'Goldfisch',         emoji: '🌟', value: 400, weight: 2.5},
    { name: 'Legendärer Fisch',  emoji: '✨', value: 900, weight: 0.5},
];

function randomFish() {
    const total = FISH_TABLE.reduce((s, f) => s + f.weight, 0);
    let r = Math.random() * total;
    for (const fish of FISH_TABLE) {
        r -= fish.weight;
        if (r <= 0) return fish;
    }
    return FISH_TABLE[0];
}

// ── Blackjack ─────────────────────────────────────────────────────────────────
function createDeck() {
    const suits = ['♠', '♥', '♦', '♣'];
    const ranks = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    const deck  = [];
    for (const suit of suits) {
        for (const rank of ranks) deck.push({ rank, suit });
    }
    for (let i = deck.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [deck[i], deck[j]] = [deck[j], deck[i]];
    }
    return deck;
}

function cardValue(card) {
    if (card.rank === 'A')                    return 11;
    if (['J', 'Q', 'K'].includes(card.rank)) return 10;
    return Number(card.rank);
}

function handValue(hand) {
    let total = 0;
    let aces  = 0;
    for (const card of hand) {
        total += cardValue(card);
        if (card.rank === 'A') aces++;
    }
    while (total > 21 && aces > 0) { total -= 10; aces--; }
    return total;
}

function formatHand(hand, hideSecond = false) {
    return hand.map((c, i) => (hideSecond && i === 1) ? '🂠' : `\`${c.rank}${c.suit}\``).join(' ');
}

function startBlackjack(gameKey, bet) {
    const deck       = createDeck();
    const playerHand = [deck.pop(), deck.pop()];
    const dealerHand = [deck.pop(), deck.pop()];
    const state = { deck, playerHand, dealerHand, bet, ts: Date.now() };
    blackjackGames.set(gameKey, state);
    return state;
}

function getBlackjackGame(gameKey)  { return blackjackGames.get(gameKey) || null; }
function endBlackjack(gameKey)      { blackjackGames.delete(gameKey); }

// ── Mines ─────────────────────────────────────────────────────────────────────
function startMines(gameKey, bet, mineCount = 4) {
    const grid     = new Array(MINES_SIZE).fill(false);
    const placed   = new Set();
    while (placed.size < mineCount) placed.add(Math.floor(Math.random() * MINES_SIZE));
    for (const pos of placed) grid[pos] = true;

    const state = {
        grid,
        revealed:     new Array(MINES_SIZE).fill(false),
        bet,
        mineCount,
        safeRevealed: 0,
        ts:           Date.now(),
    };
    minesGames.set(gameKey, state);
    return state;
}

function getMinesGame(gameKey)  { return minesGames.get(gameKey) || null; }
function endMines(gameKey)      { minesGames.delete(gameKey); }

function calcMinesMultiplier(safeRevealed, mineCount = 4) {
    const safeTiles = MINES_SIZE - mineCount;
    // base multiplier scales with risk: 1 mine → 0.25x, 8 mines → 5.0x
    const baseMult  = parseFloat((0.25 + (mineCount - 1) * (4.75 / 7)).toFixed(2));
    if (safeRevealed === 0) return baseMult;
    // grows linearly toward 10x when all safe tiles are revealed
    const grow = (10 - baseMult) / Math.max(1, safeTiles);
    return Math.min(10, parseFloat((baseMult + safeRevealed * grow).toFixed(2)));
}

// ── Crash ──────────────────────────────────────────────────────────────────────
const crashGames = new Map();

function startCrash(gameKey, bet, crashAt) {
    const state = { bet, crashAt, tick: 0, multiplier: 1.0, stopped: false, intervalId: null, ts: Date.now() };
    crashGames.set(gameKey, state);
    return state;
}

function getCrashGame(gameKey)  { return crashGames.get(gameKey) || null; }
function endCrash(gameKey)      { crashGames.delete(gameKey); }

// ── Hangman ────────────────────────────────────────────────────────────────────
const HANGMAN_DEFAULT_WORDS = [
    'APFEL', 'HAUS', 'BAUM', 'HUND', 'KATZE', 'COMPUTER', 'MUSIK', 'BUCH',
    'WASSER', 'FEUER', 'SCHULE', 'AUTO', 'BLUME', 'VOGEL', 'FISCH',
    'WINTER', 'SOMMER', 'TISCH', 'STUHL', 'FENSTER', 'TELEFON', 'GARTEN',
    'BRÜCKE', 'SCHLOSS', 'DRACHE', 'TURM', 'RITTER', 'SCHATZ', 'STERN', 'MOND',
];

const HANGMAN_STAGES = [
    '```\n  +---+\n  |   |\n      |\n      |\n      |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n      |\n      |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n  |   |\n      |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n /|   |\n      |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n /|\\  |\n      |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n /|\\  |\n /    |\n      |\n=========```',
    '```\n  +---+\n  |   |\n  O   |\n /|\\  |\n / \\  |\n      |\n=========```',
];

async function getHangmanWord(botId) {
    try {
        const rows = await dbQuery(
            'SELECT word FROM eco_hangman_words WHERE bot_id = ? ORDER BY RAND() LIMIT 1',
            [Number(botId)]
        );
        if (Array.isArray(rows) && rows.length > 0) {
            return String(rows[0].word).toUpperCase();
        }
    } catch (_) {}
    return HANGMAN_DEFAULT_WORDS[Math.floor(Math.random() * HANGMAN_DEFAULT_WORDS.length)];
}

function startHangman(gameKey, word, bet) {
    const state = { word, guessed: new Set(), wrongGuesses: 0, maxWrong: 6, bet, ts: Date.now() };
    hangmanGames.set(gameKey, state);
    return state;
}

function getHangmanGame(gameKey) { return hangmanGames.get(gameKey) || null; }
function endHangman(gameKey)     { hangmanGames.delete(gameKey); }

function hangmanDisplay(state) {
    const visual      = HANGMAN_STAGES[Math.min(state.wrongGuesses, 6)];
    const maskedWord  = state.word.split('').map(c => state.guessed.has(c) ? c : '_').join(' ');
    const wrongLetters = [...state.guessed].filter(c => !state.word.includes(c));
    return { visual, maskedWord, wrongLetters };
}

module.exports = {
    // enable check + settings
    isCommandEnabled,
    getCommandSetting,
    // settings
    getEcoSettings,
    DEFAULT_SETTINGS,
    // wallet
    getOrCreateWallet,
    adjustWallet,
    // cooldown
    getCooldownExpiry,
    setCooldown,
    // leaderboard
    getLeaderboard,
    // shop
    getShopItems,
    getShopItem,
    buyItem,
    getInventory,
    // jobs
    getJobs,
    getUserJob,
    assignJob,
    // formatting
    formatMoney,
    formatRemaining,
    randInt,
    // fishing
    randomFish,
    FISH_TABLE,
    // blackjack
    startBlackjack,
    getBlackjackGame,
    endBlackjack,
    handValue,
    formatHand,
    // mines
    startMines,
    getMinesGame,
    endMines,
    calcMinesMultiplier,
    MINES_SIZE,
    // hangman
    getHangmanWord,
    startHangman,
    getHangmanGame,
    endHangman,
    hangmanDisplay,
    // crash
    startCrash,
    getCrashGame,
    endCrash,
};
