// PFAD: /core/installer/src/data/pokemia-moves.js
// id, name, type, category (physical/special/status), power, accuracy, pp, priority, effect

const MOVES = [
  // ── Normal ──
  { id:1,   name:'Pound',         type:'normal',   cat:'physical', power:40,  acc:100, pp:35, pri:0, effect:null },
  { id:2,   name:'Karate Chop',   type:'fighting', cat:'physical', power:50,  acc:100, pp:25, pri:0, effect:null },
  { id:3,   name:'Double Slap',   type:'normal',   cat:'physical', power:15,  acc:85,  pp:10, pri:0, effect:null },
  { id:10,  name:'Scratch',       type:'normal',   cat:'physical', power:40,  acc:100, pp:35, pri:0, effect:null },
  { id:16,  name:'Gust',          type:'flying',   cat:'special',  power:40,  acc:100, pp:35, pri:0, effect:null },
  { id:17,  name:'Wing Attack',   type:'flying',   cat:'physical', power:60,  acc:100, pp:35, pri:0, effect:null },
  { id:18,  name:'Whirlwind',     type:'normal',   cat:'status',   power:0,   acc:100, pp:20, pri:-6,effect:null },
  { id:19,  name:'Fly',           type:'flying',   cat:'physical', power:90,  acc:95,  pp:15, pri:0, effect:null },
  { id:20,  name:'Bind',          type:'normal',   cat:'physical', power:15,  acc:85,  pp:20, pri:0, effect:null },
  { id:21,  name:'Slam',          type:'normal',   cat:'physical', power:80,  acc:75,  pp:20, pri:0, effect:null },
  { id:22,  name:'Vine Whip',     type:'grass',    cat:'physical', power:45,  acc:100, pp:25, pri:0, effect:null },
  { id:24,  name:'Double Kick',   type:'fighting', cat:'physical', power:30,  acc:100, pp:30, pri:0, effect:null },
  { id:28,  name:'Sand Attack',   type:'ground',   cat:'status',   power:0,   acc:100, pp:15, pri:0, effect:{stat:{stat:'accuracy',change:-1,target:'opponent'}} },
  { id:31,  name:'Fury Attack',   type:'normal',   cat:'physical', power:15,  acc:85,  pp:20, pri:0, effect:null },
  { id:33,  name:'Tackle',        type:'normal',   cat:'physical', power:40,  acc:100, pp:35, pri:0, effect:null },
  { id:34,  name:'Body Slam',     type:'normal',   cat:'physical', power:85,  acc:100, pp:15, pri:0, effect:{ailment:'paralysis',chance:30} },
  { id:35,  name:'Wrap',          type:'normal',   cat:'physical', power:15,  acc:90,  pp:20, pri:0, effect:null },
  { id:37,  name:'Thrash',        type:'normal',   cat:'physical', power:120, acc:100, pp:10, pri:0, effect:null },
  { id:39,  name:'Tail Whip',     type:'normal',   cat:'status',   power:0,   acc:100, pp:30, pri:0, effect:{stat:{stat:'def',change:-1,target:'opponent'}} },
  { id:40,  name:'Poison Sting',  type:'poison',   cat:'physical', power:15,  acc:100, pp:35, pri:0, effect:{ailment:'poison',chance:30} },
  { id:41,  name:'Twineedle',     type:'bug',      cat:'physical', power:25,  acc:100, pp:20, pri:0, effect:{ailment:'poison',chance:20} },
  { id:43,  name:'Leech Life',    type:'bug',      cat:'physical', power:80,  acc:100, pp:10, pri:0, effect:{drain:0.5} },
  { id:44,  name:'Bite',          type:'dark',     cat:'physical', power:60,  acc:100, pp:25, pri:0, effect:null },
  { id:45,  name:'Growl',         type:'normal',   cat:'status',   power:0,   acc:100, pp:40, pri:0, effect:{stat:{stat:'atk',change:-1,target:'opponent'}} },
  { id:47,  name:'Sing',          type:'normal',   cat:'status',   power:0,   acc:55,  pp:15, pri:0, effect:{ailment:'sleep',chance:100} },
  { id:48,  name:'Supersonic',    type:'normal',   cat:'status',   power:0,   acc:55,  pp:20, pri:0, effect:{ailment:'confusion',chance:100} },
  { id:50,  name:'Acid',          type:'poison',   cat:'special',  power:40,  acc:100, pp:30, pri:0, effect:{stat:{stat:'spdef',change:-1,target:'opponent'},chance:10} },
  { id:52,  name:'Ember',         type:'fire',     cat:'special',  power:40,  acc:100, pp:25, pri:0, effect:{ailment:'burn',chance:10} },
  { id:53,  name:'Flamethrower',  type:'fire',     cat:'special',  power:90,  acc:100, pp:15, pri:0, effect:{ailment:'burn',chance:10} },
  { id:55,  name:'Water Gun',     type:'water',    cat:'special',  power:40,  acc:100, pp:25, pri:0, effect:null },
  { id:56,  name:'Hydro Pump',    type:'water',    cat:'special',  power:110, acc:80,  pp:5,  pri:0, effect:null },
  { id:57,  name:'Surf',          type:'water',    cat:'special',  power:90,  acc:100, pp:15, pri:0, effect:null },
  { id:58,  name:'Ice Beam',      type:'ice',      cat:'special',  power:90,  acc:100, pp:10, pri:0, effect:{ailment:'freeze',chance:10} },
  { id:59,  name:'Blizzard',      type:'ice',      cat:'special',  power:110, acc:70,  pp:5,  pri:0, effect:{ailment:'freeze',chance:10} },
  { id:60,  name:'Psybeam',       type:'psychic',  cat:'special',  power:65,  acc:100, pp:20, pri:0, effect:{ailment:'confusion',chance:10} },
  { id:61,  name:'Bubble Beam',   type:'water',    cat:'special',  power:65,  acc:100, pp:20, pri:0, effect:{stat:{stat:'spd',change:-1,target:'opponent'},chance:10} },
  { id:67,  name:'Low Kick',      type:'fighting', cat:'physical', power:50,  acc:100, pp:20, pri:0, effect:null },
  { id:71,  name:'Absorb',        type:'grass',    cat:'special',  power:20,  acc:100, pp:25, pri:0, effect:{drain:0.5} },
  { id:72,  name:'Mega Drain',    type:'grass',    cat:'special',  power:40,  acc:100, pp:15, pri:0, effect:{drain:0.5} },
  { id:73,  name:'Leech Seed',    type:'grass',    cat:'status',   power:0,   acc:90,  pp:10, pri:0, effect:{ailment:'leech_seed',chance:100} },
  { id:74,  name:'Growth',        type:'normal',   cat:'status',   power:0,   acc:100, pp:20, pri:0, effect:{stat:{stat:'satk',change:1,target:'self'}} },
  { id:75,  name:'Razor Leaf',    type:'grass',    cat:'physical', power:55,  acc:95,  pp:25, pri:0, effect:null },
  { id:76,  name:'Solar Beam',    type:'grass',    cat:'special',  power:120, acc:100, pp:10, pri:0, effect:null },
  { id:77,  name:'Poison Powder', type:'poison',   cat:'status',   power:0,   acc:75,  pp:35, pri:0, effect:{ailment:'poison',chance:100} },
  { id:78,  name:'Stun Spore',    type:'grass',    cat:'status',   power:0,   acc:75,  pp:30, pri:0, effect:{ailment:'paralysis',chance:100} },
  { id:79,  name:'Sleep Powder',  type:'grass',    cat:'status',   power:0,   acc:75,  pp:15, pri:0, effect:{ailment:'sleep',chance:100} },
  { id:80,  name:'Petal Dance',   type:'grass',    cat:'special',  power:120, acc:100, pp:10, pri:0, effect:null },
  { id:82,  name:'Dragon Rage',   type:'dragon',   cat:'special',  power:40,  acc:100, pp:10, pri:0, effect:null },
  { id:83,  name:'Fire Spin',     type:'fire',     cat:'special',  power:35,  acc:85,  pp:15, pri:0, effect:null },
  { id:84,  name:'Thundershock',  type:'electric', cat:'special',  power:40,  acc:100, pp:30, pri:0, effect:{ailment:'paralysis',chance:10} },
  { id:85,  name:'Thunderbolt',   type:'electric', cat:'special',  power:90,  acc:100, pp:15, pri:0, effect:{ailment:'paralysis',chance:10} },
  { id:86,  name:'Thunder Wave',  type:'electric', cat:'status',   power:0,   acc:90,  pp:20, pri:0, effect:{ailment:'paralysis',chance:100} },
  { id:87,  name:'Thunder',       type:'electric', cat:'special',  power:110, acc:70,  pp:10, pri:0, effect:{ailment:'paralysis',chance:30} },
  { id:88,  name:'Rock Throw',    type:'rock',     cat:'physical', power:50,  acc:90,  pp:15, pri:0, effect:null },
  { id:89,  name:'Earthquake',    type:'ground',   cat:'physical', power:100, acc:100, pp:10, pri:0, effect:null },
  { id:91,  name:'Dig',           type:'ground',   cat:'physical', power:80,  acc:100, pp:10, pri:0, effect:null },
  { id:93,  name:'Confusion',     type:'psychic',  cat:'special',  power:50,  acc:100, pp:25, pri:0, effect:{ailment:'confusion',chance:10} },
  { id:94,  name:'Psychic',       type:'psychic',  cat:'special',  power:90,  acc:100, pp:10, pri:0, effect:{stat:{stat:'spdef',change:-1,target:'opponent'},chance:10} },
  { id:97,  name:'Agility',       type:'psychic',  cat:'status',   power:0,   acc:100, pp:30, pri:0, effect:{stat:{stat:'spd',change:2,target:'self'}} },
  { id:98,  name:'Quick Attack',  type:'normal',   cat:'physical', power:40,  acc:100, pp:30, pri:1, effect:null },
  { id:99,  name:'Rage',          type:'normal',   cat:'physical', power:20,  acc:100, pp:20, pri:0, effect:null },
  { id:100, name:'Teleport',      type:'psychic',  cat:'status',   power:0,   acc:100, pp:20, pri:-6,effect:null },
  { id:103, name:'Screech',       type:'normal',   cat:'status',   power:0,   acc:85,  pp:40, pri:0, effect:{stat:{stat:'def',change:-2,target:'opponent'}} },
  { id:104, name:'Double Team',   type:'normal',   cat:'status',   power:0,   acc:100, pp:15, pri:0, effect:{stat:{stat:'evasion',change:1,target:'self'}} },
  { id:106, name:'String Shot',   type:'bug',      cat:'status',   power:0,   acc:95,  pp:40, pri:0, effect:{stat:{stat:'spd',change:-1,target:'opponent'}} },
  { id:107, name:'Night Shade',   type:'ghost',    cat:'special',  power:1,   acc:100, pp:15, pri:0, effect:{fixed_damage:true} },
  { id:108, name:'Mimic',         type:'normal',   cat:'status',   power:0,   acc:100, pp:10, pri:0, effect:null },
  { id:109, name:'Shadow Ball',   type:'ghost',    cat:'special',  power:80,  acc:100, pp:15, pri:0, effect:{stat:{stat:'spdef',change:-1,target:'opponent'},chance:20} },
  { id:110, name:'Blizzard',      type:'ice',      cat:'special',  power:110, acc:70,  pp:5,  pri:0, effect:{ailment:'freeze',chance:10} },
  // Duplicate alias for Ice Beam variant
  { id:111, name:'Bubble',        type:'water',    cat:'special',  power:40,  acc:100, pp:30, pri:0, effect:{stat:{stat:'spd',change:-1,target:'opponent'},chance:10} },
  { id:116, name:'Pay Day',       type:'normal',   cat:'physical', power:40,  acc:100, pp:20, pri:0, effect:null },
  { id:118, name:'Soft-Boiled',   type:'normal',   cat:'status',   power:0,   acc:100, pp:10, pri:0, effect:{heal:0.5} },
  { id:119, name:'Sludge',        type:'poison',   cat:'special',  power:65,  acc:100, pp:20, pri:0, effect:{ailment:'poison',chance:30} },
  { id:120, name:'Selfdestruct',  type:'normal',   cat:'physical', power:200, acc:100, pp:5,  pri:0, effect:{recoil:1.0} },
  { id:122, name:'Lick',          type:'ghost',    cat:'physical', power:30,  acc:100, pp:30, pri:0, effect:{ailment:'paralysis',chance:30} },
  { id:124, name:'Sludge Bomb',   type:'poison',   cat:'special',  power:90,  acc:100, pp:10, pri:0, effect:{ailment:'poison',chance:30} },
  { id:129, name:'Flash',         type:'normal',   cat:'status',   power:0,   acc:100, pp:20, pri:0, effect:{stat:{stat:'accuracy',change:-1,target:'opponent'}} },
  { id:144, name:'Transform',     type:'normal',   cat:'status',   power:0,   acc:100, pp:10, pri:0, effect:null },
  { id:150, name:'Splash',        type:'normal',   cat:'status',   power:0,   acc:100, pp:40, pri:0, effect:null },
];

