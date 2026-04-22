<?php
declare(strict_types=1);
// Shared helper for the standardised "Modul aktivieren" card shown at the
// top of every module dashboard page.

if (!function_exists('bhcmd_is_enabled')) {
    require_once __DIR__ . '/db_functions/commands.php';
}

// ── DB helpers ────────────────────────────────────────────────────────────────

function bh_mod_is_enabled(PDO $pdo, int $botId, string $key): bool
{
    return bhcmd_is_enabled($pdo, $botId, $key) !== 0;
}

// ── AJAX handler ─────────────────────────────────────────────────────────────
// Call this at the very top of each page's AJAX block (before JSON parsing).
// It reads from $_POST (form-encoded) so it won't consume php://input.

function bh_mod_handle_ajax(PDO $pdo, int $botId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!isset($_POST['_bh_mod_action'])) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ((string)$_POST['_bh_mod_action'] !== 'toggle') {
            throw new RuntimeException('Unknown action.');
        }
        $key     = preg_replace('/[^a-z0-9_:\-]/', '', (string)($_POST['_bh_mod_key'] ?? ''));
        $enabled = (int)(bool)($_POST['_bh_mod_enabled'] ?? 0);

        if ($key === '') {
            throw new RuntimeException('Missing module key.');
        }

        bhcmd_set_module_enabled($pdo, $botId, $key, $enabled);

        if (function_exists('bh_notify_slash_sync')) {
            try { bh_notify_slash_sync($botId); } catch (Throwable) {}
        }
        if (function_exists('bh_notify_bot_reload')) {
            try { bh_notify_bot_reload($botId); } catch (Throwable) {}
        }

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }

    exit;
}

// ── Render toggle card ────────────────────────────────────────────────────────
// $bodyId: the id of the <div> wrapping the module content to gray out.

function bh_mod_render(bool $enabled, int $botId, string $key, string $title, string $desc, string $bodyId = 'bh-mod-body'): string
{
    $safeKey   = htmlspecialchars($key,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDesc  = htmlspecialchars($desc,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBodyId = htmlspecialchars($bodyId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $checkedAttr = $enabled ? ' checked' : '';
    $uid = 'bhmod_' . preg_replace('/[^a-z0-9]/', '_', $key);

    // CSS — output once per page
    $css = '';
    if (!defined('BH_MOD_CSS_LOADED')) {
        define('BH_MOD_CSS_LOADED', true);
        $css = '<style>
.bh-mod-card{background:var(--bh-card-bg,#1e2433);border-radius:12px;margin-bottom:16px;overflow:hidden}
.bh-mod-feature{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;gap:16px}
.bh-mod-feature__left{display:flex;flex-direction:column;gap:2px;min-width:0}
.bh-mod-feature__kicker{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--bh-accent,#6c63ff);margin-bottom:2px}
.bh-mod-feature__title{font-size:15px;font-weight:600;color:var(--bh-text,#e6edf3)}
.bh-mod-feature__desc{font-size:12px;color:var(--bh-text-muted,#8b949e);margin-top:1px}
.bh-mod-feature__right{flex-shrink:0}
.bh-mod-toggle{position:relative;display:inline-block;width:44px;height:24px;cursor:pointer}
.bh-mod-toggle input{opacity:0;width:0;height:0;position:absolute}
.bh-mod-toggle__track{position:absolute;inset:0;background:#3b3f52;border-radius:12px;transition:background .2s}
.bh-mod-toggle input:checked+.bh-mod-toggle__track{background:var(--bh-accent,#6c63ff)}
.bh-mod-toggle__track::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s}
.bh-mod-toggle input:checked+.bh-mod-toggle__track::after{transform:translateX(20px)}
.bh-mod-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.04em}
.bh-mod-pill--on{background:rgba(52,211,153,.15);color:#34d399}
.bh-mod-pill--off{background:rgba(248,113,113,.12);color:#f87171}
.bh-mod-body--disabled{opacity:.4;pointer-events:none;user-select:none}
</style>';
    }

    $pillClass = $enabled ? 'bh-mod-pill--on' : 'bh-mod-pill--off';
    $pillDot   = $enabled ? '●' : '●';
    $pillText  = $enabled ? 'Aktiv' : 'Deaktiviert';

    $html = <<<HTML
{$css}<div class="bh-mod-card">
  <div class="bh-mod-feature">
    <div class="bh-mod-feature__left">
      <div class="bh-mod-feature__kicker">Modul</div>
      <div class="bh-mod-feature__title">{$safeTitle}</div>
      <div class="bh-mod-feature__desc">{$safeDesc}</div>
    </div>
    <div class="bh-mod-feature__right" style="display:flex;align-items:center;gap:10px">
      <span class="bh-mod-pill {$pillClass}" id="{$uid}_pill">{$pillDot} {$pillText}</span>
      <label class="bh-mod-toggle">
        <input type="checkbox" id="{$uid}_chk"{$checkedAttr}>
        <span class="bh-mod-toggle__track"></span>
      </label>
    </div>
  </div>
</div>
<script>
(function(){
  var chk   = document.getElementById('{$uid}_chk');
  var pill  = document.getElementById('{$uid}_pill');
  var body  = document.getElementById('{$safeBodyId}');
  function applyState(on) {
    if (pill) {
      pill.className = 'bh-mod-pill ' + (on ? 'bh-mod-pill--on' : 'bh-mod-pill--off');
      pill.textContent = on ? '● Aktiv' : '● Deaktiviert';
    }
    if (body) {
      body.classList.toggle('bh-mod-body--disabled', !on);
    }
  }
  applyState(chk ? chk.checked : true);
  if (!chk) return;
  chk.addEventListener('change', function() {
    var on = chk.checked;
    var fd = new URLSearchParams();
    fd.set('_bh_mod_action',  'toggle');
    fd.set('_bh_mod_key',     '{$safeKey}');
    fd.set('_bh_mod_enabled', on ? '1' : '0');
    fetch(window.location.href, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: fd.toString()
    }).then(function(r){ return r.json(); }).then(function(res){
      if (!res.ok) { chk.checked = !on; applyState(!on); }
      else         { applyState(on); }
    }).catch(function(){ chk.checked = !on; applyState(!on); });
  });
})();
</script>
HTML;

    return $html;
}
