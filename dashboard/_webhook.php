<?php
declare(strict_types=1);
/** @var array  $sidebarBots   */
/** @var int|null $currentBotId */
/** @var int    $userId        */

require_once dirname(__DIR__) . '/functions/db_functions/webhooks.php';

if (!isset($sidebarBots)  || !is_array($sidebarBots))  { $sidebarBots  = []; }
if (!isset($currentBotId) || !is_int($currentBotId))   { $currentBotId = null; }

// ── Init ─────────────────────────────────────────────────────────────────────

$pdo = bh_get_pdo();
bh_wh_ensure_tables($pdo);

// ── Flash ────────────────────────────────────────────────────────────────────

$flash = $_SESSION['wh_flash'] ?? null;
unset($_SESSION['wh_flash']);
$flashOk  = null;
$flashErr = null;
if (is_array($flash) && ($flash['msg'] ?? '') !== '') {
    if (($flash['type'] ?? '') === 'ok') {
        $flashOk  = (string)$flash['msg'];
    } else {
        $flashErr = (string)$flash['msg'];
    }
}

// ── POST ─────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF Check
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        bh_wh_set_flash('err', 'Ungültiges Sicherheits-Token (CSRF).');
        bh_wh_redirect($currentBotId);
    }

    $action    = (string)($_POST['action'] ?? '');
    $botIdPost = (int)($_POST['bot_id'] ?? 0);

    // Verify bot belongs to user
    $ownerCheck = $pdo->prepare(
        'SELECT id FROM bot_instances WHERE id = :id AND owner_user_id = :uid LIMIT 1'
    );
    $ownerCheck->execute([':id' => $botIdPost, ':uid' => $userId]);
    $botExists = $ownerCheck->fetch() !== false;

    if (!$botExists || $botIdPost <= 0) {
        bh_wh_set_flash('err', 'Bot nicht gefunden oder keine Berechtigung.');
        bh_wh_redirect($currentBotId);
    }

    if ($action === 'regenerate_key') {
        bh_wh_upsert_api_key($pdo, $botIdPost, bh_wh_generate_api_key());
        bh_wh_set_flash('ok', 'API Key wurde neu generiert.');
        bh_wh_redirect($botIdPost);
    }

    if ($action === 'add_webhook') {
        $eventName = trim((string)($_POST['event_name'] ?? ''));
        if ($eventName === '') {
            bh_wh_set_flash('err', 'Event Name darf nicht leer sein.');
            bh_wh_redirect($botIdPost);
        }
        if (mb_strlen($eventName, 'UTF-8') > 128) {
            bh_wh_set_flash('err', 'Event Name ist zu lang (max. 128 Zeichen).');
            bh_wh_redirect($botIdPost);
        }

        $eventId = bh_wh_generate_event_id();
        $pdo->prepare(
            'INSERT INTO bot_webhooks (bot_id, event_id, event_name) VALUES (:bid, :eid, :ename)'
        )->execute([':bid' => $botIdPost, ':eid' => $eventId, ':ename' => $eventName]);

        bh_wh_set_flash('ok', 'Webhook "' . $eventName . '" wurde hinzugefügt.');
        bh_wh_redirect($botIdPost);
    }

    if ($action === 'delete_webhook') {
        $webhookId = (int)($_POST['webhook_id'] ?? 0);
        if ($webhookId <= 0) {
            bh_wh_set_flash('err', 'Ungültige Webhook-ID.');
            bh_wh_redirect($botIdPost);
        }
        $pdo->prepare(
            'DELETE FROM bot_webhooks WHERE id = :id AND bot_id = :bid LIMIT 1'
        )->execute([':id' => $webhookId, ':bid' => $botIdPost]);
        bh_wh_set_flash('ok', 'Webhook wurde gelöscht.');
        bh_wh_redirect($botIdPost);
    }
}

// ── Load data ────────────────────────────────────────────────────────────────

$apiKey   = null;
$webhooks = [];
$botDiscordId = null;

if ($currentBotId !== null && $currentBotId > 0) {
    // Auto-create API key on first visit
    $apiKey = bh_wh_get_api_key($pdo, $currentBotId);
    if ($apiKey === null) {
        $apiKey = bh_wh_generate_api_key();
        bh_wh_upsert_api_key($pdo, $currentBotId, $apiKey);
    }

    $webhooks = bh_wh_list_webhooks($pdo, $currentBotId);

    $row = $pdo->prepare('SELECT discord_bot_user_id FROM bot_instances WHERE id = :id LIMIT 1');
    $row->execute([':id' => $currentBotId]);
    $r = $row->fetch();
    if (is_array($r)) {
        $botDiscordId = (string)($r['discord_bot_user_id'] ?? '');
    }
}

