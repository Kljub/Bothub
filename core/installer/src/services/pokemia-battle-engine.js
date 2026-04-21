// PFAD: /core/installer/src/services/pokemia-battle-engine.js
// Battle state management, damage calculation, AI for Pokemia.

const { getSpecies } = require('../data/pokemia-species');
const { getMove, getEffectiveness, NATURES } = require('../data/pokemia-moves');

// ── In-memory battle state ─────────────────────────────────────────────────────
const battles = new Map();   // battleKey → BattleState
const challenges = new Map(); // challengeKey → ChallengeState

// ── Stat calculation ───────────────────────────────────────────────────────────
function calcMaxHp(base, iv, level) {
    return Math.floor((2 * base + iv + 5) * level / 100) + level + 10;
}

function calcStat(base, iv, level, nature = 1) {
    return Math.floor((Math.floor((2 * base + iv + 5) * level / 100) + 5) * nature);
}

const STAGE_MULT = [0.25, 0.29, 0.33, 0.4, 0.5, 0.67, 1, 1.5, 2, 2.5, 3, 3.5, 4];

function stageMultiplier(stage) {
    return STAGE_MULT[Math.max(0, Math.min(12, stage + 6))];
}

// Build a battle-ready Pokemon object from a DB row + species data
function buildBattlePokemon(dbRow) {
    const species = getSpecies(dbRow.species_id);
    if (!species) throw new Error(`Unknown species ${dbRow.species_id}`);

    const nat    = NATURES[dbRow.nature] || NATURES.Hardy;
    const level  = dbRow.level || 5;
    const moves  = (typeof dbRow.moves_json === 'string'
        ? JSON.parse(dbRow.moves_json)
        : dbRow.moves_json) || [];

    const maxHp  = calcMaxHp(species.hp, dbRow.iv_hp || 0, level);

    return {
        id:        dbRow.id,
        speciesId: species.id,
        name:      dbRow.nickname || species.name,
        species:   species,
        level,
        nature:    dbRow.nature || 'Hardy',
        shiny:     !!dbRow.shiny,
        gender:    dbRow.gender || 'male',
        maxHp,
        hp:        maxHp,
        stats: {
            atk:   Math.floor(calcStat(species.atk,  dbRow.iv_atk  || 0, level) * nat.atk),
            def:   Math.floor(calcStat(species.def,  dbRow.iv_def  || 0, level) * nat.def),
            satk:  Math.floor(calcStat(species.sa,   dbRow.iv_spatk|| 0, level) * nat.satk),
            spdef: Math.floor(calcStat(species.sd,   dbRow.iv_spdef|| 0, level) * nat.spdef),
            spd:   Math.floor(calcStat(species.spd,  dbRow.iv_spd  || 0, level) * nat.spd),
        },
        stages:  { atk:0, def:0, satk:0, spdef:0, spd:0, accuracy:0, evasion:0 },
        ailments: new Set(),
        moves:   moves.map(id => ({ id, pp: 10 })).filter(m => getMove(m.id)),
    };
}

// ── Damage formula ─────────────────────────────────────────────────────────────
function calcDamage(attacker, defender, moveData) {
    if (!moveData || moveData.power === 0 || moveData.cat === 'status') return 0;

    const isPhysical = moveData.cat === 'physical';
    const atkStat    = isPhysical ? attacker.stats.atk  : attacker.stats.satk;
    const defStat    = isPhysical ? defender.stats.def  : defender.stats.spdef;

    const atkMult    = stageMultiplier(isPhysical ? attacker.stages.atk  : attacker.stages.satk);
    const defMult    = stageMultiplier(isPhysical ? defender.stages.def  : defender.stages.spdef);

    const A = Math.floor(atkStat * atkMult);
    const D = Math.max(1, Math.floor(defStat * defMult));

    const level  = attacker.level;
    let   power  = moveData.power;

    const stab   = (moveData.type === attacker.species.t1 || moveData.type === attacker.species.t2) ? 1.5 : 1;
    const eff    = getEffectiveness(moveData.type, defender.species.t1, defender.species.t2);
    const random = 0.85 + Math.random() * 0.15;  // 0.85–1.0

    // Paralysis halves speed (already handled in priority), Burn halves physical attack
    const burnMult = (moveData.cat === 'physical' && attacker.ailments.has('burn')) ? 0.5 : 1;

    const base = Math.floor(Math.floor((2 * level / 5 + 2) * power * A / D) / 50 + 2);
    return Math.max(1, Math.floor(base * stab * eff * random * burnMult));
}

function effectivenessText(eff) {
    if (eff === 0)   return "Es hat keine Wirkung!";
    if (eff < 1)     return "Es ist nicht sehr effektiv...";
    if (eff > 1)     return "**Es ist sehr effektiv!**";
    return null;
}

