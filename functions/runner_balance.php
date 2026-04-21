<?php
declare(strict_types=1);

/**
 * Returns the current bot distribution across all registered core runners.
 *
 * Returns an array of:
 *   ['runner_name' => string, 'endpoint' => string, 'bot_count' => int, 'bots' => array]
 */
function bh_runner_get_distribution(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            cr.id,
            cr.runner_name,
            cr.endpoint,
            cr.status,
            COUNT(bi.id) AS bot_count
        FROM core_runners cr
        LEFT JOIN bot_instances bi
            ON bi.assigned_runner_name = cr.runner_name
           AND bi.is_active = 1
        GROUP BY cr.id, cr.runner_name, cr.endpoint, cr.status
        ORDER BY bot_count DESC, cr.runner_name ASC
    ");

    $runners = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Attach unassigned bots count
    $unassignedStmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM bot_instances
        WHERE is_active = 1
          AND (assigned_runner_name IS NULL OR assigned_runner_name = '')
    ");
    $unassignedCount = $unassignedStmt ? (int)($unassignedStmt->fetchColumn() ?? 0) : 0;

    return [
        'runners'          => is_array($runners) ? $runners : [],
        'unassigned_count' => $unassignedCount,
    ];
}

/**
 * Rebalances bots evenly across all registered core runners.
 *
 * Algorithm:
 *  1. Collect all active bots with their current runner assignment (including NULL).
 *  2. Round-robin assign each bot to a runner, sorted by runner ID (stable order).
 *  3. Only UPDATE rows where the assignment actually changes.
 *  4. Returns a summary ['moved' => int, 'distribution' => [...runner => count]].
 */
function bh_runner_rebalance(PDO $pdo): array
{
    // 1. Get all active runners ordered deterministically
    $runnerStmt = $pdo->query("
        SELECT id, runner_name FROM core_runners ORDER BY id ASC
    ");
    $runners = $runnerStmt ? $runnerStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (count($runners) === 0) {
        return ['moved' => 0, 'distribution' => [], 'error' => 'Keine Runner registriert.'];
    }

    $runnerNames = array_column($runners, 'runner_name');
    $runnerCount = count($runnerNames);

    // 2. Get all active bots
    $botStmt = $pdo->query("
        SELECT id, assigned_runner_name
        FROM bot_instances
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    $bots = $botStmt ? $botStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (count($bots) === 0) {
        return ['moved' => 0, 'distribution' => array_fill_keys($runnerNames, 0)];
    }

    // 3. Round-robin assignment
    $moved = 0;
    $distribution = array_fill_keys($runnerNames, 0);

    $updateStmt = $pdo->prepare("UPDATE bot_instances SET assigned_runner_name = ? WHERE id = ?");

    foreach ($bots as $index => $bot) {
        $targetRunner = $runnerNames[$index % $runnerCount];
        $distribution[$targetRunner] += 1;

        $currentRunner = (string)($bot['assigned_runner_name'] ?? '');
        if ($currentRunner !== $targetRunner) {
            $updateStmt->execute([$targetRunner, (int)$bot['id']]);
            $moved++;
        }
    }

    return [
        'moved'        => $moved,
        'distribution' => $distribution,
    ];
}
