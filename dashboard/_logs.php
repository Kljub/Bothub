<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo    = bh_get_pdo();
$userId = (int)($_SESSION['user_id'] ?? 0);
$botId  = $currentBotId ?? 0;

if ($botId <= 0) {
    echo '<p class="text-red-500 p-6">Kein Bot ausgewählt.</p>';
    return;
}

$ownerCheck = $pdo->prepare('SELECT id FROM bot_instances WHERE id = ? AND owner_user_id = ? LIMIT 1');
$ownerCheck->execute([$botId, $userId]);
if (!$ownerCheck->fetch()) {
    echo '<p class="text-red-500 p-6">Zugriff verweigert.</p>';
    return;
}

// Handle clear-logs POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    $pdo->prepare('DELETE FROM bot_logs WHERE bot_id = ?')->execute([$botId]);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Filter
$level  = in_array($_GET['level'] ?? '', ['debug', 'info', 'warn', 'error']) ? $_GET['level'] : '';
$limit  = 200;

$sql    = 'SELECT id, level, message, context_json, created_at FROM bot_logs WHERE bot_id = ?';
$params = [$botId];
if ($level !== '') {
    $sql    .= ' AND level = ?';
    $params[] = $level;
}
$sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Counts per level
$counts = $pdo->prepare('SELECT level, COUNT(*) as cnt FROM bot_logs WHERE bot_id = ? GROUP BY level');
$counts->execute([$botId]);
$levelCounts = [];
foreach ($counts->fetchAll() as $row) $levelCounts[$row['level']] = (int)$row['cnt'];
$totalCount = array_sum($levelCounts);

$levelColors = [
    'debug' => ['bg' => 'rgba(99,102,241,.15)',  'text' => '#818cf8', 'badge' => 'rgba(99,102,241,.25)'],
    'info'  => ['bg' => 'rgba(52,211,153,.12)',  'text' => '#3fb950', 'badge' => 'rgba(16,185,129,.2)'],
    'warn'  => ['bg' => 'rgba(251,191,36,.12)',  'text' => '#e3b341', 'badge' => 'rgba(245,158,11,.2)'],
    'error' => ['bg' => 'rgba(248,81,73,.12)',   'text' => '#f85149', 'badge' => 'rgba(239,68,68,.2)'],
];
$levelTwBorder = [
    'debug' => 'border-indigo-500/30',
    'info'  => 'border-emerald-500/30',
    'warn'  => 'border-amber-500/30',
    'error' => 'border-red-500/30',
];
$levelLabels = ['debug' => 'Debug', 'info' => 'Info', 'warn' => 'Warn', 'error' => 'Error'];
$baseUrl = '?view=logs&bot_id=' . $botId;
?>

