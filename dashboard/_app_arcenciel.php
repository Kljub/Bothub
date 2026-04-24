<?php
declare(strict_types=1);

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<div class="bh-alert bh-alert--err">Kein Bot ausgewählt.</div>';
    return;
}

$botId = (int)$currentBotId;

require_once __DIR__ . '/../functions/custom_commands.php';
$pdo = bh_cc_get_pdo();

// ── Auto-migrate ───────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_arcenciel_settings` (
        `bot_id`              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        `api_key`             TEXT            NULL DEFAULT NULL,
        `is_enabled`          TINYINT(1)      NOT NULL DEFAULT 1,
        `default_checkpoint`  VARCHAR(200)    NOT NULL DEFAULT '',
        `default_vae`         VARCHAR(200)    NOT NULL DEFAULT '',
        `default_neg_prompt`  TEXT            NULL DEFAULT NULL,
        `default_width`       SMALLINT UNSIGNED NOT NULL DEFAULT 512,
        `default_height`      SMALLINT UNSIGNED NOT NULL DEFAULT 512,
        `default_steps`       TINYINT UNSIGNED  NOT NULL DEFAULT 20,
        `default_cfg`         DECIMAL(4,2)    NOT NULL DEFAULT 7.00,
        `default_sampler`     VARCHAR(100)    NOT NULL DEFAULT '',
        `default_scheduler`   VARCHAR(100)    NOT NULL DEFAULT '',
        `nsfw_enabled`        TINYINT(1)      NOT NULL DEFAULT 0,
        `quota_per_hour`      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* table already exists */ }