// ── Battle action: execute one turn ───────────────────────────────────────────
function executeTurn(battle, action0, action1) {
    // Sort by priority → speed
    const getEffSpd = (trainer) => {
        const p = trainer.pokemon;
        const spd = Math.floor(p.stats.spd * stageMultiplier(p.stages.spd));
        return p.ailments.has('paralysis') ? Math.floor(spd * 0.5) : spd;
    };

    const acts = [
        { action: action0, trainer: battle.trainers[0], opponent: battle.trainers[1] },
        { action: action1, trainer: battle.trainers[1], opponent: battle.trainers[0] },
    ].sort((a, b) => {
        const pa = a.action.priority ?? 0;
        const pb = b.action.priority ?? 0;
        if (pa !== pb) return pb - pa;
        return getEffSpd(b.trainer) - getEffSpd(a.trainer);
    });

    const log = [];

    for (const { action, trainer, opponent } of acts) {
        if (trainer.pokemon.hp <= 0) continue; // already fainted

        if (action.type === 'flee') {
            log.push({ type: 'flee', user: trainer.userId });
            battle.winner = opponent.userId;
            battle.ended  = true;
            return log;
        }

        if (action.type === 'move') {
            const move = getMove(action.moveId);
            if (!move) continue;

            const entry = { type: 'move', user: trainer.userId, moveName: move.name, messages: [] };

            // Accuracy check
            const accStage  = trainer.pokemon.stages.accuracy;
            const evaStage  = opponent.pokemon.stages.evasion;
            const accMult   = stageMultiplier(accStage) / stageMultiplier(evaStage);
            const hitChance = Math.min(100, (move.acc || 100) * accMult);

            if (Math.random() * 100 > hitChance) {
                entry.missed = true;
                log.push(entry);
                continue;
            }

            // Frozen can't move
            if (trainer.pokemon.ailments.has('freeze') || trainer.pokemon.ailments.has('sleep')) {
                if (trainer.pokemon.ailments.has('freeze') && Math.random() < 0.2) {
                    trainer.pokemon.ailments.delete('freeze');
                    entry.messages.push(`${trainer.pokemon.name} ist aufgetaut!`);
                } else if (trainer.pokemon.ailments.has('sleep') && Math.random() < 0.33) {
                    trainer.pokemon.ailments.delete('sleep');
                    entry.messages.push(`${trainer.pokemon.name} ist aufgewacht!`);
                } else {
                    entry.blocked = trainer.pokemon.ailments.has('freeze') ? 'freeze' : 'sleep';
                    log.push(entry);
                    continue;
                }
            }

            // Paralysis 25% skip
            if (trainer.pokemon.ailments.has('paralysis') && Math.random() < 0.25) {
                entry.blocked = 'paralysis';
                log.push(entry);
                continue;
            }

            // Damage
            if (move.cat !== 'status') {
                const dmg  = calcDamage(trainer.pokemon, opponent.pokemon, move);
                const eff  = getEffectiveness(move.type, opponent.pokemon.species.t1, opponent.pokemon.species.t2);
                opponent.pokemon.hp = Math.max(0, opponent.pokemon.hp - dmg);
                entry.damage  = dmg;
                entry.eff     = eff;
                entry.effText = effectivenessText(eff);

                // Drain / recoil
                if (move.effect?.drain) {
                    const heal = Math.floor(dmg * move.effect.drain);
                    trainer.pokemon.hp = Math.min(trainer.pokemon.maxHp, trainer.pokemon.hp + heal);
                    entry.heal = heal;
                }
                if (move.effect?.recoil) {
                    const recoilDmg = Math.floor(dmg * move.effect.recoil);
                    trainer.pokemon.hp = Math.max(0, trainer.pokemon.hp - recoilDmg);
                    entry.recoil = recoilDmg;
                }
            }

            // Status effects
            if (move.effect?.ailment) {
                const chance = move.effect.chance ?? 100;
                if (Math.random() * 100 <= chance && !opponent.pokemon.ailments.has(move.effect.ailment)) {
                    // Type immunities
                    const imm = { poison: ['poison','steel'], freeze: ['ice'], burn: ['fire'], paralysis: ['electric'] };
                    const oppTypes = [opponent.pokemon.species.t1, opponent.pokemon.species.t2].filter(Boolean);
                    const blocked  = (imm[move.effect.ailment] || []).some(t => oppTypes.includes(t));
                    if (!blocked) {
                        opponent.pokemon.ailments.add(move.effect.ailment);
                        entry.ailment = move.effect.ailment;
                    }
                }
            }

            // Stat changes
            if (move.effect?.stat) {
                const sc = move.effect.stat;
                const tgt = sc.target === 'self' ? trainer.pokemon : opponent.pokemon;
                tgt.stages[sc.stat] = Math.max(-6, Math.min(6, (tgt.stages[sc.stat] || 0) + sc.change));
                entry.statChange = sc;
            }

            // Healing move
            if (move.effect?.heal) {
                const healAmt = Math.floor(trainer.pokemon.maxHp * move.effect.heal);
                trainer.pokemon.hp = Math.min(trainer.pokemon.maxHp, trainer.pokemon.hp + healAmt);
                entry.selfHeal = healAmt;
            }

            log.push(entry);

            // Check faint
            if (opponent.pokemon.hp <= 0) {
                log.push({ type: 'faint', pokemon: opponent.pokemon.name, userId: opponent.userId });
                break;
            }
        }
    }

    // End-of-turn ailment damage
    for (const { trainer } of acts) {
        const p = trainer.pokemon;
        if (p.hp <= 0) continue;
        if (p.ailments.has('burn')) {
            const dmg = Math.max(1, Math.floor(p.maxHp / 16));
            p.hp = Math.max(0, p.hp - dmg);
            log.push({ type: 'ailment_dmg', ailment: 'burn', pokemon: p.name, damage: dmg });
        }
        if (p.ailments.has('poison')) {
            const dmg = Math.max(1, Math.floor(p.maxHp / 8));
            p.hp = Math.max(0, p.hp - dmg);
            log.push({ type: 'ailment_dmg', ailment: 'poison', pokemon: p.name, damage: dmg });
        }
    }

    // Check winner
    if (battle.trainers[0].pokemon.hp <= 0) {
        battle.winner = battle.trainers[1].userId;
        battle.ended  = true;
    } else if (battle.trainers[1].pokemon.hp <= 0) {
        battle.winner = battle.trainers[0].userId;
        battle.ended  = true;
    }

    return log;
}

