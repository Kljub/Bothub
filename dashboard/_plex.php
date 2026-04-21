<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions/plex/plex_client.php';

$plexFlashSuccess = trim((string)($_SESSION['plex_flash_success'] ?? ''));
$plexFlashError = trim((string)($_SESSION['plex_flash_error'] ?? ''));

unset($_SESSION['plex_flash_success'], $_SESSION['plex_flash_error']);

$plexLoadError = null;
$plexState = [
    'connected' => false,
    'account' => null,
    'servers' => [],
];

$plexCurrentBotId = null;
if (isset($currentBotId) && is_numeric((string)$currentBotId) && (int)$currentBotId > 0) {
    $plexCurrentBotId = (int)$currentBotId;
} elseif (isset($_GET['bot_id']) && is_numeric((string)$_GET['bot_id']) && (int)$_GET['bot_id'] > 0) {
    $plexCurrentBotId = (int)$_GET['bot_id'];
}

$plexLibraries = [];
$plexAllowedLibraries = [];

try {
    $plexState = plex_load_state_for_user($userId);

    if (!empty($plexState['connected']) && $plexCurrentBotId !== null && isset($plexState['account']['id'])) {
        $liveLibraries = plex_get_all_libraries_for_user($userId);
        plex_sync_bot_library_catalog($userId, $plexCurrentBotId, (int)$plexState['account']['id'], $liveLibraries);
        $plexLibraries = plex_get_catalog_libraries_for_bot($userId, $plexCurrentBotId);
        $plexAllowedLibraries = plex_get_allowed_libraries_map_for_bot($userId, $plexCurrentBotId);
    }
} catch (Throwable $e) {
    $plexLoadError = $e->getMessage();
}

$plexAccount = isset($plexState['account']) && is_array($plexState['account']) ? $plexState['account'] : null;
$plexServers = isset($plexState['servers']) && is_array($plexState['servers']) ? $plexState['servers'] : [];
$plexConnected = !empty($plexState['connected']);
$plexAllowedCount = count($plexAllowedLibraries);
$plexLibrariesCount = count($plexLibraries);

$plexBotName = 'Kein Bot ausgewählt';
if (isset($sidebarBots) && is_array($sidebarBots) && $plexCurrentBotId !== null && $plexCurrentBotId > 0) {
    foreach ($sidebarBots as $sidebarBot) {
        if (!is_array($sidebarBot)) {
            continue;
        }

        if ((int)($sidebarBot['id'] ?? 0) === $plexCurrentBotId) {
            $plexBotName = trim((string)($sidebarBot['name'] ?? ''));
            if ($plexBotName === '') {
                $plexBotName = 'Bot #' . $plexCurrentBotId;
            }
            break;
        }
    }
}

$plexCommandStates = [];
if ($plexConnected && $plexCurrentBotId !== null && $plexCurrentBotId > 0) {
    try {
        $plexCommandStates = plex_get_bot_command_states($userId, $plexCurrentBotId);
    } catch (Throwable $e) {
        // States sind nicht kritisch – Fehler ignorieren
    }
}

