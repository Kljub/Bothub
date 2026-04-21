<?php
declare(strict_types=1);

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:var(--bh-text-muted,#8b949e)">Kein Bot ausgewählt.</p>';
    return;
}

$botId = (int)$currentBotId;

require_once dirname(__DIR__) . '/functions/custom_commands.php';
require_once dirname(__DIR__) . '/functions/db_functions/temp_voice.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo = bh_tv_get_pdo();

function tv_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try { bh_tv_ensure_tables(); } catch (Throwable) {}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tv_action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action  = (string)$_POST['tv_action'];
        $guildId = trim((string)($_POST['guild_id'] ?? ''));

        if ($action === 'save') {
            if ($guildId === '') throw new RuntimeException('Keine Guild-ID angegeben.');
            $triggerChannelId = trim((string)($_POST['trigger_channel_id'] ?? ''));
            $categoryId       = trim((string)($_POST['category_id']       ?? ''));
            $channelName      = mb_substr(trim((string)($_POST['channel_name'] ?? 'Temp #{n}')), 0, 100) ?: 'Temp #{n}';
            $userLimit        = max(0, min(99, (int)($_POST['user_limit'] ?? 0)));
            $bitrate          = max(8000, min(384000, (int)($_POST['bitrate'] ?? 64000)));
            $isEnabled        = isset($_POST['is_enabled']) ? 1 : 0;
            bh_tv_save_settings($botId, $guildId, $triggerChannelId, $categoryId, $channelName, $userLimit, $bitrate, $isEnabled);
            echo json_encode(['ok' => true]); exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$guilds = [];
try {
    $stmt = $pdo->prepare('SELECT guild_id, guild_name FROM bot_guilds WHERE bot_id = ? ORDER BY guild_name ASC');
    $stmt->execute([$botId]);
    $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$allSettings = [];
try { $allSettings = bh_tv_get_settings_by_bot($botId); } catch (Throwable) {}

$settingsMap = [];
foreach ($allSettings as $row) {
    $settingsMap[(string)$row['guild_id']] = $row;
}

$activeCounts = [];
$totalActive  = 0;
try {
    $stmt = $pdo->prepare(
        'SELECT guild_id, COUNT(*) AS cnt FROM bot_temp_voice_channels WHERE bot_id = ? GROUP BY guild_id'
    );
    $stmt->execute([$botId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activeCounts[(string)$row['guild_id']] = (int)$row['cnt'];
        $totalActive += (int)$row['cnt'];
    }
} catch (Throwable) {}

$configuredCount = count(array_filter($allSettings, fn($r) => !empty($r['trigger_channel_id'])));
$enabledCount    = count(array_filter($allSettings, fn($r) => !empty($r['is_enabled']) && !empty($r['trigger_channel_id'])));
$modEnabled      = bh_mod_is_enabled($pdo, $botId, 'module:temp-voice');
?>

<div class="tv-page" id="tv-root">

    <!-- ── Header ────────────────────────────────────────────────────────── -->
    <div class="lv-head">
        <div class="lv-kicker">Voice</div>
        <h1 class="lv-title">Temp Voice Channels</h1>
        <p class="lv-subtitle">Nutzer joinen einen Trigger-Channel → Bot erstellt automatisch einen privaten VC → wird gelöscht, sobald er leer ist.</p>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:temp-voice', 'Temp Voice Channels', 'Temporäre Voice Channels für diesen Bot ein- oder ausschalten.') ?>
    <div id="bh-mod-body">

    <!-- ── Stats ─────────────────────────────────────────────────────────── -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= count($guilds) ?></div>
            <div class="lv-stat__lbl">Server</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $enabledCount ?></div>
            <div class="lv-stat__lbl">Aktiv konfiguriert</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val" style="<?= $totalActive > 0 ? 'color:#4caf7d' : '' ?>"><?= $totalActive ?></div>
            <div class="lv-stat__lbl">Offene Temp-Channels</div>
        </div>
    </div>

    <div id="tv-alert" style="display:none;margin-bottom:16px" class="lv-alert"></div>

    <?php if (empty($guilds)): ?>
    <div class="lv-card">
        <div class="tv-empty">
            <div class="tv-empty__icon">🤖</div>
            <div class="tv-empty__title">Keine Server gefunden</div>
            <div class="tv-empty__desc">Der Bot ist noch auf keinem Server aktiv oder<br>die Serverliste wurde noch nicht synchronisiert.</div>
        </div>
    </div>
    <?php else: ?>

    <!-- ── Guild selector ────────────────────────────────────────────────── -->
    <?php if (count($guilds) > 1): ?>
    <div class="tv-guild-tabs" id="tv-guild-tabs">
        <?php foreach ($guilds as $i => $guild): ?>
        <?php
            $gid  = (string)$guild['guild_id'];
            $gname = tv_h((string)($guild['guild_name'] ?? $guild['guild_id']));
            $cnt  = $activeCounts[$gid] ?? 0;
        ?>
        <button type="button"
            class="tv-guild-tab<?= $i === 0 ? ' is-active' : '' ?>"
            data-guild-id="<?= tv_h($gid) ?>"
            onclick="tvSelectGuild('<?= tv_h($gid) ?>')">
            <span class="tv-guild-dot"></span>
            <?= $gname ?>
            <?php if ($cnt > 0): ?><span class="tv-badge"><?= $cnt ?></span><?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Per-guild panels ───────────────────────────────────────────────── -->
    <?php foreach ($guilds as $i => $guild): ?>
    <?php
        $gid       = (string)$guild['guild_id'];
        $s         = $settingsMap[$gid] ?? [];
        $activeNow = $activeCounts[$gid] ?? 0;
        $isOn      = !empty($s['is_enabled']) && !empty($s['trigger_channel_id']);

        $activeChs = [];
        try { $activeChs = bh_tv_list_active_channels($botId, $gid); } catch (Throwable) {}
    ?>
    <div class="tv-guild-panel<?= $i === 0 ? ' is-active' : '' ?>" data-guild-id="<?= tv_h($gid) ?>">

        <!-- ── Enable toggle ── -->
        <div class="lv-card">
            <div class="lv-feature" style="border-bottom:none">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Modul aktiviert</div>
                    <div class="lv-feature__desc">Temp Voice Channels für diesen Server ein- oder ausschalten.</div>
                </div>
                <div class="lv-feature__right" style="display:flex;align-items:center;gap:12px">
                    <span class="tv-status <?= $isOn ? 'tv-status--on' : 'tv-status--off' ?>" id="tv-status-<?= tv_h($gid) ?>">
                        <span class="tv-status__dot"></span>
                        <?= $isOn ? 'Aktiv' : 'Inaktiv' ?>
                    </span>
                    <label class="lv-toggle">
                        <input type="checkbox" class="tv-enabled-toggle"
                            data-guild-id="<?= tv_h($gid) ?>"
                            <?= !empty($s['is_enabled']) ? 'checked' : '' ?>>
                        <span class="lv-toggle__track"></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- ── Configuration ── -->
        <div class="lv-card">
            <div class="lv-card__hdr">
                <div class="lv-card__hdr-left">
                    <div class="lv-card__kicker">Setup</div>
                    <div class="lv-card__title">Channel-Konfiguration</div>
                </div>
            </div>
            <div class="lv-card__body">

                <!-- Trigger + Category -->
                <div class="tv-grid2">
                    <!-- Trigger Channel picker -->
                    <div class="lv-field">
                        <label class="lv-label">
                            Trigger Channel
                            <span style="color:#f87171;margin-left:2px">*</span>
                        </label>
                        <div class="tv-ch-wrap" id="tv-wrap-trigger-<?= tv_h($gid) ?>">
                            <div class="tv-ch-row">
                                <input type="text"
                                    class="lv-input tv-field"
                                    id="tv-trigger-input-<?= tv_h($gid) ?>"
                                    data-field="trigger_channel_id"
                                    data-guild-id="<?= tv_h($gid) ?>"
                                    value="<?= tv_h((string)($s['trigger_channel_id'] ?? '')) ?>"
                                    placeholder="Channel-ID"
                                    maxlength="20">
                                <button type="button"
                                    class="tv-pick-btn"
                                    onclick="tvOpenPicker('trigger','<?= tv_h($gid) ?>')"
                                    title="Voice Channel auswählen">
                                    <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M3 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H3Zm0 1h10v10H3V3Zm2 2v6h1V5H5Zm5 0v6h-1V5h1ZM7 6v4h2V6H7Z"/></svg>
                                    Auswählen
                                </button>
                            </div>
                            <?php if (!empty($s['trigger_channel_id'])): ?>
                            <div class="tv-selected-name" id="tv-trigger-sel-<?= tv_h($gid) ?>">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M3 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H3Zm0 1h10v10H3V3Zm2 2v6h1V5H5Zm5 0v6h-1V5h1ZM7 6v4h2V6H7Z"/></svg>
                                <span><?= tv_h((string)($s['trigger_channel_id'] ?? '')) ?></span>
                            </div>
                            <?php else: ?>
                            <div class="tv-selected-name" id="tv-trigger-sel-<?= tv_h($gid) ?>" style="display:none"></div>
                            <?php endif; ?>
                            <!-- Dropdown -->
                            <div class="tv-dropdown" id="tv-dd-trigger-<?= tv_h($gid) ?>">
                                <div class="tv-dropdown-search">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
                                    <input type="text" placeholder="Channel suchen…" oninput="tvFilterDropdown('trigger','<?= tv_h($gid) ?>',this.value)">
                                </div>
                                <div class="tv-dropdown-list" id="tv-dd-trigger-list-<?= tv_h($gid) ?>"></div>
                            </div>
                        </div>
                        <div class="lv-hint">Voice Channel, dem Nutzer beitreten → Trigger.</div>
                    </div>

                    <!-- Category picker -->
                    <div class="lv-field">
                        <label class="lv-label">
                            Kategorie
                            <span style="color:#8991a1;font-size:10px;font-weight:400;margin-left:4px">optional</span>
                        </label>
                        <div class="tv-ch-wrap" id="tv-wrap-category-<?= tv_h($gid) ?>">
                            <div class="tv-ch-row">
                                <input type="text"
                                    class="lv-input tv-field"
                                    id="tv-category-input-<?= tv_h($gid) ?>"
                                    data-field="category_id"
                                    data-guild-id="<?= tv_h($gid) ?>"
                                    value="<?= tv_h((string)($s['category_id'] ?? '')) ?>"
                                    placeholder="Kategorie-ID"
                                    maxlength="20">
                                <button type="button"
                                    class="tv-pick-btn"
                                    onclick="tvOpenPicker('category','<?= tv_h($gid) ?>')"
                                    title="Kategorie auswählen">
                                    <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M1 3.5A1.5 1.5 0 0 1 2.5 2h2.764c.958 0 1.76.56 2.311 1.184C7.985 3.648 8.48 4 9 4h4.5A1.5 1.5 0 0 1 15 5.5v7a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 1 12.5v-9z"/></svg>
                                    Auswählen
                                </button>
                            </div>
                            <?php if (!empty($s['category_id'])): ?>
                            <div class="tv-selected-name" id="tv-category-sel-<?= tv_h($gid) ?>">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M1 3.5A1.5 1.5 0 0 1 2.5 2h2.764c.958 0 1.76.56 2.311 1.184C7.985 3.648 8.48 4 9 4h4.5A1.5 1.5 0 0 1 15 5.5v7a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 1 12.5v-9z"/></svg>
                                <span><?= tv_h((string)($s['category_id'] ?? '')) ?></span>
                            </div>
                            <?php else: ?>
                            <div class="tv-selected-name" id="tv-category-sel-<?= tv_h($gid) ?>" style="display:none"></div>
                            <?php endif; ?>
                            <!-- Dropdown -->
                            <div class="tv-dropdown" id="tv-dd-category-<?= tv_h($gid) ?>">
                                <div class="tv-dropdown-search">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
                                    <input type="text" placeholder="Kategorie suchen…" oninput="tvFilterDropdown('category','<?= tv_h($gid) ?>',this.value)">
                                </div>
                                <div class="tv-dropdown-list" id="tv-dd-category-list-<?= tv_h($gid) ?>"></div>
                            </div>
                        </div>
                        <div class="lv-hint">Temp Channels werden in dieser Kategorie angelegt. Leer = keine Kategorie.</div>
                    </div>
                </div>

                <!-- Channel name -->
                <div class="lv-field">
                    <label class="lv-label">Channel-Name Vorlage</label>
                    <input type="text"
                        class="lv-input tv-field"
                        data-field="channel_name"
                        data-guild-id="<?= tv_h($gid) ?>"
                        value="<?= tv_h((string)($s['channel_name'] ?? 'Temp #{n}')) ?>"
                        placeholder="Temp #{n}"
                        maxlength="100">
                    <div class="tv-vars">
                        <span style="font-size:11px;color:var(--bh-text-muted,#8b949e);line-height:24px;margin-right:2px">Variablen:</span>
                        <span class="tv-var" onclick="tvInsertVar(this,'<?= tv_h($gid) ?>')" title="Laufende Nummer">{n} <span class="tv-var-label">Nummer</span></span>
                        <span class="tv-var" onclick="tvInsertVar(this,'<?= tv_h($gid) ?>')" title="Discord-Username">{username} <span class="tv-var-label">Username</span></span>
                        <span class="tv-var" onclick="tvInsertVar(this,'<?= tv_h($gid) ?>')" title="Server-Anzeigename">{user} <span class="tv-var-label">Anzeigename</span></span>
                    </div>
                </div>

                <!-- User limit + Bitrate -->
                <div class="tv-grid2">
                    <div class="lv-field">
                        <label class="lv-label">Nutzer-Limit</label>
                        <input type="number"
                            class="lv-input tv-field"
                            data-field="user_limit"
                            data-guild-id="<?= tv_h($gid) ?>"
                            value="<?= (int)($s['user_limit'] ?? 0) ?>"
                            min="0" max="99" placeholder="0">
                        <div class="lv-hint">0 = unbegrenzt</div>
                    </div>
                    <div class="lv-field">
                        <label class="lv-label">Bitrate</label>
                        <select class="lv-input tv-field"
                            data-field="bitrate"
                            data-guild-id="<?= tv_h($gid) ?>">
                            <?php
                            $bitrateOptions = [8000 => '8 kbps', 32000 => '32 kbps', 64000 => '64 kbps (Standard)', 96000 => '96 kbps', 128000 => '128 kbps', 256000 => '256 kbps', 384000 => '384 kbps'];
                            $currentBitrate = (int)($s['bitrate'] ?? 64000);
                            foreach ($bitrateOptions as $val => $label):
                            ?>
                            <option value="<?= $val ?>" <?= $currentBitrate === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="lv-hint">Maximale Bitrate je nach Server-Boost-Level.</div>
                    </div>
                </div>

                <div class="lv-btn-row">
                    <button type="button" class="lv-btn tv-save-btn" data-guild-id="<?= tv_h($gid) ?>" onclick="tvSave('<?= tv_h($gid) ?>')">
                        Speichern
                    </button>
                    <span class="lv-save-msg tv-save-msg" data-guild-id="<?= tv_h($gid) ?>" style="display:none"></span>
                </div>

                <!-- How it works -->
                <div class="tv-info">
                    <div class="tv-info__icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM7.25 5a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0Zm.75 2.25a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0V8a.75.75 0 0 1 .75-.75Z" fill="currentColor" style="color:#a89fff"/>
                        </svg>
                    </div>
                    <div class="tv-info__body">
                        <div class="tv-info__title">So funktioniert es</div>
                        <div class="tv-info__desc">
                            Nutzer joinen den Trigger-Channel → Bot erstellt einen neuen VC mit dem Namensmuster → Nutzer wird in den neuen VC verschoben → Wenn der VC leer wird, löscht der Bot ihn automatisch.
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Live channels ── -->
        <div class="lv-card">
            <div class="lv-card__hdr">
                <div class="lv-card__hdr-left">
                    <div class="lv-card__kicker">Live</div>
                    <div class="lv-card__title">Aktive Temp Channels</div>
                </div>
                <?php if ($activeNow > 0): ?>
                <span class="tv-status tv-status--on">
                    <span class="tv-status__dot"></span>
                    <?= $activeNow ?> offen
                </span>
                <?php else: ?>
                <span class="tv-status tv-status--off">
                    <span class="tv-status__dot"></span>
                    Keine
                </span>
                <?php endif; ?>
            </div>
            <div class="lv-card__body">
                <?php if (empty($activeChs)): ?>
                <div class="tv-empty" style="padding:28px 20px">
                    <div class="tv-empty__icon" style="font-size:22px">🔇</div>
                    <div class="tv-empty__title">Keine aktiven Temp Channels</div>
                    <div class="tv-empty__desc">Sobald Nutzer den Trigger-Channel betreten, erscheinen hier die erstellten Channels.</div>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="tv-table">
                        <thead>
                            <tr>
                                <th style="width:48px">#</th>
                                <th>Channel ID</th>
                                <th>Erstellt von</th>
                                <th>Erstellt am</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeChs as $ch): ?>
                            <tr>
                                <td><span class="tv-num"><?= (int)$ch['channel_num'] ?></span></td>
                                <td><span class="tv-code"><?= tv_h((string)$ch['channel_id']) ?></span></td>
                                <td><span class="tv-code tv-code--muted"><?= tv_h((string)$ch['owner_id']) ?></span></td>
                                <td style="color:var(--bh-text-muted,#8b949e);font-size:12px">
                                    <?= isset($ch['created_at']) ? date('d.m.Y · H:i', strtotime((string)$ch['created_at'])) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /tv-guild-panel -->
    <?php endforeach; ?>

    <?php endif; ?>

    </div><!-- /bh-mod-body -->
</div>

<script>
(function () {
    'use strict';

    const BOT_ID   = <?= $botId ?>;
    const API_BASE = '/api/v1/bot_guild_channels.php';

    // ── Channel data cache per guild ──────────────────────────────────────────
    // key: `${type}:${guildId}` → array of channel objects
    const channelCache = new Map();

    // ── Guild tab switching ───────────────────────────────────────────────────
    function tvSelectGuild(guildId) {
        document.querySelectorAll('.tv-guild-tab').forEach((t) =>
            t.classList.toggle('is-active', t.dataset.guildId === guildId)
        );
        document.querySelectorAll('.tv-guild-panel').forEach((p) =>
            p.classList.toggle('is-active', p.dataset.guildId === guildId)
        );
        closeAllDropdowns();
    }
    window.tvSelectGuild = tvSelectGuild;

    // ── Variable insert ───────────────────────────────────────────────────────
    function tvInsertVar(el, guildId) {
        const varText = el.querySelector('span:first-child')?.textContent?.trim() || el.textContent.split(' ')[0];
        const input   = document.querySelector(`.tv-field[data-field="channel_name"][data-guild-id="${guildId}"]`);
        if (!input) return;
        const start = input.selectionStart ?? input.value.length;
        const end   = input.selectionEnd   ?? input.value.length;
        input.value = input.value.slice(0, start) + varText + input.value.slice(end);
        input.focus();
        input.setSelectionRange(start + varText.length, start + varText.length);
    }
    window.tvInsertVar = tvInsertVar;

    // ── Dropdown helpers ──────────────────────────────────────────────────────
    function closeAllDropdowns() {
        document.querySelectorAll('.tv-dropdown.is-open').forEach((d) => d.classList.remove('is-open'));
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.tv-ch-wrap')) closeAllDropdowns();
    });

    // Icon SVGs per channel type
    const ICONS = {
        voice:    '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M3 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H3Zm0 1h10v10H3V3Zm2 2v6h1V5H5Zm5 0v6h-1V5h1ZM7 6v4h2V6H7Z"/></svg>',
        category: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M1 3.5A1.5 1.5 0 0 1 2.5 2h2.764c.958 0 1.76.56 2.311 1.184C7.985 3.648 8.48 4 9 4h4.5A1.5 1.5 0 0 1 15 5.5v7a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 1 12.5v-9z"/></svg>',
    };

    function buildDropdownItems(kind, guildId, channels, currentId) {
        const listEl = document.getElementById(`tv-dd-${kind}-list-${guildId}`);
        if (!listEl) return;

        if (channels.length === 0) {
            listEl.innerHTML = `<div class="tv-dropdown-empty">Keine ${kind === 'trigger' ? 'Voice Channels' : 'Kategorien'} gefunden.</div>`;
            return;
        }

        listEl.innerHTML = '';
        for (const ch of channels) {
            const item = document.createElement('div');
            item.className = 'tv-dropdown-item' + (ch.id === currentId ? ' is-selected' : '');
            item.dataset.id   = ch.id;
            item.dataset.name = ch.name;
            item.innerHTML = `
                <span class="tv-dropdown-item__icon">${ICONS[kind === 'trigger' ? 'voice' : 'category']}</span>
                <span class="tv-dropdown-item__name">${escHtml(ch.name)}</span>
                <span class="tv-dropdown-item__id">${ch.id}</span>`;
            item.addEventListener('click', () => tvSelectChannel(kind, guildId, ch.id, ch.name));
            listEl.appendChild(item);
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Open picker ───────────────────────────────────────────────────────────
    async function tvOpenPicker(kind, guildId) {
        const dd  = document.getElementById(`tv-dd-${kind}-${guildId}`);
        if (!dd) return;

        // Toggle
        if (dd.classList.contains('is-open')) {
            dd.classList.remove('is-open');
            return;
        }
        closeAllDropdowns();
        dd.classList.add('is-open');

        // Clear search input
        const searchIn = dd.querySelector('input[type=text]');
        if (searchIn) searchIn.value = '';

        const cacheKey  = `${kind}:${guildId}`;
        const apiTypes  = kind === 'trigger' ? '2' : '4'; // voice=2, category=4
        const currentId = document.getElementById(`tv-${kind}-input-${guildId}`)?.value || '';

        // Use cache if available
        if (channelCache.has(cacheKey)) {
            buildDropdownItems(kind, guildId, channelCache.get(cacheKey), currentId);
            return;
        }

        // Show loading
        const listEl = document.getElementById(`tv-dd-${kind}-list-${guildId}`);
        if (listEl) listEl.innerHTML = '<div class="tv-dropdown-empty">Lade…</div>';

        // Find the button to show loading state
        const wrap = document.getElementById(`tv-wrap-${kind}-${guildId}`);
        const btn  = wrap?.querySelector('.tv-pick-btn');
        if (btn) btn.classList.add('is-loading');

        try {
            const url = `${API_BASE}?bot_id=${BOT_ID}&guild_id=${encodeURIComponent(guildId)}&types=${apiTypes}`;
            const res  = await fetch(url);
            const json = await res.json();

            if (!json.ok) {
                if (listEl) listEl.innerHTML = `<div class="tv-dropdown-error">Fehler: ${escHtml(json.error || 'Unbekannt')}</div>`;
                return;
            }

            const channels = Array.isArray(json.channels) ? json.channels : [];
            channelCache.set(cacheKey, channels);
            buildDropdownItems(kind, guildId, channels, currentId);
        } catch (e) {
            if (listEl) listEl.innerHTML = `<div class="tv-dropdown-error">Netzwerkfehler: ${escHtml(e.message)}</div>`;
        } finally {
            if (btn) btn.classList.remove('is-loading');
        }
    }
    window.tvOpenPicker = tvOpenPicker;

    // ── Filter dropdown ───────────────────────────────────────────────────────
    function tvFilterDropdown(kind, guildId, query) {
        const listEl   = document.getElementById(`tv-dd-${kind}-list-${guildId}`);
        const cacheKey = `${kind}:${guildId}`;
        if (!listEl || !channelCache.has(cacheKey)) return;

        const q  = query.toLowerCase().trim();
        const all = channelCache.get(cacheKey);
        const filtered = q === '' ? all : all.filter((c) => c.name.toLowerCase().includes(q) || c.id.includes(q));

        const currentId = document.getElementById(`tv-${kind}-input-${guildId}`)?.value || '';
        buildDropdownItems(kind, guildId, filtered, currentId);
    }
    window.tvFilterDropdown = tvFilterDropdown;

    // ── Select a channel ──────────────────────────────────────────────────────
    function tvSelectChannel(kind, guildId, channelId, channelName) {
        // Set input value
        const input = document.getElementById(`tv-${kind}-input-${guildId}`);
        if (input) input.value = channelId;

        // Update selected name display
        const selEl = document.getElementById(`tv-${kind}-sel-${guildId}`);
        if (selEl) {
            selEl.style.display = 'inline-flex';
            const span = selEl.querySelector('span');
            if (span) span.textContent = `🔊 ${channelName}  (${channelId})`;
        }

        // Close dropdown
        const dd = document.getElementById(`tv-dd-${kind}-${guildId}`);
        if (dd) dd.classList.remove('is-open');

        // Highlight selected in list
        const listEl = document.getElementById(`tv-dd-${kind}-list-${guildId}`);
        if (listEl) {
            listEl.querySelectorAll('.tv-dropdown-item').forEach((item) => {
                item.classList.toggle('is-selected', item.dataset.id === channelId);
            });
        }
    }

    // ── Feedback ──────────────────────────────────────────────────────────────
    function showSaveMsg(guildId, text, ok) {
        const el = document.querySelector(`.tv-save-msg[data-guild-id="${guildId}"]`);
        if (!el) return;
        el.textContent = text;
        el.style.color   = ok ? '#4caf7d' : '#f87171';
        el.style.display = 'inline';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 3000);
    }

    function updateStatus(guildId, enabled) {
        const el = document.getElementById(`tv-status-${guildId}`);
        if (!el) return;
        el.className = `tv-status ${enabled ? 'tv-status--on' : 'tv-status--off'}`;
        el.innerHTML = `<span class="tv-status__dot"></span>${enabled ? 'Aktiv' : 'Inaktiv'}`;
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    async function tvSave(guildId) {
        const btn = document.querySelector(`.tv-save-btn[data-guild-id="${guildId}"]`);
        if (btn) btn.disabled = true;

        const fields = {};
        document.querySelectorAll(`.tv-field[data-guild-id="${guildId}"]`).forEach((el) => {
            fields[el.dataset.field] = el.tagName === 'SELECT' ? el.options[el.selectedIndex].value : el.value;
        });
        const enabledToggle = document.querySelector(`.tv-enabled-toggle[data-guild-id="${guildId}"]`);
        const isEnabled     = enabledToggle ? enabledToggle.checked : false;

        const body = new URLSearchParams({
            tv_action:          'save',
            guild_id:           guildId,
            trigger_channel_id: fields['trigger_channel_id'] || '',
            category_id:        fields['category_id']        || '',
            channel_name:       fields['channel_name']       || 'Temp #{n}',
            user_limit:         fields['user_limit']         || '0',
            bitrate:            fields['bitrate']            || '64000',
        });
        if (isEnabled) body.set('is_enabled', '1');

        try {
            const res  = await fetch(window.location.href, { method: 'POST', body });
            const json = await res.json();
            if (json.ok) {
                showSaveMsg(guildId, '✓ Gespeichert', true);
                updateStatus(guildId, isEnabled && (fields['trigger_channel_id'] || '').trim() !== '');
                // Invalidate channel cache so next open re-fetches
                channelCache.delete(`trigger:${guildId}`);
                channelCache.delete(`category:${guildId}`);
            } else {
                showSaveMsg(guildId, json.error || 'Fehler beim Speichern', false);
            }
        } catch (_) {
            showSaveMsg(guildId, 'Netzwerkfehler', false);
        } finally {
            if (btn) btn.disabled = false;
        }
    }
    window.tvSave = tvSave;

    // Auto-save when toggle changes
    document.querySelectorAll('.tv-enabled-toggle').forEach((t) =>
        t.addEventListener('change', () => tvSave(t.dataset.guildId))
    );
}());
</script>
