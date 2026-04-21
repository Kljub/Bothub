<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/functions/runner_balance.php';
require_once $projectRoot . '/auth/_db.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Core Runners';

$rows = [];
$error = null;
$messages = [];
$formErrors = [];

$runnerNameInput = '';
$endpointInput = '';

function bh_runner_fetch_json(string $url, int $timeoutSeconds = 8): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init fehlgeschlagen.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain;q=0.9, */*;q=0.8',
        ],
    ]);

    $raw = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $curlError = curl_error($ch);
        throw new RuntimeException('Request fehlgeschlagen: ' . $curlError);
    }

    $decoded = json_decode(trim((string)$raw), true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Endpoint antwortet nicht mit gültigem JSON.');
    }

    return $decoded;
}

function bh_runner_probe_endpoint(string $endpoint): array
{
    $endpoint = rtrim($endpoint, '/');

    $lastError = null;

    try {
        $health = bh_runner_fetch_json($endpoint . '/health');
        if ((bool)($health['ok'] ?? false) !== true) {
            throw new RuntimeException('Health Endpoint meldet ok=false.');
        }

        return [
            'ok' => true,
            'checked_url' => $endpoint . '/health',
            'ping_payload' => null,
            'health_payload' => $health,
            'error' => null,
        ];
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    try {
        $ping = bh_runner_fetch_json($endpoint . '/ping');
        if ((bool)($ping['ok'] ?? false) !== true) {
            throw new RuntimeException('Ping Endpoint meldet ok=false.');
        }

        return [
            'ok' => true,
            'checked_url' => $endpoint . '/ping',
            'ping_payload' => $ping,
            'health_payload' => null,
            'error' => null,
        ];
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    return [
        'ok' => false,
        'checked_url' => $endpoint . '/health',
        'ping_payload' => null,
        'health_payload' => null,
        'error' => $lastError,
    ];
}

function bh_runner_format_bytes(?int $bytes): string
{
    $value = (int)$bytes;
    if ($value <= 0) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    $number = (float)$value;

    while ($number >= 1024 && $unitIndex < count($units) - 1) {
        $number /= 1024;
        $unitIndex++;
    }

    return number_format($number, $unitIndex === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unitIndex];
}

function bh_runner_format_datetime(?string $value): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : '—';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postAction = trim((string)($_POST['post_action'] ?? 'register'));

    // ── Delete runner ──────────────────────────────────────────────────────
    if ($postAction === 'delete') {
        $deleteId = (int)($_POST['runner_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $pdo = bh_pdo();
                $stmt = $pdo->prepare('DELETE FROM core_runners WHERE id = :id');
                $stmt->execute([':id' => $deleteId]);
                $messages[] = 'Core Runner #' . $deleteId . ' wurde gelöscht.';
            } catch (Throwable $e) {
                $formErrors[] = 'Löschen fehlgeschlagen: ' . $e->getMessage();
            }
        } else {
            $formErrors[] = 'Ungültige Runner ID.';
        }
    }

    // ── Rebalance bots across runners ─────────────────────────────────────
    elseif ($postAction === 'rebalance') {
        try {
            $pdo = bh_pdo();
            $result = bh_runner_rebalance($pdo);
            if (isset($result['error'])) {
                $formErrors[] = $result['error'];
            } else {
                $moved = (int)($result['moved'] ?? 0);
                $messages[] = 'Rebalancing abgeschlossen. ' . $moved . ' Bot(s) neu zugewiesen.';
            }
        } catch (Throwable $e) {
            $formErrors[] = 'Rebalancing fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── Ping runner ────────────────────────────────────────────────────────
    elseif ($postAction === 'ping') {
        $pingId = (int)($_POST['runner_id'] ?? 0);
        if ($pingId > 0) {
            try {
                $pdo = bh_pdo();
                $stmt = $pdo->prepare('SELECT id, endpoint FROM core_runners WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $pingId]);
                $runnerRow = $stmt->fetch();

                if (!is_array($runnerRow)) {
                    $formErrors[] = 'Core Runner #' . $pingId . ' nicht gefunden.';
                } else {
                    $ep = trim((string)($runnerRow['endpoint'] ?? ''));
                    $probe = bh_runner_probe_endpoint($ep);
                    $newStatus = $probe['ok'] ? 'online' : 'offline';

                    $upd = $pdo->prepare(
                        "UPDATE core_runners SET status = :status, last_ping = NOW(), updated_at = NOW() WHERE id = :id"
                    );
                    $upd->execute([':status' => $newStatus, ':id' => $pingId]);

                    $messages[] = 'Ping #' . $pingId . ': ' . $newStatus . ($probe['error'] ? ' — ' . $probe['error'] : '');
                }
            } catch (Throwable $e) {
                $formErrors[] = 'Ping fehlgeschlagen: ' . $e->getMessage();
            }
        } else {
            $formErrors[] = 'Ungültige Runner ID.';
        }
    }

    // ── Register / update runner ───────────────────────────────────────────
    else {
    $runnerNameInput = trim((string)($_POST['runner_name'] ?? ''));
    $endpointInput = trim((string)($_POST['endpoint'] ?? ''));

    if ($runnerNameInput === '') {
        $formErrors[] = 'Runner Name fehlt.';
    }

    if ($endpointInput === '') {
        $formErrors[] = 'Endpoint URL fehlt.';
    } elseif (!preg_match('~^https?://~i', $endpointInput) || filter_var($endpointInput, FILTER_VALIDATE_URL) === false) {
        $formErrors[] = 'Endpoint URL ist ungültig.';
    } else {
        $endpointInput = rtrim($endpointInput, '/');
    }

    $probe = [
        'ok' => false,
        'checked_url' => '',
        'error' => null,
    ];

    if (!$formErrors) {
        $probe = bh_runner_probe_endpoint($endpointInput);

        if (!$probe['ok']) {
            $formErrors[] = 'Core Endpoint konnte nicht erfolgreich geprüft werden.' . ($probe['checked_url'] !== '' ? ' Letzter Check: ' . $probe['checked_url'] : '');
            if (is_string($probe['error']) && $probe['error'] !== '') {
                $formErrors[] = $probe['error'];
            }
        }
    }

    if (!$formErrors) {
        try {
            $pdo = bh_pdo();

            $stmt = $pdo->prepare('SELECT id FROM core_runners WHERE runner_name = :runner_name LIMIT 1');
            $stmt->execute([
                ':runner_name' => $runnerNameInput,
            ]);

            $existing = $stmt->fetch();

            if (is_array($existing) && isset($existing['id'])) {
                $runnerId = (int)$existing['id'];

                $update = $pdo->prepare("
                    UPDATE core_runners
                    SET endpoint = :endpoint,
                        status = 'online',
                        last_ping = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $update->execute([
                    ':endpoint' => $endpointInput,
                    ':id' => $runnerId,
                ]);

                $messages[] = 'Core Runner wurde aktualisiert.';
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO core_runners
                    (
                        runner_name,
                        endpoint,
                        status,
                        last_ping,
                        created_at,
                        updated_at
                    )
                    VALUES
                    (
                        :runner_name,
                        :endpoint,
                        'online',
                        NOW(),
                        NOW(),
                        NOW()
                    )
                ");
                $insert->execute([
                    ':runner_name' => $runnerNameInput,
                    ':endpoint' => $endpointInput,
                ]);

                $messages[] = 'Core Runner wurde erfolgreich hinzugefügt.';
            }

            $runnerNameInput = '';
            $endpointInput = '';
        } catch (Throwable $e) {
            $formErrors[] = 'DB Fehler: ' . $e->getMessage();
        }
    }
    } // end else (register/update)
}

try {
    $pdo = bh_pdo();

    $stmt = $pdo->query("
        SELECT
            id,
            runner_name,
            endpoint,
            status,
            last_ping,
            created_at,
            updated_at
        FROM core_runners
        ORDER BY runner_name ASC, id ASC
    ");

    $result = $stmt->fetchAll();
    if (is_array($result)) {
        $rows = $result;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$runnerCards = [];

foreach ($rows as $row) {
    $endpoint = trim((string)($row['endpoint'] ?? ''));
    $runtime = [
        'ok' => false,
        'status' => 'offline',
        'checked_url' => '',
        'error' => null,
        'payload' => null,
    ];

    if ($endpoint !== '') {
        $probe = bh_runner_probe_endpoint($endpoint);
        $runtime['ok'] = (bool)$probe['ok'];
        $runtime['checked_url'] = (string)$probe['checked_url'];
        $runtime['error'] = is_string($probe['error'] ?? null) ? (string)$probe['error'] : null;
        $runtime['payload'] = is_array($probe['health_payload'] ?? null)
            ? $probe['health_payload']
            : (is_array($probe['ping_payload'] ?? null) ? $probe['ping_payload'] : null);

        if ($runtime['ok']) {
            $runtime['status'] = 'online';
        }
    }

    $payload = is_array($runtime['payload']) ? $runtime['payload'] : [];
    $memory = is_array($payload['memory'] ?? null) ? $payload['memory'] : [];
    $cpu = is_array($payload['cpu'] ?? null) ? $payload['cpu'] : [];
    $bots = is_array($payload['bots'] ?? null) ? $payload['bots'] : [];
    $system = is_array($payload['system'] ?? null) ? $payload['system'] : [];

    $runnerCards[] = [
        'db' => $row,
        'runtime' => $runtime,
        'summary' => [
            'uptime_seconds' => (int)($payload['uptime_seconds'] ?? 0),
            'rss' => bh_runner_format_bytes((int)($memory['rss_bytes'] ?? 0)),
            'heap_used' => bh_runner_format_bytes((int)($memory['heap_used_bytes'] ?? 0)),
            'heap_total' => bh_runner_format_bytes((int)($memory['heap_total_bytes'] ?? 0)),
            'load_1' => isset($cpu['loadavg_1']) ? (string)$cpu['loadavg_1'] : '—',
            'load_5' => isset($cpu['loadavg_5']) ? (string)$cpu['loadavg_5'] : '—',
            'load_15' => isset($cpu['loadavg_15']) ? (string)$cpu['loadavg_15'] : '—',
            'bots_running' => (int)($bots['running'] ?? 0),
            'bots_desired' => (int)($bots['desired_running'] ?? 0),
            'bots_total' => (int)($bots['total_known'] ?? 0),
            'hostname' => trim((string)($system['hostname'] ?? '')),
            'platform' => trim((string)($system['platform'] ?? '')),
            'arch' => trim((string)($system['arch'] ?? '')),
        ],
    ];
}

// ── Bot distribution data ─────────────────────────────────────────────────
$distribution = ['runners' => [], 'unassigned_count' => 0];
try {
    $pdo = bh_pdo();
    $distribution = bh_runner_get_distribution($pdo);
} catch (Throwable) {}

$totalAssigned = 0;
foreach ($distribution['runners'] as $dr) {
    $totalAssigned += (int)($dr['bot_count'] ?? 0);
}
$totalBots = $totalAssigned + (int)($distribution['unassigned_count'] ?? 0);

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Core Runners
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Übersicht aller registrierten Core Runner und Live-Health über <code>/health</code>.
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                <?= h($message) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($error !== null): ?>
            <div class="mb-6 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                DB Fehler: <?= h($error) ?>
            </div>
        <?php endif; ?>

        <?php foreach ($formErrors as $formError): ?>
            <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                <?= h($formError) ?>
            </div>
        <?php endforeach; ?>

        <div class="grid grid-cols-12 gap-6 mb-6">
            <div class="col-span-full xl:col-span-5 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Core Runner hinzufügen</h2>
                </div>

                <div class="p-5">
                    <form method="post" autocomplete="off">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Runner Name
                            </label>
                            <input
                                type="text"
                                name="runner_name"
                                value="<?= h($runnerNameInput) ?>"
                                class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                                placeholder="bothub-core-1"
                                required
                            >
                        </div>

                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Endpoint URL
                            </label>
                            <input
                                type="text"
                                name="endpoint"
                                value="<?= h($endpointInput) ?>"
                                class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                                placeholder="http://zephyral.xyz:50039"
                                required
                            >
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Der Endpoint wird bevorzugt per <code>/health</code> geprüft und fällt sonst auf <code>/ping</code> zurück.
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="btn bg-violet-500 hover:bg-violet-600 text-white"
                        >
                            Core Runner prüfen & hinzufügen
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-span-full xl:col-span-7 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Hinweis</h2>
                </div>

                <div class="p-5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Die Tabelle liest die Runner live über ihren Endpoint aus. Dadurch siehst du RAM, Load und Bot-Zahlen direkt im Admin, ohne zusätzliche DB-Spalten anzulegen.
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Bot Distribution ─────────────────────────────────────────── -->
        <div class="grid grid-cols-12 gap-6 mb-6">
            <div class="col-span-full bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60 flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Bot-Verteilung</h2>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            <?= (int)$totalBots ?> Bot(s) gesamt
                            <?php if ((int)($distribution['unassigned_count'] ?? 0) > 0): ?>
                                · <span class="text-amber-500 dark:text-amber-400"><?= (int)$distribution['unassigned_count'] ?> nicht zugewiesen</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (count($distribution['runners']) >= 2): ?>
                        <form method="post">
                            <input type="hidden" name="post_action" value="rebalance">
                            <button type="submit"
                                    class="btn bg-violet-500 hover:bg-violet-600 text-white text-xs py-1.5 px-4"
                                    onclick="return confirm('Bots gleichmäßig auf alle Runner verteilen?')">
                                Smart Rebalance
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="p-5">
                    <?php if (count($distribution['runners']) === 0): ?>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Keine Runner registriert. Füge zuerst einen Core Runner hinzu.
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($distribution['runners'] as $dr):
                                $botCount  = (int)($dr['bot_count'] ?? 0);
                                $barPct    = $totalBots > 0 ? round($botCount / $totalBots * 100) : 0;
                                $runStatus = trim((string)($dr['status'] ?? 'unknown'));
                                $statusDot = match ($runStatus) {
                                    'online'  => 'bg-emerald-500',
                                    'offline' => 'bg-rose-500',
                                    default   => 'bg-gray-400',
                                };
                            ?>
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block w-2 h-2 rounded-full <?= h($statusDot) ?>"></span>
                                            <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                                <?= h((string)($dr['runner_name'] ?? '')) ?>
                                            </span>
                                        </div>
                                        <span class="text-sm text-gray-500 dark:text-gray-400">
                                            <?= $botCount ?> Bot<?= $botCount !== 1 ? 's' : '' ?>
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                        <div class="bg-violet-500 h-2 rounded-full transition-all"
                                             style="width: <?= $barPct ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if ((int)($distribution['unassigned_count'] ?? 0) > 0):
                                $unassigned = (int)$distribution['unassigned_count'];
                                $unassignedPct = $totalBots > 0 ? round($unassigned / $totalBots * 100) : 0;
                            ?>
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block w-2 h-2 rounded-full bg-amber-400"></span>
                                            <span class="text-sm font-medium text-amber-600 dark:text-amber-400">
                                                Nicht zugewiesen
                                            </span>
                                        </div>
                                        <span class="text-sm text-amber-600 dark:text-amber-400">
                                            <?= $unassigned ?> Bot<?= $unassigned !== 1 ? 's' : '' ?>
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                        <div class="bg-amber-400 h-2 rounded-full"
                                             style="width: <?= $unassignedPct ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (count($distribution['runners']) >= 2): ?>
                            <?php
                            $counts = array_column($distribution['runners'], 'bot_count');
                            $maxCount = count($counts) > 0 ? max(array_map('intval', $counts)) : 0;
                            $minCount = count($counts) > 0 ? min(array_map('intval', $counts)) : 0;
                            $isBalanced = ($maxCount - $minCount) <= 1;
                            ?>
                            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/60">
                                <div class="flex items-center gap-2 text-xs <?= $isBalanced ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' ?>">
                                    <?php if ($isBalanced): ?>
                                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 16 16" fill="currentColor"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>
                                        Gut verteilt — maximale Differenz: <?= ($maxCount - $minCount) ?> Bot(s)
                                    <?php else: ?>
                                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 16 16" fill="currentColor"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.553.553 0 0 1-1.1 0L7.1 4.995z"/></svg>
                                        Ungleichmäßig — Differenz: <?= ($maxCount - $minCount) ?> Bot(s). Klicke "Smart Rebalance" zum Ausgleichen.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Runner Table ──────────────────────────────────────────────── -->
        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-full bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Registrierte Runner</h2>
                </div>

                <div class="p-5 overflow-x-auto">
                    <table class="table-auto w-full dark:text-gray-300">
                        <thead class="text-xs uppercase text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/50 rounded-xs">
                            <tr>
                                <th class="p-2 whitespace-nowrap text-left">ID</th>
                                <th class="p-2 whitespace-nowrap text-left">Runner</th>
                                <th class="p-2 whitespace-nowrap text-left">Endpoint</th>
                                <th class="p-2 whitespace-nowrap text-left">Status</th>
                                <th class="p-2 whitespace-nowrap text-left">Zugewiesen</th>
                                <th class="p-2 whitespace-nowrap text-left">Live Bots</th>
                                <th class="p-2 whitespace-nowrap text-left">RAM RSS</th>
                                <th class="p-2 whitespace-nowrap text-left">Heap</th>
                                <th class="p-2 whitespace-nowrap text-left">Load</th>
                                <th class="p-2 whitespace-nowrap text-left">Uptime</th>
                                <th class="p-2 whitespace-nowrap text-left">Last Ping</th>
                                <th class="p-2 whitespace-nowrap text-left">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700/60">
                            <?php if (count($runnerCards) === 0): ?>
                                <tr>
                                    <td class="p-2 whitespace-nowrap" colspan="11">
                                        Keine Core Runner gefunden.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($runnerCards as $card): ?>
                                    <?php
                                    $row = $card['db'];
                                    $runtime = $card['runtime'];
                                    $summary = $card['summary'];

                                    $status = $runtime['status'];
                                    $statusClass = 'text-gray-500 dark:text-gray-400';

                                    if ($status === 'online') {
                                        $statusClass = 'text-emerald-600 dark:text-emerald-400';
                                    } elseif ($status === 'offline') {
                                        $statusClass = 'text-rose-600 dark:text-rose-400';
                                    }
                                    ?>
                                    <tr>
                                        <td class="p-2 whitespace-nowrap text-gray-800 dark:text-gray-100">
                                            <?= (int)($row['id'] ?? 0) ?>
                                        </td>
                                        <td class="p-2">
                                            <div class="font-medium text-gray-800 dark:text-gray-100">
                                                <?= h((string)($row['runner_name'] ?? '')) ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <?= h($summary['hostname'] !== '' ? $summary['hostname'] : '—') ?>
                                                <?php if ($summary['platform'] !== '' || $summary['arch'] !== ''): ?>
                                                    · <?= h(trim($summary['platform'] . ' ' . $summary['arch'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <div class="break-all text-gray-700 dark:text-gray-200">
                                                <?= h((string)($row['endpoint'] ?? '')) ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                Check: <?= h((string)($runtime['checked_url'] ?? '—')) ?>
                                            </div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <div class="<?= h($statusClass) ?> font-medium">
                                                <?= h($status) ?>
                                            </div>
                                            <?php if (!$runtime['ok'] && is_string($runtime['error']) && $runtime['error'] !== ''): ?>
                                                <div class="text-xs text-rose-500 dark:text-rose-400 mt-1 max-w-xs">
                                                    <?= h($runtime['error']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <?php
                                        // DB-assigned count for this runner
                                        $assignedCount = 0;
                                        foreach ($distribution['runners'] as $dr) {
                                            if (trim((string)($dr['runner_name'] ?? '')) === trim((string)($row['runner_name'] ?? ''))) {
                                                $assignedCount = (int)($dr['bot_count'] ?? 0);
                                                break;
                                            }
                                        }
                                        ?>
                                        <td class="p-2 whitespace-nowrap">
                                            <div class="text-gray-800 dark:text-gray-100 font-medium">
                                                <?= $assignedCount ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">DB</div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <div class="text-gray-800 dark:text-gray-100">
                                                <?= (int)$summary['bots_running'] ?> running
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <?= (int)$summary['bots_desired'] ?> desired / <?= (int)$summary['bots_total'] ?> total
                                            </div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <?= h($summary['rss']) ?>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <div><?= h($summary['heap_used']) ?></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                / <?= h($summary['heap_total']) ?>
                                            </div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <div><?= h($summary['load_1']) ?></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <?= h($summary['load_5']) ?> / <?= h($summary['load_15']) ?>
                                            </div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <?= (int)$summary['uptime_seconds'] ?>s
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <?= h(bh_runner_format_datetime((string)($row['last_ping'] ?? ''))) ?>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <div class="flex gap-2 items-center">
                                                <form method="post">
                                                    <input type="hidden" name="post_action" value="ping">
                                                    <input type="hidden" name="runner_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn bg-sky-500 hover:bg-sky-600 text-white text-xs py-1 px-3">
                                                        Ping
                                                    </button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('Core Runner &quot;<?= h((string)($row['runner_name'] ?? '')) ?>&quot; wirklich löschen?')">
                                                    <input type="hidden" name="post_action" value="delete">
                                                    <input type="hidden" name="runner_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn bg-rose-500 hover:bg-rose-600 text-white text-xs py-1 px-3">
                                                        Löschen
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';