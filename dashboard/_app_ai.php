<?php
declare(strict_types=1);

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<div class="bh-alert bh-alert--err">Kein Bot ausgewählt.</div>';
    return;
}

$botId = (int)$currentBotId;

require_once __DIR__ . '/../functions/custom_commands.php';
require_once __DIR__ . '/../functions/module_toggle.php';
$pdo = bh_cc_get_pdo();

// ── Auto-migrate ───────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_ai_settings` (
        `bot_id`              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        `active_provider`     VARCHAR(50)  NOT NULL DEFAULT 'openai',
        `system_prompt`       TEXT         NULL DEFAULT NULL,
        `max_tokens`          INT          NOT NULL DEFAULT 1000,
        `temperature`         DECIMAL(3,2) NOT NULL DEFAULT 0.70,
        `history_length`      INT          NOT NULL DEFAULT 10,
        `session_timeout_min` INT          NOT NULL DEFAULT 30,
        `web_search_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
        `brave_api_key`       TEXT         NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Add columns for existing tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_ai_allowed_channels` (
        `bot_id`     BIGINT UNSIGNED NOT NULL,
        `channel_id` VARCHAR(20)     NOT NULL,
        PRIMARY KEY (`bot_id`, `channel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `history_length`           INT          NOT NULL DEFAULT 10",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `session_timeout_min`      INT          NOT NULL DEFAULT 30",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `web_search_enabled`       TINYINT(1)   NOT NULL DEFAULT 0",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `brave_api_key`            TEXT         NULL DEFAULT NULL",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `searxng_url`              VARCHAR(500) NULL DEFAULT NULL",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `mention_enabled`          TINYINT(1)   NOT NULL DEFAULT 0",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `mention_context_messages` INT          NOT NULL DEFAULT 10",
        "ALTER TABLE `bot_ai_settings` ADD COLUMN `web_search_always`        TINYINT(1)   NOT NULL DEFAULT 0",
    ] as $alterSql) {
        try { $pdo->exec($alterSql); } catch (Throwable $ignored) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_ai_providers` (
        `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `bot_id`         BIGINT UNSIGNED NOT NULL,
        `provider`       VARCHAR(50) NOT NULL,
        `api_key`        TEXT NULL DEFAULT NULL,
        `base_url`       VARCHAR(500) NULL DEFAULT NULL,
        `selected_model` VARCHAR(255) NULL DEFAULT NULL,
        UNIQUE KEY `uq_bot_provider` (`bot_id`, `provider`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* tables already exist */ }

// ── Handle AJAX ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        $provider       = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_POST['active_provider'] ?? 'openai'));
        $systemPrompt   = substr(trim($_POST['system_prompt'] ?? ''), 0, 2000);
        $maxTokens      = max(1, min(8000, (int)($_POST['max_tokens'] ?? 1000)));
        $temperature    = max(0.0, min(2.0, (float)($_POST['temperature'] ?? 0.7)));
        $historyLength  = max(1, min(50, (int)($_POST['history_length'] ?? 10)));
        $sessionTimeout = max(1, min(1440, (int)($_POST['session_timeout_min'] ?? 30)));
        $webEnabled     = (int)(bool)($_POST['web_search_enabled'] ?? 0);
        $webAlways      = (int)(bool)($_POST['web_search_always'] ?? 0);
        $braveKey       = substr(trim($_POST['brave_api_key'] ?? ''), 0, 500);
        $searxngUrl     = substr(trim($_POST['searxng_url'] ?? ''), 0, 500);

        try {
            $s = $pdo->prepare(
                "INSERT INTO bot_ai_settings
                    (bot_id, active_provider, system_prompt, max_tokens, temperature,
                     history_length, session_timeout_min, web_search_enabled, web_search_always, brave_api_key, searxng_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     active_provider     = VALUES(active_provider),
                     system_prompt       = VALUES(system_prompt),
                     max_tokens          = VALUES(max_tokens),
                     temperature         = VALUES(temperature),
                     history_length      = VALUES(history_length),
                     session_timeout_min = VALUES(session_timeout_min),
                     web_search_enabled  = VALUES(web_search_enabled),
                     web_search_always   = VALUES(web_search_always),
                     brave_api_key       = VALUES(brave_api_key),
                     searxng_url         = VALUES(searxng_url)"
            );
            $s->execute([$botId, $provider, $systemPrompt ?: null, $maxTokens, $temperature,
                         $historyLength, $sessionTimeout, $webEnabled, $webAlways, $braveKey ?: null, $searxngUrl ?: null]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_provider') {
        $provider = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_POST['provider'] ?? ''));
        $apiKey   = substr(trim($_POST['api_key'] ?? ''), 0, 2000);
        $baseUrl  = substr(trim($_POST['base_url'] ?? ''), 0, 500);
        $model    = substr(trim($_POST['selected_model'] ?? ''), 0, 255);

        if ($provider === '') {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Provider.']);
            exit;
        }

        try {
            $s = $pdo->prepare(
                "INSERT INTO bot_ai_providers (bot_id, provider, api_key, base_url, selected_model)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     api_key        = VALUES(api_key),
                     base_url       = VALUES(base_url),
                     selected_model = VALUES(selected_model)"
            );
            $s->execute([$botId, $provider, $apiKey ?: null, $baseUrl ?: null, $model ?: null]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'fetch_ollama_models') {
        $baseUrl = trim($_POST['base_url'] ?? 'http://localhost:11434');
        $baseUrl = rtrim($baseUrl, '/');

        // SECURITY: Allow only localhost or local IP for Ollama by default
        $parsed = parse_url($baseUrl);
        if (!isset($parsed['host']) || !in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'])) {
            echo json_encode(['ok' => false, 'error' => 'Nur lokale Ollama-Instanzen sind aus Sicherheitsgründen erlaubt.']);
            exit;
        }

        $tagsUrl = $baseUrl . '/api/tags';

        $ch = curl_init($tagsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $curlErr !== '') {
            echo json_encode(['ok' => false, 'error' => 'Ollama nicht erreichbar (' . htmlspecialchars($curlErr ?: 'curl fehler') . ')']);
            exit;
        }
        if ($httpCode !== 200) {
            echo json_encode(['ok' => false, 'error' => "Ollama antwortete mit HTTP {$httpCode}"]);
            exit;
        }
        $json = json_decode($raw, true);
        $models = array_column($json['models'] ?? [], 'name');
        echo json_encode(['ok' => true, 'models' => $models]);
        exit;
    }

    if ($action === 'save_mention_settings') {
        $mentionEnabled = (int)(bool)($_POST['mention_enabled'] ?? 0);
        $contextMsgs    = max(0, min(50, (int)($_POST['mention_context_messages'] ?? 10)));
        $channels       = array_filter(array_map('trim', explode(',', $_POST['allowed_channels'] ?? '')));
        $channels       = array_values(array_filter($channels, fn($c) => preg_match('/^\d{17,20}$/', $c)));

        try {
            $pdo->prepare(
                "INSERT INTO bot_ai_settings (bot_id, mention_enabled, mention_context_messages)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     mention_enabled          = VALUES(mention_enabled),
                     mention_context_messages = VALUES(mention_context_messages)"
            )->execute([$botId, $mentionEnabled, $contextMsgs]);

            // Replace allowed channels
            $pdo->prepare("DELETE FROM bot_ai_allowed_channels WHERE bot_id = ?")->execute([$botId]);
            if (!empty($channels)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO bot_ai_allowed_channels (bot_id, channel_id) VALUES (?, ?)");
                foreach ($channels as $ch) { $ins->execute([$botId, $ch]); }
            }
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
            $s = $pdo->prepare(
                "INSERT INTO commands (bot_id, command_key, is_enabled)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
            );
            $s->execute([$botId, $cmdKey, $enabled]);
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
$s = $pdo->prepare("SELECT * FROM bot_ai_settings WHERE bot_id = ? LIMIT 1");
$s->execute([$botId]);
$settings = $s->fetch(PDO::FETCH_ASSOC) ?: [
    'active_provider' => 'openai', 'system_prompt' => '', 'max_tokens' => 1000, 'temperature' => 0.70,
    'history_length' => 10, 'session_timeout_min' => 30, 'web_search_enabled' => 0, 'brave_api_key' => '',
];

$s = $pdo->prepare("SELECT * FROM bot_ai_providers WHERE bot_id = ?");
$s->execute([$botId]);
$providers = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $providers[$row['provider']] = $row;
}

// Allowed channels
$s = $pdo->prepare("SELECT channel_id FROM bot_ai_allowed_channels WHERE bot_id = ?");
$s->execute([$botId]);
$allowedChannels = implode(', ', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'channel_id'));

// Ensure command row exists
$pdo->prepare("INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled) VALUES (?, 'ai', 'ai', '/ask', 'Stelle der KI eine Frage', 1)")
    ->execute([$botId]);

// Commands
$s = $pdo->prepare("SELECT command_key, is_enabled FROM commands WHERE bot_id = ? AND command_key IN ('ai')");
$s->execute([$botId]);
$cmdMap = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $cmdMap[$r['command_key']] = (bool)$r['is_enabled']; }

function prov(array $providers, string $key, string $field, string $default = ''): string {
    return htmlspecialchars((string)($providers[$key][$field] ?? $default), ENT_QUOTES, 'UTF-8');
}

$providerDefs = [
    'openai'    => ['label' => 'OpenAI',              'default_url' => 'https://api.openai.com/v1',              'default_model' => 'gpt-4o-mini',                      'needs_key' => true,  'has_url' => false, 'has_ollama' => false],
    'nvidia'    => ['label' => 'NVIDIA / build.nvidia.com', 'default_url' => 'https://integrate.api.nvidia.com/v1', 'default_model' => 'meta/llama-3.1-70b-instruct',    'needs_key' => true,  'has_url' => false, 'has_ollama' => false],
    'anthropic' => ['label' => 'Anthropic (Claude)',   'default_url' => 'https://api.anthropic.com/v1',           'default_model' => 'claude-haiku-4-5-20251001',        'needs_key' => true,  'has_url' => false, 'has_ollama' => false],
    'groq'      => ['label' => 'Groq',                 'default_url' => 'https://api.groq.com/openai/v1',         'default_model' => 'llama-3.1-8b-instant',             'needs_key' => true,  'has_url' => false, 'has_ollama' => false],
    'ollama'    => ['label' => 'Ollama (lokal)',        'default_url' => 'http://localhost:11434/v1',              'default_model' => 'llama3',                           'needs_key' => false, 'has_url' => true,  'has_ollama' => true],
    'custom'    => ['label' => 'Custom (OpenAI-kompatibel)', 'default_url' => '',                                  'default_model' => '',                                 'needs_key' => true,  'has_url' => true,  'has_ollama' => false],
];

$activeProvider = $settings['active_provider'] ?? 'openai';
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:ai');
?>

<div class="lv-page">
    <div class="lv-head">
        <div class="lv-kicker">Künstliche Intelligenz</div>
        <h1 class="lv-title">AI Chat</h1>
        <p class="lv-subtitle">Verbinde deinen Bot mit KI-Anbietern — OpenAI, NVIDIA, Ollama, Anthropic, Groq und mehr.</p>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:ai', 'AI Chat', 'KI-gestützte Chatfunktion und alle /ask-Commands für diesen Bot ein- oder ausschalten.') ?>
    <div id="bh-mod-body">

    <!-- General Settings Card -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ai-general-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Konfiguration</div>
                <div class="bh-card-title">Allgemeine Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ai-general-body">
            <div class="bh-field">
                <label class="bh-label" for="ai-active-provider">Aktiver Anbieter</label>
                <select id="ai-active-provider" class="bh-select" style="max-width:320px">
                    <?php foreach ($providerDefs as $key => $def): ?>
                    <option value="<?= $key ?>" <?= $activeProvider === $key ? 'selected' : '' ?>><?= htmlspecialchars($def['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="bh-hint">Dieser Anbieter wird für den <code>/ask</code>-Command verwendet.</div>
            </div>
            <div class="bh-field">
                <label class="bh-label" for="ai-system-prompt">System-Prompt <span style="color:var(--bh-text-muted)">(optional)</span></label>
                <textarea id="ai-system-prompt" class="bh-input" rows="3" style="resize:vertical" placeholder="Du bist ein hilfreicher Assistent auf dem Discord-Server {servername}."><?= htmlspecialchars((string)($settings['system_prompt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="bh-hint">Gibt der KI eine Rolle oder einen Kontext vor. Leer lassen für Standardverhalten.</div>
            </div>
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="ai-max-tokens">Max. Tokens</label>
                        <input type="number" id="ai-max-tokens" class="bh-input" value="<?= (int)($settings['max_tokens'] ?? 1000) ?>" min="1" max="8000">
                        <div class="bh-hint">Maximale Länge der Antwort (1–8000).</div>
                    </div>
                    <div>
                        <label class="bh-label" for="ai-temperature">Temperature</label>
                        <input type="number" id="ai-temperature" class="bh-input" value="<?= number_format((float)($settings['temperature'] ?? 0.7), 2) ?>" min="0" max="2" step="0.05">
                        <div class="bh-hint">Kreativität (0 = deterministisch, 2 = sehr kreativ).</div>
                    </div>
                </div>
            </div>
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="ai-history-length">Verlauf (Nachrichten)</label>
                        <input type="number" id="ai-history-length" class="bh-input" value="<?= (int)($settings['history_length'] ?? 10) ?>" min="1" max="50">
                        <div class="bh-hint">Wie viele Nachrichten die KI sich pro User merkt (1–50).</div>
                    </div>
                    <div>
                        <label class="bh-label" for="ai-session-timeout">Session-Timeout (Minuten)</label>
                        <input type="number" id="ai-session-timeout" class="bh-input" value="<?= (int)($settings['session_timeout_min'] ?? 30) ?>" min="1" max="1440">
                        <div class="bh-hint">Nach dieser Inaktivität wird der Verlauf automatisch gelöscht.</div>
                    </div>
                </div>
            </div>
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Web Research</div>
                    <div class="lv-feature__desc">Erlaubt Websuche beim <code>/ask frage web:True</code>-Command und per <code>web:</code>-Prefix bei @Mentions.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="ai-web-enabled" <?= !empty($settings['web_search_enabled']) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="lv-feature" id="ai-web-always-row" <?= empty($settings['web_search_enabled']) ? 'style="display:none"' : '' ?>>
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Immer Web Research nutzen</div>
                    <div class="lv-feature__desc">Bei jeder Anfrage automatisch suchen — kein <code>web:</code>-Prefix nötig.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="ai-web-always" <?= !empty($settings['web_search_always']) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="bh-field" id="ai-web-fields" <?= empty($settings['web_search_enabled']) ? 'style="display:none"' : '' ?>>
                <label class="bh-label">Such-Anbieter & API Keys <span style="color:var(--bh-text-muted)">(optional — ohne Key wird DuckDuckGo verwendet)</span></label>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px">
                    <div>
                        <label class="bh-label" for="ai-brave-key" style="font-size:11px">Brave Search API Key</label>
                        <input type="password" id="ai-brave-key" class="bh-input" placeholder="BSA..." value="<?= htmlspecialchars((string)($settings['brave_api_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bh-hint">Bestes Ergebnis. Kostenloser Key unter search.brave.com/api</div>
                    </div>
                    <div>
                        <label class="bh-label" for="ai-searxng-url" style="font-size:11px">SearXNG Instance URL <span style="color:var(--bh-text-muted)">(optional)</span></label>
                        <input type="text" id="ai-searxng-url" class="bh-input" placeholder="https://searxng.example.com" value="<?= htmlspecialchars((string)($settings['searxng_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bh-hint">Eigene oder öffentliche SearXNG-Instanz. Wird vor DuckDuckGo bevorzugt (wenn kein Brave Key).</div>
                    </div>
                </div>
            </div>
            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="ai-save-general">Speichern</button>
                <span class="lv-save-msg" id="ai-general-msg"></span>
            </div>
        </div>
    </div>

    <!-- Provider Cards -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ai-providers-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Zugangsdaten</div>
                <div class="bh-card-title">Anbieter konfigurieren</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ai-providers-body">
            <?php foreach ($providerDefs as $key => $def): ?>
            <div class="bh-field ai-provider-block" id="ai-prov-<?= $key ?>" <?= $activeProvider !== $key ? 'style="display:none"' : '' ?>>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                    <div>
                        <div class="bh-label" style="margin-bottom:0;font-size:14px"><?= htmlspecialchars($def['label']) ?></div>
                        <?php if ($activeProvider === $key): ?>
                        <span class="badge badge--active" style="margin-top:4px;display:inline-block">Aktiv</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($def['needs_key']): ?>
                <div style="margin-bottom:10px">
                    <label class="bh-label" for="ai-key-<?= $key ?>">API Key</label>
                    <input type="password" id="ai-key-<?= $key ?>" class="bh-input ai-api-key"
                        data-provider="<?= $key ?>"
                        value="<?= prov($providers, $key, 'api_key') ?>"
                        placeholder="sk-...">
                </div>
                <?php endif; ?>

                <?php if ($def['has_url']): ?>
                <div style="margin-bottom:10px">
                    <label class="bh-label" for="ai-url-<?= $key ?>">Base URL</label>
                    <input type="text" id="ai-url-<?= $key ?>" class="bh-input ai-base-url"
                        data-provider="<?= $key ?>"
                        value="<?= prov($providers, $key, 'base_url', $def['default_url']) ?>"
                        placeholder="<?= htmlspecialchars($def['default_url']) ?>">
                </div>
                <?php endif; ?>

                <div style="margin-bottom:10px">
                    <label class="bh-label" for="ai-model-<?= $key ?>">Modell</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="ai-model-<?= $key ?>" class="bh-input ai-model"
                            data-provider="<?= $key ?>"
                            value="<?= prov($providers, $key, 'selected_model', $def['default_model']) ?>"
                            placeholder="<?= htmlspecialchars($def['default_model']) ?>"
                            style="flex:1">
                        <?php if ($def['has_ollama']): ?>
                        <button class="bh-btn bh-btn--primary bh-btn bh-btn--sm ai-ollama-fetch" data-provider="<?= $key ?>" type="button" style="white-space:nowrap">
                            Models laden
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($def['has_ollama']): ?>
                    <select id="ai-ollama-list-<?= $key ?>" class="bh-select ai-ollama-select" data-provider="<?= $key ?>" style="display:none;margin-top:8px">
                        <option value="">-- Modell wählen --</option>
                    </select>
                    <div class="bh-hint ai-ollama-status-<?= $key ?>"></div>
                    <?php endif; ?>
                </div>

                <button class="bh-btn bh-btn--primary bh-btn bh-btn--sm ai-save-provider" data-provider="<?= $key ?>" type="button">Speichern</button>
                <span class="lv-save-msg ai-prov-msg-<?= $key ?>" style="margin-left:10px;font-size:12px"></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Mention Card -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ai-mention-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Nachrichten</div>
                <div class="bh-card-title">@Mention Antworten</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ai-mention-body">
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Aktiviert</div>
                    <div class="lv-feature__desc">Bot reagiert wenn er per @Mention in einem erlaubten Channel angesprochen wird.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="ai-mention-enabled" <?= !empty($settings['mention_enabled']) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="bh-field">
                <label class="bh-label" for="ai-mention-context">Channel-Kontext (Nachrichten)</label>
                <input type="number" id="ai-mention-context" class="bh-input" style="max-width:160px"
                    value="<?= (int)($settings['mention_context_messages'] ?? 10) ?>" min="0" max="50">
                <div class="bh-hint">Wie viele vorherige Channel-Nachrichten die KI als Kontext einliest (0 = deaktiviert).</div>
            </div>
            <div class="bh-field">
                <label class="bh-label" for="ai-allowed-channels">Erlaubte Channels <span style="color:var(--bh-text-muted)">(Channel-IDs, kommagetrennt)</span></label>
                <input type="text" id="ai-allowed-channels" class="bh-input"
                    value="<?= htmlspecialchars($allowedChannels, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="123456789012345678, 987654321098765432">
                <div class="bh-hint">Nur in diesen Channels reagiert der Bot auf @Mentions. Leer lassen = alle Channels erlaubt.</div>
            </div>
            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="ai-save-mention">Speichern</button>
                <span class="lv-save-msg" id="ai-mention-msg"></span>
            </div>
        </div>
    </div>

    <!-- Commands Card -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="ai-cmd-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Commands</div>
                <div class="bh-card-title">Slash-Commands</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="ai-cmd-body">
            <div class="bh-cmd-grid" style="padding:16px 20px">
                <?php
                $cmds = [
                    ['key' => 'ai', 'name' => '/ask', 'desc' => 'Stellt der KI eine Frage und postet die Antwort im Channel.'],
                ];
                foreach ($cmds as $cmd): ?>
                <div class="bh-cmd-card">
                    <div>
                        <div class="bh-cmd-name"><?= htmlspecialchars($cmd['name']) ?></div>
                        <div class="bh-cmd-desc"><?= htmlspecialchars($cmd['desc']) ?></div>
                    </div>
                    <label class="bh-toggle">
                        <input type="checkbox" class="ai-cmd-toggle bh-toggle-input" data-key="<?= $cmd['key'] ?>"
                            <?= ($cmdMap[$cmd['key']] ?? true) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    </div><!-- /bh-mod-body -->
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

    // Show only the active provider block
    function updateProviderVisibility(active) {
        document.querySelectorAll('.ai-provider-block').forEach(el => {
            const prov = el.id.replace('ai-prov-', '');
            el.style.display = prov === active ? '' : 'none';
        });
    }

    document.getElementById('ai-active-provider').addEventListener('change', function () {
        updateProviderVisibility(this.value);
    });

    function post(data) {
        return fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ...data, bot_id: '<?= $botId ?>' }).toString() })
            .then(r => r.json());
    }

    function showMsg(el, ok, text) {
        el.textContent = text;
        el.className = 'lv-save-msg ' + (ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        setTimeout(() => { el.textContent = ''; el.className = 'lv-save-msg'; }, 3000);
    }

    // Web search toggle shows/hides key fields + always-row
    const webToggle    = document.getElementById('ai-web-enabled');
    const webFields    = document.getElementById('ai-web-fields');
    const webAlwaysRow = document.getElementById('ai-web-always-row');
    webToggle.addEventListener('change', function () {
        webFields.style.display    = this.checked ? '' : 'none';
        webAlwaysRow.style.display = this.checked ? '' : 'none';
        if (!this.checked) document.getElementById('ai-web-always').checked = false;
    });

    // Save general settings
    document.getElementById('ai-save-general').addEventListener('click', () => {
        const msg = document.getElementById('ai-general-msg');
        post({
            action:              'save_settings',
            active_provider:     document.getElementById('ai-active-provider').value,
            system_prompt:       document.getElementById('ai-system-prompt').value,
            max_tokens:          document.getElementById('ai-max-tokens').value,
            temperature:         document.getElementById('ai-temperature').value,
            history_length:      document.getElementById('ai-history-length').value,
            session_timeout_min: document.getElementById('ai-session-timeout').value,
            web_search_enabled:  document.getElementById('ai-web-enabled').checked ? 1 : 0,
            web_search_always:   document.getElementById('ai-web-always').checked ? 1 : 0,
            brave_api_key:       document.getElementById('ai-brave-key').value,
            searxng_url:         document.getElementById('ai-searxng-url').value,
        }).then(r => {
            showMsg(msg, r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler'));
            if (r.ok) {
                updateProviderVisibility(document.getElementById('ai-active-provider').value);
            }
        });
    });

    // Save individual provider
    document.querySelectorAll('.ai-save-provider').forEach(btn => {
        btn.addEventListener('click', () => {
            const prov = btn.dataset.provider;
            const msgEl = document.querySelector('.ai-prov-msg-' + prov);
            post({
                action:         'save_provider',
                provider:       prov,
                api_key:        document.getElementById('ai-key-' + prov)?.value ?? '',
                base_url:       document.getElementById('ai-url-' + prov)?.value ?? '',
                selected_model: document.getElementById('ai-model-' + prov)?.value ?? '',
            }).then(r => showMsg(msgEl, r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
        });
    });

    // Ollama model fetch
    document.querySelectorAll('.ai-ollama-fetch').forEach(btn => {
        btn.addEventListener('click', () => {
            const prov = btn.dataset.provider;
            const urlInput = document.getElementById('ai-url-' + prov);
            const baseUrl  = urlInput?.value || 'http://localhost:11434';
            // Strip /v1 suffix for the tags endpoint
            const tagsBase = baseUrl.replace(/\/v1\/?$/, '');
            const statusEl = document.querySelector('.ai-ollama-status-' + prov);
            const selectEl = document.getElementById('ai-ollama-list-' + prov);

            btn.disabled = true;
            btn.textContent = 'Laden…';

            post({ action: 'fetch_ollama_models', base_url: tagsBase }).then(r => {
                btn.disabled = false;
                btn.textContent = 'Models laden';
                if (!r.ok) {
                    if (statusEl) statusEl.textContent = r.error || 'Fehler';
                    return;
                }
                if (statusEl) statusEl.textContent = r.models.length + ' Modell(e) gefunden.';
                if (selectEl && r.models.length > 0) {
                    selectEl.innerHTML = '<option value="">-- Modell wählen --</option>';
                    r.models.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m; opt.textContent = m;
                        selectEl.appendChild(opt);
                    });
                    selectEl.style.display = '';
                    selectEl.addEventListener('change', function () {
                        const modelInput = document.getElementById('ai-model-' + prov);
                        if (modelInput && this.value) modelInput.value = this.value;
                    }, { once: false });
                }
            }).catch(() => {
                btn.disabled = false;
                btn.textContent = 'Models laden';
                if (statusEl) statusEl.textContent = 'Netzwerkfehler';
            });
        });
    });

    // Save mention settings
    document.getElementById('ai-save-mention').addEventListener('click', () => {
        const msg = document.getElementById('ai-mention-msg');
        post({
            action:                   'save_mention_settings',
            mention_enabled:          document.getElementById('ai-mention-enabled').checked ? 1 : 0,
            mention_context_messages: document.getElementById('ai-mention-context').value,
            allowed_channels:         document.getElementById('ai-allowed-channels').value,
        }).then(r => showMsg(msg, r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });

    // Command toggles
    document.querySelectorAll('.ai-cmd-toggle').forEach(chk => {
        chk.addEventListener('change', function () {
            post({ action: 'toggle_command', command_key: this.dataset.key, enabled: this.checked ? 1 : 0 });
        });
    });
})();
</script>
