<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

require_once dirname(__DIR__, 2) . '/auth/_db.php';

function cc_share_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function cc_share_generate_code(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $len = strlen($chars) - 1;

    $parts = [4, 6, 6, 4];
    $segments = [];
    foreach ($parts as $partLen) {
        $seg = '';
        for ($i = 0; $i < $partLen; $i++) {
            $seg .= $chars[random_int(0, $len)];
        }
        $segments[] = $seg;
    }

    return implode('-', $segments);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cc_share_fail(405, 'method_not_allowed');
}

// CSRF check
$csrfHeader  = (string)($_SERVER['HTTP_X_CC_CSRF'] ?? '');
$csrfSession = (string)($_SESSION['bh_cc_csrf'] ?? '');
if ($csrfSession === '' || !hash_equals($csrfSession, $csrfHeader)) {
    cc_share_fail(403, 'invalid_csrf');
}

$body   = (string)file_get_contents('php://input');
$data   = json_decode($body, true);
$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';

try {
    $pdo = bh_pdo();

    // Ensure share codes table exists (auto-migration)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS command_share_codes (
          id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          code         VARCHAR(25)     NOT NULL,
          payload_json LONGTEXT        NOT NULL,
          created_by   BIGINT UNSIGNED NULL,
          created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at   DATETIME        NULL DEFAULT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_command_share_code (code),
          KEY idx_command_share_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ── Generate share code ─────────────────────────────────────────────────
    if ($action === 'generate_share_code') {
        $commandId = isset($data['command_id']) ? (int)$data['command_id'] : 0;
        if ($commandId <= 0) {
            cc_share_fail(400, 'invalid_command_id');
        }

        // Load command + builder (verify ownership via userId→bot→command)
        $stmt = $pdo->prepare(
            'SELECT cc.id, cc.name, cc.slash_name, cc.description, cc.group_name,
                    ccb.builder_json, ccb.builder_version
             FROM bot_custom_commands cc
             INNER JOIN bot_instances bi ON bi.id = cc.bot_id
             LEFT JOIN bot_custom_command_builders ccb ON ccb.custom_command_id = cc.id
             WHERE cc.id = :id
               AND bi.owner_user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([':id' => $commandId, ':uid' => $userId]);
        $cmd = $stmt->fetch();

        if (!is_array($cmd)) {
            cc_share_fail(404, 'command_not_found');
        }

        $payload = [
            'version'         => 1,
            'name'            => (string)($cmd['name'] ?? ''),
            'slash_name'      => (string)($cmd['slash_name'] ?? ''),
            'description'     => (string)($cmd['description'] ?? ''),
            'group_name'      => $cmd['group_name'] !== null ? (string)$cmd['group_name'] : null,
            'builder_json'    => (string)($cmd['builder_json'] ?? ''),
            'builder_version' => (int)($cmd['builder_version'] ?? 1),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            cc_share_fail(500, 'json_encode_failed');
        }

        // Generate a unique code
        $code = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = cc_share_generate_code();
            $check = $pdo->prepare('SELECT id FROM command_share_codes WHERE code = :code LIMIT 1');
            $check->execute([':code' => $candidate]);
            if ($check->fetch() === false) {
                $code = $candidate;
                break;
            }
        }

        if ($code === '') {
            cc_share_fail(500, 'could_not_generate_unique_code');
        }

        // Expires in 30 days
        $expiresAt = (new DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO command_share_codes (code, payload_json, created_by, expires_at)
             VALUES (:code, :payload, :uid, :expires)'
        );
        $insert->execute([
            ':code'    => $code,
            ':payload' => $payloadJson,
            ':uid'     => $userId,
            ':expires' => $expiresAt,
        ]);

        echo json_encode([
            'ok'         => true,
            'code'       => $code,
            'expires_at' => $expiresAt,
        ]);
        exit;
    }

    // ── Import by share code ────────────────────────────────────────────────
    if ($action === 'import_share_code') {
        $code   = trim((string)($data['code'] ?? ''));
        $botId  = isset($data['bot_id']) ? (int)$data['bot_id'] : 0;

        if ($code === '' || !preg_match('/^[a-z0-9]{4}-[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{4}$/', $code)) {
            cc_share_fail(400, 'invalid_code_format');
        }

        if ($botId <= 0) {
            cc_share_fail(400, 'invalid_bot_id');
        }

        // Verify bot belongs to current user
        $botCheck = $pdo->prepare(
            'SELECT id FROM bot_instances WHERE id = :id AND owner_user_id = :uid LIMIT 1'
        );
        $botCheck->execute([':id' => $botId, ':uid' => $userId]);
        if ($botCheck->fetch() === false) {
            cc_share_fail(403, 'bot_not_found_or_no_access');
        }

        // Look up share code
        $stmt = $pdo->prepare(
            'SELECT payload_json, expires_at FROM command_share_codes WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            cc_share_fail(404, 'code_not_found');
        }

        // Check expiry
        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $expiry = strtotime($expiresAt);
            if ($expiry !== false && $expiry < time()) {
                cc_share_fail(410, 'code_expired');
            }
        }

        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) {
            cc_share_fail(500, 'payload_corrupt');
        }

        $name         = trim((string)($payload['name'] ?? ''));
        $slashName    = trim((string)($payload['slash_name'] ?? ''));
        $description  = trim((string)($payload['description'] ?? ''));
        $groupName    = isset($payload['group_name']) && is_string($payload['group_name'])
            ? trim($payload['group_name'])
            : null;
        $builderJson  = (string)($payload['builder_json'] ?? '');
        $builderVer   = (int)($payload['builder_version'] ?? 1);

        if ($name === '' || $slashName === '') {
            cc_share_fail(400, 'payload_missing_name_or_slug');
        }

        // Ensure slash_name is unique for this bot — append suffix if needed
        $baseSlashName = $slashName;
        $finalSlashName = $slashName;
        $suffix = 1;
        while (true) {
            $slugCheck = $pdo->prepare(
                'SELECT id FROM bot_custom_commands WHERE bot_id = :bid AND slash_name = :sn LIMIT 1'
            );
            $slugCheck->execute([':bid' => $botId, ':sn' => $finalSlashName]);
            if ($slugCheck->fetch() === false) {
                break;
            }
            $finalSlashName = $baseSlashName . '_' . $suffix;
            $suffix++;
            if ($suffix > 99) {
                cc_share_fail(409, 'slug_conflict');
            }
        }

        // Insert command
        $ins = $pdo->prepare(
            'INSERT INTO bot_custom_commands
             (bot_id, name, slash_name, description, group_name, is_enabled, created_by_user_id, updated_by_user_id)
             VALUES (:bot_id, :name, :slash_name, :desc, :group, 1, :uid, :uid2)'
        );
        $ins->execute([
            ':bot_id'     => $botId,
            ':name'       => $name,
            ':slash_name' => $finalSlashName,
            ':desc'       => $description !== '' ? $description : null,
            ':group'      => $groupName !== '' ? $groupName : null,
            ':uid'        => $userId,
            ':uid2'       => $userId,
        ]);
        $newCommandId = (int)$pdo->lastInsertId();

        // Insert builder JSON if present
        if ($builderJson !== '' && $builderJson !== '{}' && $builderJson !== 'null') {
            $insBuilder = $pdo->prepare(
                'INSERT INTO bot_custom_command_builders (custom_command_id, builder_json, builder_version)
                 VALUES (:cid, :json, :ver)'
            );
            $insBuilder->execute([
                ':cid'  => $newCommandId,
                ':json' => $builderJson,
                ':ver'  => $builderVer,
            ]);
        }

        echo json_encode([
            'ok'         => true,
            'command_id' => $newCommandId,
            'slash_name' => $finalSlashName,
            'name'       => $name,
        ]);
        exit;
    }

    // ── Preview share code (read metadata without importing) ────────────────
    if ($action === 'preview_share_code') {
        $code = trim((string)($data['code'] ?? ''));

        if ($code === '' || !preg_match('/^[a-z0-9]{4}-[a-z0-9]{6}-[a-z0-9]{6}-[a-z0-9]{4}$/', $code)) {
            cc_share_fail(400, 'invalid_code_format');
        }

        $stmt = $pdo->prepare(
            'SELECT payload_json, expires_at, created_at FROM command_share_codes WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            cc_share_fail(404, 'code_not_found');
        }

        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $expiry = strtotime($expiresAt);
            if ($expiry !== false && $expiry < time()) {
                cc_share_fail(410, 'code_expired');
            }
        }

        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) {
            cc_share_fail(500, 'payload_corrupt');
        }

        echo json_encode([
            'ok'         => true,
            'name'       => (string)($payload['name'] ?? ''),
            'slash_name' => (string)($payload['slash_name'] ?? ''),
            'description'=> (string)($payload['description'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'expires_at' => $expiresAt,
        ]);
        exit;
    }

    cc_share_fail(400, 'unknown_action');

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
    exit;
}