// ── POST handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        $apiKey      = substr(trim($_POST['api_key'] ?? ''), 0, 2000);
        $isEnabled   = (int)(bool)($_POST['is_enabled'] ?? 1);
        $checkpoint  = substr(trim($_POST['default_checkpoint'] ?? ''), 0, 200);
        $vae         = substr(trim($_POST['default_vae'] ?? ''), 0, 200);
        $negPrompt   = substr(trim($_POST['default_neg_prompt'] ?? ''), 0, 1000);
        $width       = max(64, min(2048, (int)($_POST['default_width']  ?? 512)));
        $height      = max(64, min(2048, (int)($_POST['default_height'] ?? 512)));
        $steps       = max(1,  min(150,  (int)($_POST['default_steps']  ?? 20)));
        $cfg         = max(1.0, min(30.0, (float)($_POST['default_cfg'] ?? 7.0)));
        $sampler     = substr(trim($_POST['default_sampler']   ?? ''), 0, 100);
        $scheduler   = substr(trim($_POST['default_scheduler'] ?? ''), 0, 100);
        $nsfw        = (int)(bool)($_POST['nsfw_enabled'] ?? 0);
        $quota       = max(1, min(500, (int)($_POST['quota_per_hour'] ?? 10)));

        try {
            $pdo->prepare(
                "INSERT INTO bot_arcenciel_settings
                    (bot_id, api_key, is_enabled, default_checkpoint, default_vae, default_neg_prompt,
                     default_width, default_height, default_steps, default_cfg,
                     default_sampler, default_scheduler, nsfw_enabled, quota_per_hour)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     api_key            = VALUES(api_key),
                     is_enabled         = VALUES(is_enabled),
                     default_checkpoint = VALUES(default_checkpoint),
                     default_vae        = VALUES(default_vae),
                     default_neg_prompt = VALUES(default_neg_prompt),
                     default_width      = VALUES(default_width),
                     default_height     = VALUES(default_height),
                     default_steps      = VALUES(default_steps),
                     default_cfg        = VALUES(default_cfg),
                     default_sampler    = VALUES(default_sampler),
                     default_scheduler  = VALUES(default_scheduler),
                     nsfw_enabled       = VALUES(nsfw_enabled),
                     quota_per_hour     = VALUES(quota_per_hour)"
            )->execute([
                $botId, $apiKey ?: null, $isEnabled, $checkpoint, $vae, $negPrompt ?: null,
                $width, $height, $steps, $cfg, $sampler, $scheduler, $nsfw, $quota,
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'toggle_command') {
        $cmdKey  = preg_replace('/[^a-z0-9_\-]/', '', $_POST['command_key'] ?? '');
        $enabled = (int)(bool)($_POST['enabled'] ?? 0);
        try {
            $pdo->prepare(
                "INSERT INTO commands (bot_id, command_key, is_enabled)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
            )->execute([$botId, $cmdKey, $enabled]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load data ──────────────────────────────────────────────────────────────────
$s = $pdo->prepare("SELECT * FROM bot_arcenciel_settings WHERE bot_id = ? LIMIT 1");
$s->execute([$botId]);
$settings = $s->fetch(PDO::FETCH_ASSOC) ?: [
    'api_key' => '', 'is_enabled' => 1, 'default_checkpoint' => '', 'default_vae' => '',
    'default_neg_prompt' => '', 'default_width' => 512, 'default_height' => 512,
    'default_steps' => 20, 'default_cfg' => 7.0, 'default_sampler' => '',
    'default_scheduler' => '', 'nsfw_enabled' => 0, 'quota_per_hour' => 10,
];

// Ensure command rows exist (preserved = don't overwrite is_enabled)
foreach (['imagine', 'img2img', 'autotag'] as $cmdKey) {
    $pdo->prepare(
        "INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled)
         VALUES (?, ?, 'predefined', ?, ?, 1)"
    )->execute([$botId, $cmdKey, "/$cmdKey", match($cmdKey) {
        'imagine' => 'Generiere ein Bild mit Arc en Ciel AI',
        'img2img' => 'Bildgenerierung basierend auf einem Eingabebild',
        'autotag' => 'Analysiert ein Bild und gibt Tags zurück',
    }]);
}

$s = $pdo->prepare("SELECT command_key, is_enabled FROM commands WHERE bot_id = ? AND command_key IN ('imagine','img2img','autotag')");
$s->execute([$botId]);
$cmdMap = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $cmdMap[$r['command_key']] = (bool)$r['is_enabled']; }

function ace_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$aceHasKey    = !empty($settings['api_key']);
$aceEnabled   = !empty($settings['is_enabled']);
$aceNsfw      = !empty($settings['nsfw_enabled']);
$aceWidth     = (int)($settings['default_width']  ?? 512);
$aceHeight    = (int)($settings['default_height'] ?? 512);
$aceSteps     = (int)($settings['default_steps']  ?? 20);
$aceCfg       = number_format((float)($settings['default_cfg'] ?? 7.0), 1);
$aceSampler   = ace_e((string)($settings['default_sampler']   ?? ''));
$aceScheduler = ace_e((string)($settings['default_scheduler'] ?? ''));
$aceQuota     = (int)($settings['quota_per_hour'] ?? 10);
$enabledCmds  = count(array_filter($cmdMap));
?>

<style>
/* ── Arc en Ciel — page-scoped styles ───────────────────────────────────────── */

.ace-hero {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    padding: 28px 28px 24px;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    color: #fff;
}
.ace-hero__bg {
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 1;
}
.ace-hero__inner { position: relative; display: flex; align-items: flex-start; gap: 18px; }
.ace-hero__icon {
    flex-shrink: 0;
    width: 52px; height: 52px;
    background: rgba(255,255,255,.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.ace-hero__icon svg { width: 28px; height: 28px; fill: #fff; }
.ace-hero__text { flex: 1; min-width: 0; }
.ace-hero__kicker { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; opacity: .75; margin-bottom: 4px; }
.ace-hero__title { font-size: 22px; font-weight: 700; margin: 0 0 6px; }
.ace-hero__sub { font-size: 13px; opacity: .82; line-height: 1.5; margin: 0; }
.ace-hero__sub a { color: #fff; text-decoration: underline; text-underline-offset: 2px; }
.ace-hero__badge {
    flex-shrink: 0; align-self: flex-start;
    padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600;
    border: 1.5px solid rgba(255,255,255,.4);
    backdrop-filter: blur(4px);
}
.ace-hero__badge--on  { background: rgba(255,255,255,.18); color: #fff; }
.ace-hero__badge--off { background: rgba(0,0,0,.18); color: rgba(255,255,255,.65); }

.ace-key-row {
    display: flex; align-items: center; gap: 8px;
}
.ace-key-row .bh-input { flex: 1; font-family: monospace; font-size: 13px; }
.ace-key-toggle {
    flex-shrink: 0;
    background: none; border: 1px solid var(--bh-border, #2d3346);
    border-radius: 6px; padding: 0 8px; height: 38px; cursor: pointer;
    color: var(--bh-text-muted, #8b949e);
    display: flex; align-items: center; justify-content: center;
    transition: border-color .15s, color .15s;
}
.ace-key-toggle:hover { border-color: #6366f1; color: #6366f1; }
.ace-key-toggle svg { width: 16px; height: 16px; fill: currentColor; }

.ace-dimension-preview {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin-top: 8px;
    font-size: 12px; color: var(--bh-text-muted, #8b949e);
}
.ace-dimension-preview span { font-weight: 600; color: var(--bh-text, #e6edf3); }
</style>

<div class="lv-page">

    <!-- Hero Header -->
    <div class="ace-hero">
        <div class="ace-hero__bg"></div>
        <div class="ace-hero__inner">
            <div class="ace-hero__icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                    <path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm0 2a8 8 0 1 1 0 16A8 8 0 0 1 12 4zm-1 4v2H9v2h2v2H9v2h2v2h2v-2h2v-2h-2v-2h2V8h-2V6h-2v2z"/>
                </svg>
            </div>
            <div class="ace-hero__text">
                <div class="ace-hero__kicker">Bildgenerierung · KI</div>
                <h1 class="ace-hero__title">Arc en Ciel</h1>
                <p class="ace-hero__sub">
                    Stable Diffusion Bildgenerierung via
                    <a href="https://arcenciel.io" target="_blank" rel="noopener">arcenciel.io</a>
                    — /imagine, /img2img und /autotag direkt in Discord.
                </p>
            </div>
            <div class="ace-hero__badge <?= ($aceEnabled && $aceHasKey) ? 'ace-hero__badge--on' : 'ace-hero__badge--off' ?>">
                <?= ($aceEnabled && $aceHasKey) ? '● Aktiv' : '○ Inaktiv' ?>
            </div>
        </div>
    </div>

    <!-- Stats bar -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $aceWidth ?> × <?= $aceHeight ?></div>
            <div class="lv-stat__lbl">Standard-Auflösung</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $aceSteps ?></div>
            <div class="lv-stat__lbl">Schritte</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $aceCfg ?></div>
            <div class="lv-stat__lbl">CFG Scale</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $aceQuota ?></div>
            <div class="lv-stat__lbl">Bilder / Stunde</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $enabledCmds ?> / 3</div>
            <div class="lv-stat__lbl">Commands aktiv</div>
        </div>
    </div>

    <!-- ── Verbindung ────────────────────────────────────────────────────────── -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ace-api-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Konfiguration</div>
                <div class="bh-card-title">Verbindung & API-Schlüssel</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ace-api-body">

            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Dienst aktiviert</div>
                    <div class="lv-feature__desc">Schaltet Arc en Ciel für diesen Bot ein oder aus. Alle Commands bleiben registriert.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="ace-is-enabled" <?= $aceEnabled ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="bh-field" style="margin-top:16px">
                <label class="bh-label" for="ace-api-key">
                    API-Schlüssel
                    <?php if ($aceHasKey): ?>
                        <span style="margin-left:6px;font-size:11px;font-weight:600;color:#10b981;background:rgba(16,185,129,.1);padding:1px 7px;border-radius:99px">● Gesetzt</span>
                    <?php else: ?>
                        <span style="margin-left:6px;font-size:11px;font-weight:600;color:#f59e0b;background:rgba(245,158,11,.1);padding:1px 7px;border-radius:99px">○ Nicht gesetzt</span>
                    <?php endif; ?>
                </label>
                <div class="ace-key-row">
                    <input type="password" id="ace-api-key" class="bh-input"
                        value="<?= ace_e((string)($settings['api_key'] ?? '')) ?>"
                        placeholder="ace_xxxxxxxxxxxxxxxxxxxxxxxx"
                        autocomplete="off">
                    <button type="button" class="ace-key-toggle" id="ace-key-show" title="Anzeigen / Verbergen">
                        <svg id="ace-eye-icon" viewBox="0 0 20 20"><path d="M10 3C5 3 1.73 7.11 1.08 8.27a1 1 0 0 0 0 .96C1.73 10.38 5 15 10 15s8.27-4.62 8.92-5.77a1 1 0 0 0 0-.96C18.27 7.11 15 3 10 3zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                    </button>
                </div>
                <div class="bh-hint">
                    API Key aus dem
                    <a href="https://arcenciel.io/dashboard" target="_blank" rel="noopener">Arc en Ciel Dashboard</a>.
                    Leer lassen um den gespeicherten Schlüssel beizubehalten.
                </div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="ace-save-api">Speichern</button>
                <span class="lv-save-msg" id="ace-api-msg"></span>
            </div>
        </div>
    </div>

    <!-- ── Standard-Einstellungen ────────────────────────────────────────────── -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ace-gen-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Generierung</div>
                <div class="bh-card-title">Standard-Parameter</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ace-gen-body">

            <div class="bh-field">
                <label class="bh-label" for="ace-checkpoint">
                    Checkpoint / Modell
                    <span style="font-weight:400;color:var(--bh-text-muted)">— optional</span>
                </label>
                <input type="text" id="ace-checkpoint" class="bh-input"
                    value="<?= ace_e((string)($settings['default_checkpoint'] ?? '')) ?>"
                    placeholder="z.B. dreamshaper_8.safetensors">
                <div class="bh-hint">Name des Checkpoints wie er in Arc en Ciel erscheint. Leer = Server-Standard.</div>
            </div>

            <div class="bh-field">
                <label class="bh-label" for="ace-vae">
                    VAE
                    <span style="font-weight:400;color:var(--bh-text-muted)">— optional</span>
                </label>
                <input type="text" id="ace-vae" class="bh-input"
                    value="<?= ace_e((string)($settings['default_vae'] ?? '')) ?>"
                    placeholder="z.B. vae-ft-mse-840000-ema-pruned.ckpt">
            </div>

            <div class="bh-field">
                <label class="bh-label" for="ace-neg-prompt">
                    Negativ-Prompt
                    <span style="font-weight:400;color:var(--bh-text-muted)">— optional</span>
                </label>
                <textarea id="ace-neg-prompt" class="bh-input" rows="2" style="resize:vertical"
                    placeholder="lowres, bad anatomy, bad hands, text, error, missing fingers…"><?= ace_e((string)($settings['default_neg_prompt'] ?? '')) ?></textarea>
                <div class="bh-hint">Fallback wenn der Nutzer keinen eigenen Negativ-Prompt angibt.</div>
            </div>

            <!-- Resolution -->
            <div class="bh-field">
                <label class="bh-label">Auflösung</label>
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="ace-width" style="font-size:11px;font-weight:500;margin-bottom:4px">Breite (px)</label>
                        <input type="number" id="ace-width" class="bh-input" value="<?= $aceWidth ?>" min="64" max="2048" step="64">
                    </div>
                    <div>
                        <label class="bh-label" for="ace-height" style="font-size:11px;font-weight:500;margin-bottom:4px">Höhe (px)</label>
                        <input type="number" id="ace-height" class="bh-input" value="<?= $aceHeight ?>" min="64" max="2048" step="64">
                    </div>
                </div>
                <div class="ace-dimension-preview" id="ace-dim-preview">
                    <span id="ace-dim-w"><?= $aceWidth ?></span>
                    ×
                    <span id="ace-dim-h"><?= $aceHeight ?></span>
                    px
                </div>
            </div>

            <!-- Steps + CFG -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="ace-steps">Schritte</label>
                        <input type="number" id="ace-steps" class="bh-input" value="<?= $aceSteps ?>" min="1" max="150">
                        <div class="bh-hint">Standard: 20 · Empfehlung: 20–30</div>
                    </div>
                    <div>
                        <label class="bh-label" for="ace-cfg">CFG Scale</label>
                        <input type="number" id="ace-cfg" class="bh-input" value="<?= $aceCfg ?>" min="1" max="30" step="0.5">
                        <div class="bh-hint">Standard: 7 · Höher = prompttreuer</div>
                    </div>
                </div>
            </div>

            <!-- Sampler + Scheduler -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="ace-sampler">Sampler</label>
                        <input type="text" id="ace-sampler" class="bh-input"
                            value="<?= ace_e($aceSampler) ?>" placeholder="Euler a">
                    </div>
                    <div>
                        <label class="bh-label" for="ace-scheduler">Scheduler</label>
                        <input type="text" id="ace-scheduler" class="bh-input"
                            value="<?= ace_e($aceScheduler) ?>" placeholder="Automatic">
                    </div>
                </div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="ace-save-gen">Speichern</button>
                <span class="lv-save-msg" id="ace-gen-msg"></span>
            </div>
        </div>
    </div>

    <!-- ── Sicherheit & Limits ───────────────────────────────────────────────── -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ace-limits-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Sicherheit</div>
                <div class="bh-card-title">Content & Nutzungslimits</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ace-limits-body">

            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">NSFW-Inhalte erlauben</div>
                    <div class="lv-feature__desc">
                        Sensitive und explizite Bilder werden als
                        <code style="font-size:11px;background:var(--bh-code-bg,#2e3850);padding:1px 5px;border-radius:4px">SPOILER_</code>
                        gesendet. Nur in Age-Restricted Channels verwenden.
                    </div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="ace-nsfw" <?= $aceNsfw ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="bh-field" style="margin-top:20px">
                <label class="bh-label" for="ace-quota">Bilder pro Nutzer / Stunde</label>
                <div style="display:flex;align-items:center;gap:12px">
                    <input type="number" id="ace-quota" class="bh-input" style="max-width:120px"
                        value="<?= $aceQuota ?>" min="1" max="500">
                    <span style="font-size:13px;color:var(--bh-text-muted)">Generierungen / User / Stunden-Fenster</span>
                </div>
                <div class="bh-hint">Schützt vor Spam. Zähler wird stündlich zurückgesetzt. Empfehlung: 5–20.</div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="ace-save-limits">Speichern</button>
                <span class="lv-save-msg" id="ace-limits-msg"></span>
            </div>
        </div>
    </div>

    <!-- ── Slash-Commands ────────────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ace-cmd-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Commands</div>
                <div class="bh-card-title">Slash-Commands</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ace-cmd-body">
            <div class="bh-cmd-grid" style="padding:16px 20px">
                <?php
                $cmds = [
                    ['key' => 'imagine', 'name' => '/imagine', 'icon' => '🎨',
                     'desc' => 'Text-to-Image — Generiert ein Bild aus einem Prompt.'],
                    ['key' => 'img2img', 'name' => '/img2img',  'icon' => '🖼️',
                     'desc' => 'Image-to-Image — Bild auf Basis eines hochgeladenen Fotos generieren.'],
                    ['key' => 'autotag', 'name' => '/autotag',  'icon' => '🏷️',
                     'desc' => 'Tagger — Analysiert ein Bild und gibt passende Tags zurück.'],
                ];
                foreach ($cmds as $cmd): ?>
                <div class="bh-cmd-card">
                    <div style="display:flex;align-items:flex-start;gap:10px">
                        <span style="font-size:20px;line-height:1"><?= $cmd['icon'] ?></span>
                        <div>
                            <div class="bh-cmd-name"><?= htmlspecialchars($cmd['name']) ?></div>
                            <div class="bh-cmd-desc"><?= htmlspecialchars($cmd['desc']) ?></div>
                        </div>
                    </div>
                    <label class="bh-toggle">
                        <input type="checkbox" class="ace-cmd-toggle bh-toggle-input" data-key="<?= $cmd['key'] ?>"
                            <?= ($cmdMap[$cmd['key']] ?? true) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    // Collapsible cards
    document.querySelectorAll('.lv-toggle-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const target = document.getElementById(hdr.dataset.target);
            if (!target) return;
            const collapsed = target.classList.toggle('lv-collapsed');
            hdr.querySelector('.lv-chevron')?.classList.toggle('lv-chevron--open', !collapsed);
        });
    });

    // Show/hide API key
    const keyInput = document.getElementById('ace-api-key');
    document.getElementById('ace-key-show').addEventListener('click', () => {
        keyInput.type = keyInput.type === 'password' ? 'text' : 'password';
    });

    // Live dimension preview
    const wIn = document.getElementById('ace-width');
    const hIn = document.getElementById('ace-height');
    const wLbl = document.getElementById('ace-dim-w');
    const hLbl = document.getElementById('ace-dim-h');
    function updateDim() {
        wLbl.textContent = wIn.value;
        hLbl.textContent = hIn.value;
    }
    wIn.addEventListener('input', updateDim);
    hIn.addEventListener('input', updateDim);

    function post(data) {
        return fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ...data, bot_id: '<?= $botId ?>' }).toString(),
        }).then(r => r.json());
    }

    function showMsg(el, ok, text) {
        el.textContent = text;
        el.className = 'lv-save-msg ' + (ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        setTimeout(() => { el.textContent = ''; el.className = 'lv-save-msg'; }, 3000);
    }

    function collectSettings() {
        return {
            action:             'save_settings',
            api_key:            document.getElementById('ace-api-key').value,
            is_enabled:         document.getElementById('ace-is-enabled').checked ? 1 : 0,
            default_checkpoint: document.getElementById('ace-checkpoint').value,
            default_vae:        document.getElementById('ace-vae').value,
            default_neg_prompt: document.getElementById('ace-neg-prompt').value,
            default_width:      document.getElementById('ace-width').value,
            default_height:     document.getElementById('ace-height').value,
            default_steps:      document.getElementById('ace-steps').value,
            default_cfg:        document.getElementById('ace-cfg').value,
            default_sampler:    document.getElementById('ace-sampler').value,
            default_scheduler:  document.getElementById('ace-scheduler').value,
            nsfw_enabled:       document.getElementById('ace-nsfw').checked ? 1 : 0,
            quota_per_hour:     document.getElementById('ace-quota').value,
        };
    }

    document.getElementById('ace-save-api').addEventListener('click', () => {
        post(collectSettings()).then(r => showMsg(document.getElementById('ace-api-msg'), r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });
    document.getElementById('ace-save-gen').addEventListener('click', () => {
        post(collectSettings()).then(r => showMsg(document.getElementById('ace-gen-msg'), r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });
    document.getElementById('ace-save-limits').addEventListener('click', () => {
        post(collectSettings()).then(r => showMsg(document.getElementById('ace-limits-msg'), r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });

    document.querySelectorAll('.ace-cmd-toggle').forEach(chk => {
        chk.addEventListener('change', function () {
            post({ action: 'toggle_command', command_key: this.dataset.key, enabled: this.checked ? 1 : 0 });
        });
    });
})();
</script>
