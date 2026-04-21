<?php
declare(strict_types=1);

require_once __DIR__ . '/custom_commands.php';

define('SB_STORAGE_BASE', dirname(__DIR__) . '/storage/soundboard');
define('SB_MAX_FILESIZE', 8 * 1024 * 1024); // 8 MB
define('SB_ALLOWED_MIME', ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
                            'audio/ogg', 'audio/flac', 'audio/x-flac', 'audio/mp4',
                            'audio/aac', 'audio/webm']);

function sb_get_pdo(): \PDO
{
    return bh_cc_get_pdo();
}

function sb_storage_dir(int $botId): string
{
    $dir = SB_STORAGE_BASE . '/' . $botId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Ensure file_data column exists (auto-migration for existing installations).
 */
function sb_ensure_schema(\PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM bot_soundboard_sounds LIKE 'file_data'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE bot_soundboard_sounds ADD COLUMN file_data MEDIUMBLOB NULL DEFAULT NULL AFTER mime_type");
        }
    } catch (\Throwable) {}
}

function sb_list_sounds(int $userId, int $botId): array
{
    $pdo = sb_get_pdo();
    sb_ensure_schema($pdo);

    // Verify that $botId belongs to $userId before listing sounds (IDOR prevention)
    $stmt = $pdo->prepare(
        'SELECT bss.id, bss.name, bss.emoji, bss.volume, bss.filename, bss.filesize, bss.mime_type, bss.created_at
           FROM bot_soundboard_sounds bss
           JOIN bot_instances bi ON bi.id = bss.bot_id AND bi.owner_user_id = :uid
          WHERE bss.bot_id = :bid
          ORDER BY bss.name ASC'
    );
    $stmt->execute([':uid' => $userId, ':bid' => $botId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function sb_upload_sound(int $userId, int $botId, array $file, string $name, string $emoji, int $volume): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload-Fehler (Code ' . ($file['error'] ?? '?') . ').'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size > SB_MAX_FILESIZE) {
        return ['ok' => false, 'error' => 'Datei zu groß (max. 8 MB).'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');

    // Detect MIME type
    $mimeType = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $detected = finfo_file($fi, $tmpPath);
            finfo_close($fi);
            if ($detected !== false && $detected !== '') {
                $mimeType = $detected;
            }
        }
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
    } else {
        $extMap = [
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'flac' => 'audio/flac', 'aac' => 'audio/aac', 'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
        ];
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mimeType = $extMap[$ext] ?? 'application/octet-stream';
    }

    if (!in_array($mimeType, SB_ALLOWED_MIME, true)) {
        return ['ok' => false, 'error' => 'Ungültiges Dateiformat. Erlaubt: MP3, WAV, OGG, FLAC, AAC.'];
    }

    $name   = trim($name) !== '' ? mb_substr(trim($name), 0, 64) : 'Sound';
    $emoji  = mb_substr(trim($emoji), 0, 16);
    $volume = max(1, min(200, $volume));

    // Read file content for MySQL storage
    $fileData = file_get_contents($tmpPath);
    if ($fileData === false) {
        return ['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.'];
    }

    // Also keep a local copy as fallback / for browser preview
    $ext      = strtolower(pathinfo((string)($file['name'] ?? 'sound.mp3'), PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dir      = sb_storage_dir($botId);
    $destPath = $dir . '/' . $filename;
    move_uploaded_file($tmpPath, $destPath); // best-effort; not critical

    try {
        $pdo = sb_get_pdo();
        sb_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO bot_soundboard_sounds
                (bot_id, user_id, name, emoji, volume, filename, filesize, mime_type, file_data)
             VALUES (:bid, :uid, :name, :emoji, :vol, :fn, :fs, :mime, :data)'
        );
        $stmt->bindValue(':bid',   $botId,                       \PDO::PARAM_INT);
        $stmt->bindValue(':uid',   $userId,                      \PDO::PARAM_INT);
        $stmt->bindValue(':name',  $name,                        \PDO::PARAM_STR);
        $stmt->bindValue(':emoji', $emoji !== '' ? $emoji : null, \PDO::PARAM_STR);
        $stmt->bindValue(':vol',   $volume,                      \PDO::PARAM_INT);
        $stmt->bindValue(':fn',    $filename,                    \PDO::PARAM_STR);
        $stmt->bindValue(':fs',    $size,                        \PDO::PARAM_INT);
        $stmt->bindValue(':mime',  $mimeType,                    \PDO::PARAM_STR);
        $stmt->bindValue(':data',  $fileData,                    \PDO::PARAM_LOB);
        $stmt->execute();
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (\Throwable $e) {
        @unlink($destPath);
        return ['ok' => false, 'error' => 'DB-Fehler: ' . $e->getMessage()];
    }
}

function sb_delete_sound(int $userId, int $botId, int $soundId): array
{
    $pdo  = sb_get_pdo();
    $stmt = $pdo->prepare('SELECT filename FROM bot_soundboard_sounds WHERE id = :id AND bot_id = :bid');
    $stmt->execute([':id' => $soundId, ':bid' => $botId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'error' => 'Sound nicht gefunden.'];
    }

    $path = sb_storage_dir($botId) . '/' . $row['filename'];
    if (is_file($path)) {
        @unlink($path);
    }

    $pdo->prepare('DELETE FROM bot_soundboard_sounds WHERE id = :id AND bot_id = :bid')
        ->execute([':id' => $soundId, ':bid' => $botId]);

    return ['ok' => true];
}

/**
 * Send a soundboard command to the running bot core.
 */
function sb_send_to_bot(int $botId, string $action, array $payload = []): array
{
    try {
        $secretPath = dirname(__DIR__) . '/db/config/secret.php';
        if (!is_file($secretPath)) {
            return ['ok' => false, 'error' => 'Keine Konfiguration gefunden.'];
        }
        $secret = require $secretPath;
        $appKey = trim((string)($secret['APP_KEY'] ?? ''));
        if ($appKey === '') {
            return ['ok' => false, 'error' => 'APP_KEY nicht konfiguriert.'];
        }

        $pdo    = sb_get_pdo();
        $stmt   = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC LIMIT 1");
        $runner = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$runner || empty($runner['endpoint'])) {
            return ['ok' => false, 'error' => 'Kein Bot-Core erreichbar.'];
        }

        $endpoint = rtrim(trim((string)$runner['endpoint']), '/');
        $url      = $endpoint . '/soundboard/bot/' . $botId . '/' . $action;
        $body     = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $appKey,
                'Content-Type: application/json',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $code === 0) {
            return ['ok' => false, 'error' => 'Bot-Core nicht erreichbar.'];
        }

        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : ['ok' => $code === 200];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function sb_get_vc_status(int $botId): array
{
    return sb_send_to_bot($botId, 'status');
}