// ── AI move selection ──────────────────────────────────────────────────────────
function aiPickMove(attacker, defender, difficulty) {
    const moves = attacker.moves.filter(m => m.pp > 0 && getMove(m.id));
    if (moves.length === 0) return { type: 'move', moveId: null, priority: 0 }; // struggle

    if (difficulty <= 2) {
        // Random
        const m = moves[Math.floor(Math.random() * moves.length)];
        return { type: 'move', moveId: m.id, priority: getMove(m.id)?.pri ?? 0 };
    }

    if (difficulty <= 5) {
        // Highest base power
        const best = moves.reduce((a, b) => {
            const pa = getMove(a.id)?.power ?? 0;
            const pb = getMove(b.id)?.power ?? 0;
            return pb > pa ? b : a;
        });
        return { type: 'move', moveId: best.id, priority: getMove(best.id)?.pri ?? 0 };
    }

    // Difficulty 6+: type-effectiveness aware
    let bestScore = -Infinity;
    let bestMove  = moves[0];

    for (const m of moves) {
        const md = getMove(m.id);
        if (!md) continue;
        const eff   = getEffectiveness(md.type, defender.species.t1, defender.species.t2);
        const stab  = (md.type === attacker.species.t1 || md.type === attacker.species.t2) ? 1.5 : 1;
        let   score = (md.power || 0) * eff * stab;

        // Level 9-10: prefer finishing move if opponent low HP
        if (difficulty >= 9) {
            const estimatedDmg = calcDamage(attacker, defender, md);
            if (estimatedDmg >= defender.hp) score += 10000;
        }

        // Level 8+: avoid immune / never-effective moves
        if (difficulty >= 8 && eff === 0) score = -9999;

        if (score > bestScore) { bestScore = score; bestMove = m; }
    }

    const md = getMove(bestMove.id);
    return { type: 'move', moveId: bestMove.id, priority: md?.pri ?? 0 };
}

// ── HP bar ─────────────────────────────────────────────────────────────────────
function hpBar(current, max) {
    const pct   = Math.max(0, current / max);
    const fill  = Math.round(pct * 10);
    const empty = 10 - fill;
    const bar   = '█'.repeat(fill) + '░'.repeat(empty);
    const color = pct > 0.5 ? '🟩' : pct > 0.25 ? '🟨' : '🟥';
    return `${color} \`${bar}\` ${current}/${max}`;
}

// ── Battle management ──────────────────────────────────────────────────────────
function createBattle(key, type, trainers, difficulty = 5) {
    const state = { key, type, trainers, difficulty, started: false, ended: false, winner: null,
                    pendingActions: {}, ts: Date.now() };
    battles.set(key, state);
    return state;
}

function getBattle(key)  { return battles.get(key) || null; }
function endBattle(key)  { battles.delete(key); }

function getBattleByUserId(userId) {
    for (const b of battles.values()) {
        if (b.trainers.some(t => t.userId === userId)) return b;
    }
    return null;
}

function createChallenge(key, data) { challenges.set(key, { ...data, ts: Date.now() }); return data; }
function getChallenge(key)  { return challenges.get(key) || null; }
function endChallenge(key)  { challenges.delete(key); }

// Clean up stale battles/challenges every 5 minutes
setInterval(() => {
    const now = Date.now();
    for (const [k, b] of battles)    if (now - b.ts > 10 * 60 * 1000) battles.delete(k);
    for (const [k, c] of challenges) if (now - c.ts > 5  * 60 * 1000) challenges.delete(k);
}, 5 * 60 * 1000);

module.exports = {
    buildBattlePokemon,
    calcDamage, calcMaxHp, calcStat,
    executeTurn, aiPickMove,
    hpBar,
    createBattle, getBattle, endBattle, getBattleByUserId,
    createChallenge, getChallenge, endChallenge,
};