<div class="px-4 sm:px-6 lg:px-8 py-6 w-full max-w-full box-border">

    <!-- ── Page header ─────────────────────────────────────────────────────── -->
    <div class="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-100 mb-1">Logs</h1>
            <p class="text-sm text-gray-500">Die letzten <?= $limit ?> Einträge · <?= $totalCount ?> gesamt</p>
        </div>
        <form method="post" onsubmit="return confirm('Alle Logs löschen?');">
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit"
                class="text-sm px-3 py-1.5 rounded-lg border border-gray-700 bg-gray-800 hover:bg-gray-700 text-gray-300 cursor-pointer transition">
                Logs löschen
            </button>
        </form>
    </div>

    <!-- ── Level filter tabs ───────────────────────────────────────────────── -->
    <div class="flex gap-2 mb-5 flex-wrap items-center">
        <a href="<?= $baseUrl ?>"
           class="px-3 py-1.5 rounded-lg text-xs font-semibold no-underline transition border
                  <?= $level === '' ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-gray-800 border-gray-700 text-gray-400 hover:text-gray-200' ?>">
            Alle <span class="opacity-60">(<?= $totalCount ?>)</span>
        </a>
        <?php foreach (['error','warn','info','debug'] as $lvl):
            $cnt = $levelCounts[$lvl] ?? 0;
            $isActive = $level === $lvl;
            $col = $levelColors[$lvl];
        ?>
        <a href="<?= $baseUrl ?>&level=<?= $lvl ?>"
           style="<?= $isActive ? "background:{$col['bg']};border-color:{$col['text']};color:{$col['text']}" : '' ?>"
           class="px-3 py-1.5 rounded-lg text-xs font-semibold no-underline transition border
                  <?= $isActive ? '' : 'bg-gray-800 border-gray-700 text-gray-400 hover:text-gray-200' ?>">
            <?= $levelLabels[$lvl] ?> <span class="opacity-60">(<?= $cnt ?>)</span>
        </a>
        <?php endforeach; ?>
        <button onclick="location.reload()"
            class="ml-auto px-3 py-1.5 rounded-lg text-xs font-semibold border border-gray-700 bg-gray-800 text-gray-400 hover:text-gray-200 cursor-pointer transition">
            ↻ Reload
        </button>
    </div>

    <?php if (empty($logs)): ?>
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-10 text-center text-gray-500 text-sm">
        Keine Log-Einträge vorhanden.
    </div>

    <?php else: ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         DESKTOP VIEW — table (hidden on mobile)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="hidden md:block rounded-xl border border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#21262d;">
                        <th style="padding:10px 16px; font-weight:600; color:#8b949e; text-align:left; white-space:nowrap;">Zeit</th>
                        <th style="padding:10px 12px; font-weight:600; color:#8b949e; text-align:left; width:70px;">Level</th>
                        <th style="padding:10px 12px; font-weight:600; color:#8b949e; text-align:left;">Nachricht</th>
                        <th style="padding:10px 16px; font-weight:600; color:#8b949e; text-align:left; width:200px;">Kontext</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $i => $log):
                    $col    = $levelColors[$log['level']] ?? $levelColors['info'];
                    $ctx    = $log['context_json'] ? json_decode($log['context_json'], true) : null;
                    $rowBg  = $i % 2 === 0 ? '#161b22' : '#0d1117';
                ?>
                    <tr style="background:<?= $rowBg ?>; border-top:1px solid #21262d; vertical-align:top;">
                        <td style="padding:10px 16px; color:#8b949e; white-space:nowrap; font-family:monospace; font-size:12px;">
                            <?= htmlspecialchars(date('d.m.y H:i:s', strtotime($log['created_at']))) ?>
                        </td>
                        <td style="padding:10px 12px;">
                            <span style="display:inline-block; padding:2px 8px; border-radius:5px; font-weight:700; font-size:11px; letter-spacing:.04em;
                                background:<?= $col['badge'] ?>; color:<?= $col['text'] ?>; text-transform:uppercase;">
                                <?= htmlspecialchars($log['level']) ?>
                            </span>
                        </td>
                        <td style="padding:10px 12px; color:#cdd9e5; word-break:break-word;">
                            <?= htmlspecialchars($log['message']) ?>
                        </td>
                        <td style="padding:10px 16px;">
                            <?php if ($ctx): ?>
                            <details>
                                <summary style="color:#8b949e; cursor:pointer; font-size:12px; list-style:none;">Details ▾</summary>
                                <pre style="margin:6px 0 0; padding:8px; background:#0d1117; border-radius:6px; font-size:11px; color:#8b949e; overflow:auto; white-space:pre-wrap; word-break:break-all;"><?= htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </details>
                            <?php else: ?>
                            <span style="color:#30363d;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         MOBILE VIEW — cards (hidden on desktop)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="md:hidden space-y-2">
        <?php foreach ($logs as $log):
            $col    = $levelColors[$log['level']] ?? $levelColors['info'];
            $ctx    = $log['context_json'] ? json_decode($log['context_json'], true) : null;
            $border = $levelTwBorder[$log['level']] ?? 'border-gray-700';
        ?>
        <div class="rounded-xl border <?= $border ?> bg-gray-900 overflow-hidden">

            <!-- Card header: level badge + timestamp -->
            <div class="flex items-center justify-between px-3 py-2 border-b border-gray-800 gap-2">
                <span style="display:inline-flex; align-items:center; padding:2px 10px; border-radius:6px; font-weight:700; font-size:11px; letter-spacing:.05em;
                    background:<?= $col['badge'] ?>; color:<?= $col['text'] ?>; text-transform:uppercase;">
                    <?= htmlspecialchars($log['level']) ?>
                </span>
                <span class="text-xs text-gray-500 font-mono tabular-nums shrink-0">
                    <?= htmlspecialchars(date('d.m.y H:i:s', strtotime($log['created_at']))) ?>
                </span>
            </div>

            <!-- Message -->
            <div class="px-3 py-2 text-sm text-gray-200 break-words leading-relaxed">
                <?= htmlspecialchars($log['message']) ?>
            </div>

            <!-- Context (collapsible) -->
            <?php if ($ctx): ?>
            <div class="border-t border-gray-800">
                <details>
                    <summary class="px-3 py-1.5 text-xs text-gray-500 cursor-pointer select-none list-none hover:text-gray-300 transition">
                        Kontext ▾
                    </summary>
                    <pre class="mx-3 mb-3 mt-1 p-2 bg-gray-950 rounded-lg text-xs text-gray-400 overflow-x-auto whitespace-pre-wrap break-all"><?= htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>