const MOVES_MAP = new Map(MOVES.map(m => [m.id, m]));

function getMove(id) { return MOVES_MAP.get(id) || null; }
function getMoveByName(name) {
    const n = name.trim().toLowerCase();
    return MOVES.find(m => m.name.toLowerCase() === n) || null;
}

// Type effectiveness chart [attackingType][defendingType] = multiplier
// 0 = immune, 0.5 = not effective, 1 = normal, 2 = super effective
const TYPES = ['normal','fire','water','electric','grass','ice','fighting','poison','ground','flying','psychic','bug','rock','ghost','dragon','dark','steel','fairy'];

// Built as a flat object: TYPE_CHART['fire']['grass'] = 2
const TYPE_CHART = (() => {
    const chart = {};
    for (const t of TYPES) {
        chart[t] = {};
        for (const d of TYPES) chart[t][d] = 1;
    }
    // Immunities (0)
    const immune = {
        normal:   ['ghost'], electric: ['ground'], fighting: ['ghost'],
        ground:   ['flying'], ghost: ['normal','psychic'], poison: [],
        dragon:   [], dark: ['psychic'], steel: ['poison','dark']
    };
    // 2x
    const se = {
        fire:     ['grass','ice','bug','steel'],
        water:    ['fire','ground','rock'],
        electric: ['water','flying'],
        grass:    ['water','ground','rock'],
        ice:      ['grass','ground','flying','dragon'],
        fighting: ['normal','ice','rock','dark','steel'],
        poison:   ['grass','fairy'],
        ground:   ['fire','electric','poison','rock','steel'],
        flying:   ['grass','fighting','bug'],
        psychic:  ['fighting','poison'],
        bug:      ['grass','psychic','dark'],
        rock:     ['fire','ice','flying','bug'],
        ghost:    ['ghost','psychic'],
        dragon:   ['dragon'],
        dark:     ['ghost','psychic'],
        steel:    ['ice','rock','fairy'],
        fairy:    ['fighting','dragon','dark'],
        normal:   []
    };
    // 0.5x
    const nve = {
        fire:     ['fire','water','rock','dragon'],
        water:    ['water','grass','dragon'],
        electric: ['electric','grass','dragon'],
        grass:    ['fire','grass','poison','flying','bug','dragon','steel'],
        ice:      ['water','ice','steel'],
        fighting: ['poison','flying','psychic','bug','fairy'],
        poison:   ['poison','ground','rock','ghost'],
        ground:   ['grass','bug'],
        flying:   ['electric','rock','steel'],
        psychic:  ['psychic','steel'],
        bug:      ['fire','fighting','flying','ghost','steel','fairy'],
        rock:     ['fighting','ground','steel'],
        ghost:    ['dark'],
        dragon:   ['steel'],
        dark:     ['fighting','dark','fairy'],
        steel:    ['fire','water','electric','steel'],
        fairy:    ['fire','poison','steel'],
        normal:   ['rock','steel']
    };

    for (const [att, defs] of Object.entries(immune)) {
        for (const d of defs) { if (chart[att]) chart[att][d] = 0; }
    }
    for (const [att, defs] of Object.entries(se)) {
        for (const d of defs) { if (chart[att]) chart[att][d] = 2; }
    }
    for (const [att, defs] of Object.entries(nve)) {
        for (const d of defs) { if (chart[att]) chart[att][d] = 0.5; }
    }
    return chart;
})();

