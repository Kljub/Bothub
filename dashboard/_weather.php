<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';
require_once dirname(__DIR__) . '/functions/html.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div style="color:#f87171;padding:20px">Kein Bot ausgewählt.</div>
<?php return; }

// ── Ensure weather command row exists ─────────────────────────────────────────
try {
    bhcmd_upsert_command(
        $pdo, $botId, 'weather', 'predefined',
        'weather', 'Zeigt das aktuelle Wetter für einen Ort an.',
        1, null
    );
} catch (Throwable) {}

// ── Load current settings_json ────────────────────────────────────────────────
function bh_weather_get_settings(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare(
        'SELECT settings_json FROM commands WHERE bot_id = :bid AND command_key = :key LIMIT 1'
    );
    $stmt->execute([':bid' => $botId, ':key' => 'weather']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['settings_json']) return [];
    return json_decode((string)$row['settings_json'], true) ?: [];
}

function bh_weather_save_settings(PDO $pdo, int $botId, array $settings): void
{
    $pdo->prepare(
        'UPDATE commands SET settings_json = :json, updated_at = NOW()
         WHERE bot_id = :bid AND command_key = :key'
    )->execute([
        ':json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ':bid'  => $botId,
        ':key'  => 'weather',
    ]);
}

// ── AJAX POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Module toggle (handled by bh_mod_handle_ajax via _bh_mod_action field)
    bh_mod_handle_ajax($pdo, $botId);

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    $csrfPost = (string)(json_decode((string)file_get_contents('php://input'), true)['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfPost)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_mismatch']);
        exit;
    }

    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true) ?? [];
    $action = (string)($data['action'] ?? '');

    try {
        if ($action === 'save') {
            $apiKey          = trim((string)($data['api_key'] ?? ''));
            $units           = in_array($data['units'] ?? '', ['metric', 'imperial'], true)
                ? (string)$data['units'] : 'metric';
            $defaultLocation = mb_substr(trim((string)($data['default_location'] ?? '')), 0, 128);

            bh_weather_save_settings($pdo, $botId, [
                'api_key'          => $apiKey,
                'units'            => $units,
                'default_location' => $defaultLocation,
            ]);

            // Trigger slash-sync so Discord shows the updated command
            try { bh_notify_slash_sync($botId); } catch (Throwable) {}

            echo json_encode(['ok' => true]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    } catch (Throwable $e) {
        error_log('[BotHub] weather save error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Interner Fehler.']);
    }
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$settings        = bh_weather_get_settings($pdo, $botId);
$apiKey          = (string)($settings['api_key'] ?? '');
$units           = (string)($settings['units'] ?? 'metric');
$defaultLocation = (string)($settings['default_location'] ?? '');
$modEnabled      = bh_mod_is_enabled($pdo, $botId, 'module:weather');
$cmdEnabled      = bhcmd_is_enabled($pdo, $botId, 'weather');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:weather', 'Weather', 'Wetter-Modul und /weather-Command für diesen Bot ein- oder ausschalten.') ?>

<div id="bh-mod-body" <?= $modEnabled ? '' : 'class="bh-mod-body--disabled"' ?>>
<div class="wx-wrap">

    <div id="wx-flash" class="wx-flash" style="display:none"></div>

    <!-- ── API-Einstellungen ──────────────────────────────────────────────── -->
    <div class="mb-1">
        <div class="wx-kicker">Einstellungen</div>
        <div class="wx-section-title">OpenWeatherMap API</div>
    </div>

    <div class="wx-card">
        <div class="wx-card-title">API-Konfiguration</div>

        <!-- API Key -->
        <div class="wx-field">
            <div class="wx-field-left">
                <div class="wx-field-label">API-Key</div>
                <div class="wx-field-desc">
                    Kostenlos erhältlich auf
                    <a href="https://openweathermap.org/api" target="_blank" rel="noopener" class="wx-link">openweathermap.org</a>
                    (Free-Tier: 60 Anfragen/min).
                </div>
            </div>
            <div class="wx-field-right">
                <div class="wx-key-row">
                    <input type="password" id="wx-api-key" class="wx-input wx-input--mono"
                        placeholder="Dein OpenWeatherMap API-Key"
                        value="<?= h($apiKey) ?>"
                        autocomplete="off" spellcheck="false">
                    <button type="button" class="wx-eye-btn" id="wx-eye-btn" title="Anzeigen/Verbergen">
                        <svg id="wx-eye-icon" viewBox="0 0 16 16" fill="currentColor" width="16" height="16">
                            <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/>
                            <path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Units -->
        <div class="wx-field">
            <div class="wx-field-left">
                <div class="wx-field-label">Einheit</div>
                <div class="wx-field-desc">Temperatur in Celsius oder Fahrenheit anzeigen.</div>
            </div>
            <div class="wx-field-right">
                <div class="wx-unit-toggle">
                    <button type="button" class="wx-unit-btn <?= $units === 'metric' ? 'wx-unit-btn--active' : '' ?>"
                        id="wx-unit-metric" data-unit="metric">°C — Metric</button>
                    <button type="button" class="wx-unit-btn <?= $units === 'imperial' ? 'wx-unit-btn--active' : '' ?>"
                        id="wx-unit-imperial" data-unit="imperial">°F — Imperial</button>
                </div>
                <input type="hidden" id="wx-units" value="<?= h($units) ?>">
            </div>
        </div>

        <!-- Default location -->
        <div class="wx-field" style="border-bottom:none">
            <div class="wx-field-left">
                <div class="wx-field-label">Standard-Ort <span class="wx-optional">(optional)</span></div>
                <div class="wx-field-desc">
                    Wird verwendet, wenn der Nutzer <code class="wx-code">/weather</code> ohne Ortsangabe ausführt.
                    Stadtname oder PLZ (z.&thinsp;B. <code class="wx-code">Berlin</code> oder <code class="wx-code">10115</code>).
                </div>
            </div>
            <div class="wx-field-right">
                <input type="text" id="wx-default-location" class="wx-input"
                    placeholder="z.B. Berlin oder 10115"
                    value="<?= h($defaultLocation) ?>" maxlength="128">
            </div>
        </div>
    </div>

    <!-- ── Command-Info ───────────────────────────────────────────────────── -->
    <div class="mb-1 mt-2">
        <div class="wx-kicker">Command</div>
        <div class="wx-section-title">Slash-Command</div>
    </div>

    <div class="wx-cmd-card">
        <div class="wx-cmd-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
            </svg>
            <div>
                <div class="wx-cmd-kicker">Slash Command</div>
                <div class="wx-cmd-title">Wetterbefehl</div>
            </div>
            <label class="wx-toggle" style="margin-left:auto">
                <input type="checkbox" id="wx-cmd-toggle" <?= $cmdEnabled ? 'checked' : '' ?>>
                <span class="wx-toggle-track"></span>
            </label>
        </div>
        <div class="wx-cmd-list">
            <div class="wx-cmd-row">
                <code class="wx-cmd-name">/weather [ort]</code>
                <span class="wx-cmd-desc">
                    Zeigt aktuelles Wetter an. <code class="wx-code">ort</code> ist optional —
                    Stadtname (<code class="wx-code">Berlin</code>) oder PLZ (<code class="wx-code">10115</code>).
                </span>
            </div>
        </div>

        <div class="wx-example-box">
            <div class="wx-example-title">Beispiele</div>
            <div class="wx-example-list">
                <span class="wx-example-tag">/weather Berlin</span>
                <span class="wx-example-tag">/weather München</span>
                <span class="wx-example-tag">/weather 10115</span>
                <span class="wx-example-tag">/weather Hamburg</span>
                <span class="wx-example-tag">/weather</span>
                <span class="wx-example-desc">(nutzt Standard-Ort)</span>
            </div>
        </div>

        <div class="wx-preview">
            <div class="wx-preview-title">Discord-Ausgabe</div>
            <div class="wx-preview-embed">
                <div class="wx-preview-stripe"></div>
                <div class="wx-preview-body">
                    <div class="wx-preview-head">⛅ Wetter für Berlin, DE</div>
                    <div class="wx-preview-desc">Leicht bewölkt</div>
                    <div class="wx-preview-fields">
                        <div class="wx-preview-field">
                            <div class="wx-preview-field-name">🌡️ Temperatur</div>
                            <div class="wx-preview-field-val">18.4°C (gefühlt 16.1°C)</div>
                        </div>
                        <div class="wx-preview-field">
                            <div class="wx-preview-field-name">💧 Luftfeuchtigkeit</div>
                            <div class="wx-preview-field-val">62%</div>
                        </div>
                        <div class="wx-preview-field">
                            <div class="wx-preview-field-name">💨 Wind</div>
                            <div class="wx-preview-field-val">3.2 m/s NO</div>
                        </div>
                        <div class="wx-preview-field">
                            <div class="wx-preview-field-name">🌅 Sonnenaufgang</div>
                            <div class="wx-preview-field-val">05:52</div>
                        </div>
                        <div class="wx-preview-field">
                            <div class="wx-preview-field-name">🌇 Sonnenuntergang</div>
                            <div class="wx-preview-field-val">20:18</div>
                        </div>
                    </div>
                    <div class="wx-preview-footer">Daten von OpenWeatherMap</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Save -->
    <div class="wx-save-bar">
        <button type="button" class="wx-save-btn" id="wx-save-btn">Speichern</button>
    </div>

</div><!-- /wx-wrap -->
</div><!-- /bh-mod-body -->


<script>
(function () {
    'use strict';

    var BOT_ID    = <?= (int)$botId ?>;
    var BASE_URL  = window.location.href;
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── Unit toggle ───────────────────────────────────────────────────────────
    var unitInput = document.getElementById('wx-units');
    document.querySelectorAll('.wx-unit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.wx-unit-btn').forEach(function (b) {
                b.classList.remove('wx-unit-btn--active');
            });
            btn.classList.add('wx-unit-btn--active');
            if (unitInput) unitInput.value = btn.dataset.unit;
        });
    });

    // ── API-Key visibility toggle ─────────────────────────────────────────────
    var keyInput = document.getElementById('wx-api-key');
    document.getElementById('wx-eye-btn').addEventListener('click', function () {
        if (!keyInput) return;
        keyInput.type = keyInput.type === 'password' ? 'text' : 'password';
    });

    // ── Command toggle ────────────────────────────────────────────────────────
    var cmdToggle = document.getElementById('wx-cmd-toggle');
    if (cmdToggle) {
        cmdToggle.addEventListener('change', function () {
            var on = cmdToggle.checked;
            var fd = new URLSearchParams();
            fd.set('_bh_mod_action', 'toggle');
            fd.set('_bh_mod_key',    'weather');
            fd.set('_bh_mod_enabled', on ? '1' : '0');
            fetch(BASE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString(),
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (!res.ok) { cmdToggle.checked = !on; }
            }).catch(function () { cmdToggle.checked = !on; });
        });
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    document.getElementById('wx-save-btn').addEventListener('click', async function () {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '…';

        var payload = {
            action:           'save',
            csrf_token:       csrfToken,
            api_key:          (document.getElementById('wx-api-key')          || {}).value || '',
            units:            (document.getElementById('wx-units')             || {}).value || 'metric',
            default_location: (document.getElementById('wx-default-location') || {}).value || '',
        };

        try {
            var res  = await fetch(BASE_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            var data = await res.json();
            showFlash(data.ok ? 'ok' : 'err',
                data.ok ? 'Einstellungen gespeichert.' : (data.error || 'Fehler beim Speichern.'));
        } catch (_) {
            showFlash('err', 'Netzwerkfehler.');
        }

        btn.disabled = false;
        btn.textContent = 'Speichern';
    });

    function showFlash(type, msg) {
        var el = document.getElementById('wx-flash');
        if (!el) return;
        el.className = 'wx-flash wx-flash--' + type;
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.style.display = 'none'; }, 3500);
    }
}());
</script>