$plexCommandDefinitions = [
    [
        'key' => 'plex-search',
        'label' => '/plex-search {name}',
        'description' => 'Sucht nach einem Film, einer Serie oder einem anderen Eintrag in den freigegebenen Plex Libraries.',
    ],
    [
        'key' => 'plex-random',
        'label' => '/plex-random',
        'description' => 'Zieht zufällig einen Eintrag aus den erlaubten Libraries.',
    ],
    [
        'key' => 'plex-info',
        'label' => '/plex-info',
        'description' => 'Zeigt Basisinfos über den verbundenen Plex Server und den Account.',
    ],
    [
        'key' => 'plex-stats',
        'label' => '/plex-stats',
        'description' => 'Zeigt Library-Zahlen wie Filme, Serien oder andere Inhalte an.',
    ],
    [
        'key' => 'plex-play',
        'label' => '/plex-play {query}',
        'description' => 'Sucht einen Song in Plex-Musiklibraries und spielt ihn direkt im Voice-Channel ab.',
    ],
    [
        'key' => 'plex-recently-added',
        'label' => '/plex-recently-added',
        'description' => 'Zeigt die zuletzt zu Plex hinzugefügten Inhalte (optional nach Library gefiltert).',
    ],
    [
        'key' => 'plex-on-deck',
        'label' => '/plex-on-deck',
        'description' => 'Zeigt deine Plex "On Deck" Liste – Inhalte, die du weiterschauen kannst.',
    ],
];
?>
<div class="plex-page">
    <div class="plex-page__hero">
        <div>
            <div class="plex-page__eyebrow">Integrations</div>
            <h2 class="plex-page__title">Plex</h2>
            <p class="plex-page__subtitle">Verbinde dein Plex Konto mit dem Panel, lade deine Plex Server und gib pro Bot nur die Libraries frei, die wirklich benutzt werden dürfen.</p>
        </div>

        <div class="plex-page__actions">
            <?php if ($plexConnected): ?>
                <form method="POST" action="/dashboard/plex/disconnect" style="display:inline;">
                    <input type="hidden" name="bot_id" value="<?= $plexCurrentBotId ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <button type="submit" class="plex-btn plex-btn--danger">Verbindung trennen</button>
                </form>
            <?php else: ?>
                <a class="plex-btn plex-btn--primary" href="/dashboard/plex/connect<?= $plexCurrentBotId !== null && $plexCurrentBotId > 0 ? '?bot_id=' . $plexCurrentBotId : '' ?>">Mit Plex verbinden</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($plexFlashSuccess !== ''): ?>
        <div class="plex-alert plex-alert--success"><?= htmlspecialchars($plexFlashSuccess, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($plexFlashError !== ''): ?>
        <div class="plex-alert plex-alert--error"><?= htmlspecialchars($plexFlashError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($plexLoadError !== null): ?>
        <div class="plex-alert plex-alert--error"><?= htmlspecialchars($plexLoadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="plex-grid">
        <section class="plex-card">
            <div class="plex-card__header">
                <h3 class="plex-card__title">Verbindungsstatus</h3>
            </div>

            <div class="plex-status">
                <div class="plex-status__badge <?= $plexConnected ? 'is-connected' : 'is-disconnected' ?>">
                    <?= $plexConnected ? 'Verbunden' : 'Nicht verbunden' ?>
                </div>

                <?php if ($plexConnected && $plexAccount !== null): ?>
                    <dl class="plex-status__meta">
                        <div>
                            <dt>Status</dt>
                            <dd><?= htmlspecialchars((string)($plexAccount['status'] ?? 'connected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Client Identifier</dt>
                            <dd>
                                <?php 
                                    $cid = (string)($plexAccount['client_identifier'] ?? '');
                                    echo $cid !== '' 
                                        ? htmlspecialchars(substr($cid, 0, 8) . '••••' . substr($cid, -4), ENT_QUOTES, 'UTF-8')
                                        : '—';
                                ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Zuletzt synchronisiert</dt>
                            <dd><?= htmlspecialchars((string)($plexAccount['last_sync_at'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                <?php else: ?>
                    <div class="plex-empty">Verbinde zuerst deinen Plex Account, damit Server und Libraries geladen werden können.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="plex-card">
            <div class="plex-card__header">
                <h3 class="plex-card__title">Sync Übersicht</h3>
            </div>

            <div class="plex-stats-grid">
                <div class="plex-stat-box">
                    <div class="plex-stat-box__label">Server</div>
                    <div class="plex-stat-box__value"><?= count($plexServers) ?></div>
                </div>
                <div class="plex-stat-box">
                    <div class="plex-stat-box__label">Libraries</div>
                    <div class="plex-stat-box__value"><?= $plexLibrariesCount ?></div>
                </div>
                <div class="plex-stat-box">
                    <div class="plex-stat-box__label">Freigegeben</div>
                    <div class="plex-stat-box__value"><?= $plexAllowedCount ?></div>
                </div>
            </div>
        </section>
    </div>

    <section class="plex-card plex-card--libraries">
        <div class="plex-card__header">
            <div>
                <h3 class="plex-card__title">Allowed Libraries for this Bot</h3>
                <div class="plex-card__subtitle">Bot: <?= htmlspecialchars($plexBotName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
            <div class="plex-card__counter"><?= $plexAllowedCount ?> / <?= $plexLibrariesCount ?> ausgewählt</div>
        </div>

        <?php if ($plexCurrentBotId === null || $plexCurrentBotId <= 0): ?>
            <div class="plex-empty">Bitte wähle zuerst einen Bot aus.</div>
        <?php elseif (!$plexConnected): ?>
            <div class="plex-empty">Verbinde zuerst dein Plex Konto, damit Libraries geladen werden können.</div>
        <?php elseif ($plexLibrariesCount === 0): ?>
            <div class="plex-empty">Es wurden noch keine Plex Libraries gefunden. Prüfe Server-Status und Verbindung.</div>
        <?php else: ?>
            <form method="post" action="/dashboard/plex/libraries/save" class="plex-library-form">
                <input type="hidden" name="bot_id" value="<?= $plexCurrentBotId ?>">

                <details class="plex-library-dropdown" open>
                    <summary class="plex-library-dropdown__summary">
                        <span>Libraries auswählen</span>
                        <span class="plex-library-dropdown__summary-count"><?= $plexAllowedCount ?> / <?= $plexLibrariesCount ?> ausgewählt</span>
                    </summary>

                    <div class="plex-library-dropdown__body">
                        <div class="plex-library-toolbar">
                            <button type="button" class="plex-btn plex-btn--ghost" data-plex-select-all>Alle auswählen</button>
                            <button type="button" class="plex-btn plex-btn--ghost" data-plex-clear-all>Alle abwählen</button>
                        </div>

                        <div class="plex-library-list">
                            <?php foreach ($plexLibraries as $plexLibrary): ?>
                                <?php
                                $plexCompoundKey = trim((string)($plexLibrary['resource_identifier'] ?? '')) . '::' . trim((string)($plexLibrary['library_key'] ?? ''));
                                ?>
                                <label class="plex-library-item">
                                    <input
                                        class="plex-library-item__checkbox"
                                        type="checkbox"
                                        name="allowed_libraries[]"
                                        value="<?= htmlspecialchars($plexCompoundKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        <?= isset($plexAllowedLibraries[$plexCompoundKey]) ? 'checked' : '' ?>
                                    >
                                    <span class="plex-library-item__body">
                                        <span class="plex-library-item__title"><?= htmlspecialchars((string)($plexLibrary['library_title'] ?? 'Unbekannte Library'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <span class="plex-library-item__meta">
                                            <span class="plex-tag"><?= htmlspecialchars((string)($plexLibrary['library_type'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                            <span class="plex-library-item__server"><?= htmlspecialchars((string)($plexLibrary['server_name'] ?? 'Unbekannter Server'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>

                <div class="plex-library-form__actions">
                    <button type="submit" class="plex-btn plex-btn--primary">Libraries speichern</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="plex-card plex-card--commands">
        <div class="plex-card__header">
            <div>
                <h3 class="plex-card__title">Plex Commands</h3>
                <div class="plex-card__subtitle">V1 Grundbefehle für den aktuell gewählten Bot</div>
            </div>
        </div>

        <?php if (!$plexConnected): ?>
            <div class="plex-empty">Verbinde zuerst Plex, bevor du Plex Commands für den Bot nutzen kannst.</div>
        <?php elseif ($plexCurrentBotId === null || $plexCurrentBotId <= 0): ?>
            <div class="plex-empty">Wähle zuerst einen Bot aus.</div>
        <?php elseif ($plexLibrariesCount === 0): ?>
            <div class="plex-empty">Commands werden sichtbar, sobald mindestens eine Plex Library erkannt wurde.</div>
        <?php else: ?>
            <div class="plex-library-list">
                <?php foreach ($plexCommandDefinitions as $plexCommand): ?>
                    <?php $plexCmdEnabled = !empty($plexCommandStates[$plexCommand['key']]); ?>
                    <label class="plex-library-item">
                        <input
                            class="plex-library-item__checkbox"
                            type="checkbox"
                            data-plex-command
                            data-bot-id="<?= $plexCurrentBotId ?>"
                            data-command-key="<?= htmlspecialchars((string)$plexCommand['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            <?= $plexCmdEnabled ? 'checked' : '' ?>
                        >
                        <span class="plex-library-item__body">
                            <span class="plex-library-item__title"><?= htmlspecialchars((string)$plexCommand['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span class="plex-library-item__meta">
                                <span class="plex-library-item__server"><?= htmlspecialchars((string)$plexCommand['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <script>
            (function () {
                document.querySelectorAll('[data-plex-command]').forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        var self       = this;
                        var botId      = parseInt(self.dataset.botId, 10);
                        var commandKey = self.dataset.commandKey;
                        var enabled    = self.checked;

                        self.disabled = true;

                        fetch('/dashboard/plex/command/toggle', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                // Beispiel für CSRF Header
                                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
                            },
                            body: JSON.stringify({ bot_id: botId, command_key: commandKey, enabled: enabled })
                        })
                        .then(function (r) { return r.text(); })
                        .then(function (text) {
                            var data;
                            try { data = JSON.parse(text); } catch (e) {
                                console.error('[plex-toggle] Ungültige JSON-Antwort:', text);
                                self.checked = !enabled;
                                return;
                            }
                            if (!data.ok) {
                                console.error('[plex-toggle] Fehler:', data.error);
                                self.checked = !enabled;
                            }
                        })
                        .catch(function (err) {
                            console.error('[plex-toggle] Netzwerkfehler:', err);
                            self.checked = !enabled;
                        })
                        .finally(function () {
                            self.disabled = false;
                        });
                    });
                });
            })();
            </script>
        <?php endif; ?>
    </section>

    <section class="plex-card plex-card--servers">
        <div class="plex-card__header">
            <h3 class="plex-card__title">Plex Server</h3>
        </div>

        <?php if (!$plexConnected): ?>
            <div class="plex-empty">Noch keine Plex Verbindung vorhanden.</div>
        <?php elseif (count($plexServers) === 0): ?>
            <div class="plex-empty">Verbindung vorhanden, aber es wurden keine Plex Server gefunden.</div>
        <?php else: ?>
            <div class="plex-servers">
                <?php foreach ($plexServers as $server): ?>
                    <article class="plex-server-card">
                        <div class="plex-server-card__top">
                            <div>
                                <h4 class="plex-server-card__title"><?= htmlspecialchars((string)($server['name'] ?? 'Unbekannter Server'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                                <div class="plex-server-card__badges">
                                    <span class="plex-tag"><?= !empty($server['owned']) ? 'Owned' : 'Shared' ?></span>
                                    <span class="plex-tag <?= !empty($server['presence']) ? 'is-online' : 'is-offline' ?>"><?= !empty($server['presence']) ? 'Online' : 'Offline' ?></span>
                                </div>
                            </div>
                        </div>

                        <dl class="plex-server-meta">
                            <div><dt>Produkt</dt><dd><?= htmlspecialchars((string)($server['product'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
                            <div><dt>Version</dt><dd><?= htmlspecialchars((string)($server['product_version'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
                            <div><dt>Plattform</dt><dd><?= htmlspecialchars(trim(((string)($server['platform'] ?? '')) . ' ' . ((string)($server['platform_version'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
                            <div><dt>Gerät</dt><dd><?= htmlspecialchars((string)($server['device'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
                        </dl>

                        <div class="plex-connections">
                            <div class="plex-connections__label">Verbindungen</div>
                            <?php $connections = isset($server['connections']) && is_array($server['connections']) ? $server['connections'] : []; ?>
                            <?php if (count($connections) === 0): ?>
                                <div class="plex-connections__empty">Keine URLs vorhanden.</div>
                            <?php else: ?>
                                <ul class="plex-connections__list">
                                    <?php foreach ($connections as $connection): ?>
                                        <?php if (!is_array($connection)) { continue; } ?>
                                        <li>
                                            <span class="plex-connection-uri"><?= htmlspecialchars((string)($connection['uri'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                            <?php if (!empty($connection['local'])): ?><span class="plex-connection-flag">Local</span><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>