function getEffectiveness(attackType, defType1, defType2 = null) {
    const e1 = TYPE_CHART[attackType]?.[defType1] ?? 1;
    const e2 = defType2 ? (TYPE_CHART[attackType]?.[defType2] ?? 1) : 1;
    return e1 * e2;
}

const NATURES = {
    Hardy:   { atk:1,   def:1,   satk:1,   spdef:1,   spd:1   },
    Lonely:  { atk:1.1, def:0.9, satk:1,   spdef:1,   spd:1   },
    Brave:   { atk:1.1, def:1,   satk:1,   spdef:1,   spd:0.9 },
    Adamant: { atk:1.1, def:1,   satk:0.9, spdef:1,   spd:1   },
    Naughty: { atk:1.1, def:1,   satk:1,   spdef:0.9, spd:1   },
    Bold:    { atk:0.9, def:1.1, satk:1,   spdef:1,   spd:1   },
    Docile:  { atk:1,   def:1,   satk:1,   spdef:1,   spd:1   },
    Relaxed: { atk:1,   def:1.1, satk:1,   spdef:1,   spd:0.9 },
    Impish:  { atk:1,   def:1.1, satk:0.9, spdef:1,   spd:1   },
    Lax:     { atk:1,   def:1.1, satk:1,   spdef:0.9, spd:1   },
    Timid:   { atk:0.9, def:1,   satk:1,   spdef:1,   spd:1.1 },
    Hasty:   { atk:1,   def:0.9, satk:1,   spdef:1,   spd:1.1 },
    Serious: { atk:1,   def:1,   satk:1,   spdef:1,   spd:1   },
    Jolly:   { atk:1,   def:1,   satk:0.9, spdef:1,   spd:1.1 },
    Naive:   { atk:1,   def:1,   satk:1,   spdef:0.9, spd:1.1 },
    Modest:  { atk:0.9, def:1,   satk:1.1, spdef:1,   spd:1   },
    Mild:    { atk:1,   def:0.9, satk:1.1, spdef:1,   spd:1   },
    Quiet:   { atk:1,   def:1,   satk:1.1, spdef:1,   spd:0.9 },
    Bashful: { atk:1,   def:1,   satk:1,   spdef:1,   spd:1   },
    Rash:    { atk:1,   def:1,   satk:1.1, spdef:0.9, spd:1   },
    Calm:    { atk:0.9, def:1,   satk:1,   spdef:1.1, spd:1   },
    Gentle:  { atk:1,   def:0.9, satk:1,   spdef:1.1, spd:1   },
    Sassy:   { atk:1,   def:1,   satk:1,   spdef:1.1, spd:0.9 },
    Careful: { atk:1,   def:1,   satk:0.9, spdef:1.1, spd:1   },
    Quirky:  { atk:1,   def:1,   satk:1,   spdef:1,   spd:1   },
};
const NATURE_NAMES = Object.keys(NATURES);
function randomNature() { return NATURE_NAMES[Math.floor(Math.random() * NATURE_NAMES.length)]; }

module.exports = { MOVES, MOVES_MAP, getMove, getMoveByName, TYPE_CHART, NATURES, NATURE_NAMES, randomNature, getEffectiveness };
