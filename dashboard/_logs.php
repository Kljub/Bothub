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

$logs = $pdo->prepare($sql);
$logs->execute($params);
$logs = $logs->fetchAll();

// Counts per level
$counts = $pdo->prepare('SELECT level, COUNT(*) as cnt FROM bot_logs WHERE bot_id = ? GROUP BY level');
$counts->execute([$botId]);
$levelCounts = [];
foreach ($counts->fetchAll() as $row) $levelCounts[$row['level']] = (int)$row['cnt'];
$totalCount = array_sum($levelCounts);

$levelColors = [
    'debug' => ['bg' => '#1e2a3a', 'text' => '#6ea8fe', 'badge' => '#0d2240'],
    'info'  => ['bg' => '#1a2e1a', 'text' => '#3fb950', 'badge' => '#0d2a0d'],
    'warn'  => ['bg' => '#2e2700', 'text' => '#e3b341', 'badge' => '#2a2100'],
    'error' => ['bg' => '#2e1a1a', 'text' => '#f85149', 'badge' => '#2a0d0d'],
];
$levelLabels = ['debug' => 'Debug', 'info' => 'Info', 'warn' => 'Warn', 'error' => 'Error'];
?>

<div style="padding: 28px 32px; width: 100%; box-sizing: border-box;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; gap:16px; flex-wrap:wrap;">
        <div>
            <h1 style="font-size:22px; font-weight:700; color:#e6edf3; margin:0 0 4px;">Logs</h1>
            <p style="color:#8b949e; font-size:13px; margin:0;">Die letzten <?= $limit ?> Einträge · <?= $totalCount ?> gesamt</p>
        </div>
        <form method="post" onsubmit="return confirm('Alle Logs löschen?');" style="display:inline;">
            <input type="hidden" name="action" value="clear_logs">
            <button type="submit" style="background:#21262d; border:1px solid #3d444d; color:#cdd9e5; padding:7px 16px; border-radius:8px; font-size:13px; cursor:pointer;">
                Logs löschen
            </button>
        </form>
    </div>

    <!-- Level filter tabs -->
    <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
        <?php
        $baseUrl = '?view=logs&bot_id=' . $botId;
        $allActive = $level === '';
        ?>
        <a href="<?= $baseUrl ?>" style="padding:6px 14px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none;
            background:<?= $allActive ? '#6366f1' : '#21262d' ?>; color:<?= $allActive ? '#fff' : '#8b949e' ?>; border:1px solid <?= $allActive ? '#6366f1' : '#3d444d' ?>;">
            Alle <span style="opacity:.7;">(<?= $totalCount ?>)</span>
        </a>
        <?php foreach (['error','warn','info','debug'] as $lvl):
            $cnt = $levelCounts[$lvl] ?? 0;
            $isActive = $level === $lvl;
            $col = $levelColors[$lvl];
        ?>
        <a href="<?= $baseUrl ?>&level=<?= $lvl ?>" style="padding:6px 14px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none;
            background:<?= $isActive ? $col['bg'] : '#21262d' ?>; color:<?= $isActive ? $col['text'] : '#8b949e' ?>; border:1px solid <?= $isActive ? $col['text'] : '#3d444d' ?>;">
            <?= $levelLabels[$lvl] ?> <span style="opacity:.7;">(<?= $cnt ?>)</span>
        </a>
        <?php endforeach; ?>
        <button onclick="location.reload()" style="margin-left:auto; background:#21262d; border:1px solid #3d444d; color:#8b949e; padding:6px 14px; border-radius:8px; font-size:13px; cursor:pointer;">
            ↻ Reload
        </button>
    </div>

    <!-- Log list -->
    <?php if (empty($logs)): ?>
    <div style="background:#161b22; border:1px solid #30363d; border-radius:12px; padding:40px; text-align:center; color:#8b949e;">
        Keine Log-Einträge vorhanden.
    </div>
    <?php else: ?>
    <div style="background:#161b22; border:1px solid #30363d; border-radius:12px; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#21262d; color:#8b949e; text-align:left;">
                    <th style="padding:10px 16px; font-weight:600; white-space:nowrap;">Zeit</th>
                    <th style="padding:10px 12px; font-weight:600; width:70px;">Level</th>
                    <th style="padding:10px 12px; font-weight:600;">Nachricht</th>
                    <th style="padding:10px 16px; font-weight:600; width:200px;">Kontext</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $i => $log):
                $col = $levelColors[$log['level']] ?? $levelColors['info'];
                $ctx = $log['context_json'] ? json_decode($log['context_json'], true) : null;
                $rowBg = $i % 2 === 0 ? '#161b22' : '#0d1117';
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
                            <pre style="margin:6px 0 0; padding:8px; background:#0d1117; border-radius:6px; font-size:11px; color:#8b949e; overflow:auto;"><?= htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
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
    <?php endif; ?>

</div>