$host       = (string)($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');
$newEventId = bh_wh_generate_event_id();
$formBotId  = (int)($currentBotId ?? 0);
$exampleEventId = count($webhooks) > 0 ? (string)($webhooks[0]['event_id'] ?? $newEventId) : $newEventId;
$webhookBase = 'https://' . $host . '/api/webhook/' . ($botDiscordId !== null && $botDiscordId !== '' ? $botDiscordId : '{bot_id}');
?>
<link rel="stylesheet" href="/assets/css/_webhook.css">

<div class="bh-wh-page">

    <div class="bh-wh-head">
        <div class="bh-wh-kicker">WEBHOOKS</div>
        <h1 class="bh-wh-title">Webhooks</h1>
    </div>

    <?php if ($flashOk !== null): ?>
        <div class="bh-alert bh-alert--ok"><?= bh_wh_h($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null): ?>
        <div class="bh-alert bh-alert--err"><?= bh_wh_h($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($currentBotId === null || $currentBotId <= 0): ?>
        <div class="bh-wh-card">
            <div class="bh-wh-card__body">
                <p class="bh-wh-empty">Kein Bot ausgewählt.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- ── API Key ── -->
    <div class="bh-wh-card">
        <div class="bh-wh-card__header">
            <div class="bh-wh-card__kicker">WEBHOOKS</div>
            <div class="bh-wh-card__title">API Key</div>
        </div>
        <div class="bh-wh-card__body">
            <label class="bh-wh-label" for="bh-apikey-field">API Key</label>
            <p class="bh-wh-desc" style="margin-bottom:12px;">The event id to pass along to the HTTP request</p>

            <div class="bh-wh-apikey-wrap">
                <input
                    id="bh-apikey-field"
                    type="password"
                    class="bh-wh-input bh-wh-apikey-field"
                    value="<?= bh_wh_h((string)$apiKey) ?>"
                    readonly
                    autocomplete="off"
                >
            </div>

            <div class="bh-wh-apikey-actions">
                <button
                    type="button"
                    class="bh-wh-btn bh-wh-btn--secondary"
                    onclick="
                        const f = document.getElementById('bh-apikey-field');
                        f.type = f.type === 'password' ? 'text' : 'password';
                        this.textContent = f.type === 'password' ? 'Show' : 'Hide';
                    "
                >Show</button>

                <form method="post" style="display:inline;" onsubmit="return confirm('API Key wirklich neu generieren?');">
                    <input type="hidden" name="action"  value="regenerate_key">
                    <input type="hidden" name="bot_id"  value="<?= $formBotId ?>">
                    <button type="submit" class="bh-wh-btn bh-wh-btn--primary">Regenerate</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── New Webhook ── -->
    <div class="bh-wh-card">
        <div class="bh-wh-card__header">
            <div class="bh-wh-card__kicker">WEBHOOKS</div>
            <div class="bh-wh-card__title">New Webhook</div>
        </div>
        <div class="bh-wh-card__body">

            <p class="bh-wh-desc">
                Webhooks allow you to trigger custom events through a simple POST HTTP request.
                You can use them to integrate other services into your BotHub bot.
                Once you have added a webhook, create a custom event to be triggered when this webhook is executed.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="add_webhook">
                <input type="hidden" name="bot_id" value="<?= $formBotId ?>">

                <div class="bh-wh-form-grid">
                    <div>
                        <div class="bh-wh-field">
                            <label class="bh-wh-label" for="bh-event-id">Event ID</label>
                            <p class="bh-wh-desc">The event id to pass along to the HTTP request</p>
                            <input
                                id="bh-event-id"
                                type="text"
                                class="bh-wh-input"
                                value="<?= bh_wh_h($newEventId) ?>"
                                readonly
                            >
                        </div>
                        <div class="bh-wh-field">
                            <label class="bh-wh-label" for="bh-event-name">Event name</label>
                            <p class="bh-wh-desc">A descriptive name for the event</p>
                            <input
                                id="bh-event-name"
                                name="event_name"
                                type="text"
                                class="bh-wh-input"
                                placeholder="Name"
                                maxlength="128"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="bh-wh-example">
                        <div class="bh-wh-example__title">Example request (POST)</div>
                        <pre class="bh-wh-example__code"><span class="hl-url">curl <?= bh_wh_h($webhookBase . '/' . $newEventId) ?> \</span>
<span class="hl-flag">  -X POST \
  -H</span> <span class="hl-value">'Authorization: API_KEY'</span> <span class="hl-flag">\
  -d</span> <span class="hl-value">'{"variables":[{"name":"message","variable":"{event_message}","value":"HELLO from webhooks"}]}'</span></pre>
                    </div>
                </div>

                <div class="bh-wh-form-actions">
                    <button type="submit" class="bh-wh-btn bh-wh-btn--primary">Add Webhook Event</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Active Webhooks ── -->
    <div class="bh-wh-card">
        <div class="bh-wh-card__header">
            <div class="bh-wh-card__kicker">WEBHOOKS</div>
            <div class="bh-wh-card__title">Active Webhooks</div>
        </div>
        <div class="bh-wh-card__body">
            <?php if (count($webhooks) === 0): ?>
                <p class="bh-wh-empty">Noch keine Webhooks vorhanden.</p>
            <?php else: ?>
                <table class="bh-wh-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Event ID</th>
                            <th>Erstellt</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhooks as $wh): ?>
                            <?php
                            $whId   = (int)($wh['id'] ?? 0);
                            $whEid  = (string)($wh['event_id'] ?? '');
                            $whName = (string)($wh['event_name'] ?? '');
                            $whDate = (string)($wh['created_at'] ?? '');
                            ?>
                            <tr>
                                <td><?= bh_wh_h($whName !== '' ? $whName : '—') ?></td>
                                <td><span class="bh-wh-event-id"><?= bh_wh_h($whEid) ?></span></td>
                                <td><?= bh_wh_h($whDate !== '' ? substr($whDate, 0, 10) : '—') ?></td>
                                <td class="bh-wh-table-actions">
                                    <form method="post" onsubmit="return confirm('Webhook wirklich löschen?');" style="display:inline;">
                                        <input type="hidden" name="action"     value="delete_webhook">
                                        <input type="hidden" name="bot_id"     value="<?= $formBotId ?>">
                                        <input type="hidden" name="webhook_id" value="<?= $whId ?>">
                                        <button type="submit" class="bh-wh-btn bh-wh-btn--danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>
