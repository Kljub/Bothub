<?php
declare(strict_types=1);

function plex_library_type_to_search_type(string $libraryType): ?int
{
    return match ($libraryType) {
        'movie'  => 1,
        'show'   => 2,
        'artist' => 8,
        'album'  => 9,
        'photo'  => 13,
        default  => null,
    };
}

function plex_get_app_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $configPath = dirname(__DIR__, 2) . '/db/config/app.php';
    if (!is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Plex: Konfigurationsdatei /db/config/app.php nicht gefunden.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Plex: Konfigurationsdatei /db/config/app.php ist ungültig.');
    }

    return $config;
}

function plex_get_secret_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $configPath = dirname(__DIR__, 2) . '/db/config/secret.php';
    if (!is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Plex: Konfigurationsdatei /db/config/secret.php nicht gefunden.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Plex: Konfigurationsdatei /db/config/secret.php ist ungültig.');
    }

    return $config;
}

function plex_get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = plex_get_app_config();
    $db = isset($config['db']) && is_array($config['db']) ? $config['db'] : [];

    $host = trim((string)($db['host'] ?? ''));
    $port = trim((string)($db['port'] ?? '3306'));
    $name = trim((string)($db['name'] ?? ''));
    $user = trim((string)($db['user'] ?? ''));
    $pass = (string)($db['pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Plex: Datenbank-Konfiguration ist unvollständig.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . ($port !== '' ? $port : '3306') . ';dbname=' . $name . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function plex_get_base_url(): string
{
    $config = plex_get_app_config();

    $candidates = [
        $config['base_url'] ?? null,
        $config['BASE_URL'] ?? null,
        isset($config['app']) && is_array($config['app']) ? ($config['app']['base_url'] ?? null) : null,
        isset($config['app']) && is_array($config['app']) ? ($config['app']['url'] ?? null) : null,
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return rtrim($value, '/');
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        throw new RuntimeException('Plex: BASE_URL konnte nicht ermittelt werden.');
    }

    return $scheme . '://' . $host;
}

function plex_get_app_key(): string
{
    $config = plex_get_secret_config();

    $candidates = [
        $config['APP_KEY'] ?? null,
        $config['app_key'] ?? null,
        isset($config['app']) && is_array($config['app']) ? ($config['app']['key'] ?? null) : null,
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }

    throw new RuntimeException('Plex: APP_KEY konnte nicht aus /db/config/secret.php gelesen werden.');
}

function plex_get_client_identifier(): string
{
    return 'bothub-plex-' . hash('sha256', plex_get_app_key());
}

function plex_encrypt_value(string $plainText): string
{
    if ($plainText === '') {
        return '';
    }

    $key = hash('sha256', plex_get_app_key(), true);
    $iv = random_bytes(16);

    $cipherText = openssl_encrypt(
        $plainText,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipherText === false) {
        throw new RuntimeException('Plex: Token-Verschlüsselung fehlgeschlagen.');
    }

    return 'enc:' . base64_encode($iv . $cipherText);
}

function plex_decrypt_value(string $storedValue): string
{
    $storedValue = trim($storedValue);
    if ($storedValue === '') {
        return '';
    }

    if (!str_starts_with($storedValue, 'enc:')) {
        return $storedValue;
    }

    $encoded = substr($storedValue, 4);
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $cipherText = substr($raw, 16);
    $key = hash('sha256', plex_get_app_key(), true);

    $plainText = openssl_decrypt(
        $cipherText,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return is_string($plainText) ? $plainText : '';
}

function plex_headers(?string $token = null, string $accept = 'application/json'): array
{
    $headers = [
        'Accept: ' . $accept,
        'X-Plex-Product: BotHub',
        'X-Plex-Version: 1.0',
        'X-Plex-Platform: Web',
        'X-Plex-Platform-Version: 1.0',
        'X-Plex-Device: BotHub Dashboard',
        'X-Plex-Device-Name: BotHub Dashboard',
        'X-Plex-Client-Identifier: ' . plex_get_client_identifier(),
    ];

    if ($token !== null && $token !== '') {
        $headers[] = 'X-Plex-Token: ' . $token;
    }

    return $headers;
}

function plex_http_request(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Plex: curl_init fehlgeschlagen.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            
            throw new RuntimeException('Plex: JSON-Encoding fehlgeschlagen.');
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $rawBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    

    if ($rawBody === false) {
        throw new RuntimeException('Plex: Netzwerkfehler: ' . $curlError);
    }

    $body = trim((string)$rawBody);
    $decoded = null;

    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = null;
        }
    }

    return [
        'status_code' => $statusCode,
        'content_type' => $contentType,
        'body' => $body,
        'json' => $decoded,
    ];
}

function plex_create_pin(): array
{
    $response = plex_http_request(
        'POST',
        'https://plex.tv/api/v2/pins?strong=true',
        plex_headers()
    );

    $json = $response['json'];
    if (!is_array($json) || !isset($json['id'], $json['code'])) {
        throw new RuntimeException('Plex: PIN konnte nicht erstellt werden.');
    }

    return [
        'id' => (int)$json['id'],
        'code' => (string)$json['code'],
    ];
}

function plex_build_callback_url(): string
{
    return plex_get_base_url() . '/dashboard/plex/callback';
}

function plex_build_auth_url(string $code): string
{
    $params = [
        'clientID' => plex_get_client_identifier(),
        'code' => $code,
        'context[device][product]' => 'BotHub',
        'forwardUrl' => plex_build_callback_url(),
    ];

    return 'https://app.plex.tv/auth#?' . http_build_query($params, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
}

function plex_check_pin(int $pinId, string $code = ''): ?string
{
    $url = 'https://plex.tv/api/v2/pins/' . $pinId;

    if ($code !== '') {
        $url .= '?' . http_build_query(['code' => $code], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }

    $response = plex_http_request(
        'GET',
        $url,
        plex_headers()
    );

    $json = $response['json'];
    if (!is_array($json)) {
        throw new RuntimeException('Plex: PIN-Prüfung lieferte keine gültige Antwort.');
    }

    $token = (string)($json['authToken'] ?? '');
    return $token !== '' ? $token : null;
}

function plex_get_resources(string $token): array
{
    $response = plex_http_request(
        'GET',
        'https://plex.tv/api/v2/resources?includeHttps=1&includeRelay=1',
        plex_headers($token)
    );

    $json = $response['json'];
    if (!is_array($json)) {
        throw new RuntimeException('Plex: Ressourcen konnten nicht geladen werden.');
    }

    $servers = [];

    foreach ($json as $resource) {
        if (!is_array($resource)) {
            continue;
        }

        $provides = trim((string)($resource['provides'] ?? ''));
        if (!str_contains($provides, 'server')) {
            continue;
        }

        $connections = [];
        if (isset($resource['connections']) && is_array($resource['connections'])) {
            foreach ($resource['connections'] as $connection) {
                if (!is_array($connection)) {
                    continue;
                }

                $uri = trim((string)($connection['uri'] ?? ''));
                if ($uri === '') {
                    continue;
                }

                $connections[] = [
                    'uri' => $uri,
                    'protocol' => trim((string)($connection['protocol'] ?? '')),
                    'address' => trim((string)($connection['address'] ?? '')),
                    'port' => trim((string)($connection['port'] ?? '')),
                    'local' => !empty($connection['local']),
                    'relay' => !empty($connection['relay']),
                    'IPv6' => !empty($connection['IPv6']),
                ];
            }
        }

        $servers[] = [
            'resource_identifier' => trim((string)($resource['clientIdentifier'] ?? $resource['identifier'] ?? '')),
            'name' => trim((string)($resource['name'] ?? '')),
            'product' => trim((string)($resource['product'] ?? '')),
            'product_version' => trim((string)($resource['productVersion'] ?? '')),
            'platform' => trim((string)($resource['platform'] ?? '')),
            'platform_version' => trim((string)($resource['platformVersion'] ?? '')),
            'device' => trim((string)($resource['device'] ?? '')),
            'client_identifier' => trim((string)($resource['clientIdentifier'] ?? '')),
            'owned' => !empty($resource['owned']),
            'presence' => !empty($resource['presence']),
            'access_token' => trim((string)($resource['accessToken'] ?? '')),
            'public_address_matches' => !empty($resource['publicAddressMatches']),
            'https_required' => !empty($resource['httpsRequired']),
            'connections' => $connections,
            'raw_json' => $resource,
        ];
    }

    return $servers;
}

function plex_upsert_account(int $userId, string $token): int
{
    $pdo = plex_get_pdo();

    $select = $pdo->prepare('SELECT id FROM user_plex_accounts WHERE user_id = :user_id LIMIT 1');
    $select->execute([':user_id' => $userId]);

    $existingId = (int)($select->fetchColumn() ?: 0);
    $tokenEnc = plex_encrypt_value($token);
    $clientIdentifier = plex_get_client_identifier();

    if ($existingId > 0) {
        $update = $pdo->prepare(
            'UPDATE user_plex_accounts
             SET plex_token_enc = :token,
                 client_identifier = :client_identifier,
                 status = :status,
                 updated_at = NOW(),
                 last_sync_at = NOW()
             WHERE id = :id'
        );

        $update->execute([
            ':token' => $tokenEnc,
            ':client_identifier' => $clientIdentifier,
            ':status' => 'connected',
            ':id' => $existingId,
        ]);

        return $existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO user_plex_accounts
        (user_id, plex_token_enc, client_identifier, status, connected_at, updated_at, last_sync_at)
        VALUES
        (:user_id, :token, :client_identifier, :status, NOW(), NOW(), NOW())'
    );

    $insert->execute([
        ':user_id' => $userId,
        ':token' => $tokenEnc,
        ':client_identifier' => $clientIdentifier,
        ':status' => 'connected',
    ]);

    return (int)$pdo->lastInsertId();
}

function plex_replace_servers(int $plexAccountId, array $servers): void
{
    $pdo = plex_get_pdo();

    $delete = $pdo->prepare('DELETE FROM user_plex_servers WHERE plex_account_id = :plex_account_id');
    $delete->execute([':plex_account_id' => $plexAccountId]);

    if (count($servers) === 0) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO user_plex_servers
        (
            plex_account_id,
            resource_identifier,
            name,
            product,
            product_version,
            platform,
            platform_version,
            device,
            client_identifier,
            owned,
            presence,
            access_token,
            connections_json,
            raw_json,
            created_at,
            updated_at
        )
        VALUES
        (
            :plex_account_id,
            :resource_identifier,
            :name,
            :product,
            :product_version,
            :platform,
            :platform_version,
            :device,
            :client_identifier,
            :owned,
            :presence,
            :access_token,
            :connections_json,
            :raw_json,
            NOW(),
            NOW()
        )'
    );

    foreach ($servers as $server) {
        if (!is_array($server)) {
            continue;
        }

        $resourceIdentifier = trim((string)($server['resource_identifier'] ?? ''));
        if ($resourceIdentifier === '') {
            continue;
        }

        $connectionsJson = json_encode($server['connections'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rawJson = json_encode($server['raw_json'] ?? $server, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $insert->execute([
            ':plex_account_id' => $plexAccountId,
            ':resource_identifier' => $resourceIdentifier,
            ':name' => trim((string)($server['name'] ?? '')),
            ':product' => trim((string)($server['product'] ?? '')),
            ':product_version' => trim((string)($server['product_version'] ?? '')),
            ':platform' => trim((string)($server['platform'] ?? '')),
            ':platform_version' => trim((string)($server['platform_version'] ?? '')),
            ':device' => trim((string)($server['device'] ?? '')),
            ':client_identifier' => trim((string)($server['client_identifier'] ?? '')),
            ':owned' => !empty($server['owned']) ? 1 : 0,
            ':presence' => !empty($server['presence']) ? 1 : 0,
            ':access_token' => trim((string)($server['access_token'] ?? '')),
            ':connections_json' => $connectionsJson !== false ? $connectionsJson : '[]',
            ':raw_json' => $rawJson !== false ? $rawJson : '{}',
        ]);
    }
}

function plex_disconnect_account(int $userId): void
{
    $pdo = plex_get_pdo();

    $select = $pdo->prepare('SELECT id FROM user_plex_accounts WHERE user_id = :user_id LIMIT 1');
    $select->execute([':user_id' => $userId]);

    $accountId = (int)($select->fetchColumn() ?: 0);
    if ($accountId > 0) {
        $deleteServers = $pdo->prepare('DELETE FROM user_plex_servers WHERE plex_account_id = :plex_account_id');
        $deleteServers->execute([':plex_account_id' => $accountId]);

        $deleteBotLibraries = $pdo->prepare('DELETE FROM bot_plex_libraries WHERE plex_account_id = :plex_account_id');
        $deleteBotLibraries->execute([':plex_account_id' => $accountId]);

        $deleteAccount = $pdo->prepare('DELETE FROM user_plex_accounts WHERE id = :id');
        $deleteAccount->execute([':id' => $accountId]);
    }
}

function plex_load_state_for_user(int $userId): array
{
    $pdo = plex_get_pdo();

    $accountStmt = $pdo->prepare('SELECT * FROM user_plex_accounts WHERE user_id = :user_id LIMIT 1');
    $accountStmt->execute([':user_id' => $userId]);
    $account = $accountStmt->fetch();

    if (!is_array($account)) {
        return [
            'connected' => false,
            'account' => null,
            'servers' => [],
        ];
    }

    $account['plex_token'] = plex_decrypt_value((string)($account['plex_token_enc'] ?? ''));

    $serversStmt = $pdo->prepare(
        'SELECT *
         FROM user_plex_servers
         WHERE plex_account_id = :plex_account_id
         ORDER BY name ASC, id ASC'
    );
    $serversStmt->execute([':plex_account_id' => (int)$account['id']]);

    $servers = [];
    foreach ($serversStmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $connections = [];
        $connectionsJson = (string)($row['connections_json'] ?? '');
        if ($connectionsJson !== '') {
            $decoded = json_decode($connectionsJson, true);
            if (is_array($decoded)) {
                $connections = $decoded;
            }
        }

        $servers[] = [
            'id' => (int)($row['id'] ?? 0),
            'plex_account_id' => (int)($row['plex_account_id'] ?? 0),
            'resource_identifier' => trim((string)($row['resource_identifier'] ?? '')),
            'name' => trim((string)($row['name'] ?? '')),
            'product' => trim((string)($row['product'] ?? '')),
            'product_version' => trim((string)($row['product_version'] ?? '')),
            'platform' => trim((string)($row['platform'] ?? '')),
            'platform_version' => trim((string)($row['platform_version'] ?? '')),
            'device' => trim((string)($row['device'] ?? '')),
            'client_identifier' => trim((string)($row['client_identifier'] ?? '')),
            'owned' => !empty($row['owned']),
            'presence' => !empty($row['presence']),
            'access_token' => trim((string)($row['access_token'] ?? '')),
            'connections' => $connections,
            'raw_json' => (string)($row['raw_json'] ?? ''),
        ];
    }

    return [
        'connected' => true,
        'account' => $account,
        'servers' => $servers,
    ];
}

function plex_pick_best_connections(array $connections): array
{
    $sorted = [];

    foreach ($connections as $connection) {
        if (!is_array($connection)) {
            continue;
        }

        $uri = trim((string)($connection['uri'] ?? ''));
        if ($uri === '') {
            continue;
        }

        $score = 0;
        if (!empty($connection['local'])) {
            $score += 100;
        }
        if (str_starts_with($uri, 'https://')) {
            $score += 20;
        }
        if (empty($connection['relay'])) {
            $score += 10;
        }

        $connection['_score'] = $score;
        $sorted[] = $connection;
    }

    usort($sorted, static function (array $a, array $b): int {
        return ((int)($b['_score'] ?? 0)) <=> ((int)($a['_score'] ?? 0));
    });

    foreach ($sorted as &$connection) {
        unset($connection['_score']);
    }
    unset($connection);

    return $sorted;
}

function plex_parse_libraries_from_response(string $body): array
{
    $body = trim($body);
    if ($body === '') {
        return [];
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        $dirs = [];
        if (isset($json['MediaContainer']['Directory']) && is_array($json['MediaContainer']['Directory'])) {
            $dirs = $json['MediaContainer']['Directory'];
        } elseif (isset($json['MediaContainer']['Directory']) && isset($json['MediaContainer']['Directory']['key'])) {
            $dirs = [$json['MediaContainer']['Directory']];
        }

        $libraries = [];
        foreach ($dirs as $dir) {
            if (!is_array($dir)) {
                continue;
            }

            $key = trim((string)($dir['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $libraries[] = [
                'library_key' => $key,
                'library_title' => trim((string)($dir['title'] ?? '')),
                'library_type' => trim((string)($dir['type'] ?? '')),
            ];
        }

        return $libraries;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        return [];
    }

    $libraries = [];
    foreach ($xml->Directory as $directory) {
        $key = trim((string)($directory['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $libraries[] = [
            'library_key' => $key,
            'library_title' => trim((string)($directory['title'] ?? '')),
            'library_type' => trim((string)($directory['type'] ?? '')),
        ];
    }

    return $libraries;
}

function plex_fetch_server_libraries(array $server, string $fallbackToken): array
{
    file_put_contents('/tmp/plex_step_3.txt', json_encode($server, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    $resourceIdentifier = trim((string)($server['resource_identifier'] ?? ''));
    if ($resourceIdentifier === '') {
        return [];
    }

    $serverName = trim((string)($server['name'] ?? ''));
    $serverToken = trim((string)($server['access_token'] ?? ''));
    $token = $serverToken !== '' ? $serverToken : $fallbackToken;
    if ($token === '') {
        return [];
    }

    $connections = isset($server['connections']) && is_array($server['connections']) ? $server['connections'] : [];
    file_put_contents('/tmp/plex_step_4.txt', json_encode($connections, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    $connections = plex_pick_best_connections($connections);
    if (count($connections) === 0) {
        return [];
    }
    

    foreach ($connections as $connection) {
        $baseUri = rtrim(trim((string)($connection['uri'] ?? '')), '/');
        if ($baseUri === '') {
            continue;
        }
        file_put_contents('/tmp/plex_try_url.txt', $baseUri . PHP_EOL, FILE_APPEND);

        try {
            $response = plex_http_request(
                'GET',
                $baseUri . '/library/sections',
                plex_headers($token, 'application/xml,application/json;q=0.9,*/*;q=0.8')
            );
            file_put_contents('/tmp/plex_try_url2.txt', $baseUri . PHP_EOL, FILE_APPEND);

            if ((int)($response['status_code'] ?? 0) < 200 || (int)($response['status_code'] ?? 0) >= 300) {
                continue;
            }

            $libraries = plex_parse_libraries_from_response((string)($response['body'] ?? ''));
            if (count($libraries) === 0) {
                continue;
            }

            $out = [];
            foreach ($libraries as $library) {
                if (!is_array($library)) {
                    continue;
                }

                $libraryKey = trim((string)($library['library_key'] ?? ''));
                if ($libraryKey === '') {
                    continue;
                }

                $out[] = [
                    'resource_identifier' => $resourceIdentifier,
                    'server_name' => $serverName !== '' ? $serverName : 'Unbekannter Server',
                    'library_key' => $libraryKey,
                    'library_title' => trim((string)($library['library_title'] ?? '')),
                    'library_type' => trim((string)($library['library_type'] ?? '')),
                ];
            }

            if (count($out) > 0) {
                return $out;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return [];
}

function plex_get_all_libraries_for_user(int $userId): array
{
    file_put_contents('/tmp/plex_step_1.txt', "plex_get_all_libraries_for_user reached\n", FILE_APPEND);
    $state = plex_load_state_for_user($userId);
    if (empty($state['connected']) || !isset($state['account']) || !is_array($state['account'])) {
        return [];
    }

    $accountToken = trim((string)($state['account']['plex_token'] ?? ''));
    if ($accountToken === '') {
        return [];
    }

    $servers = isset($state['servers']) && is_array($state['servers']) ? $state['servers'] : [];
    file_put_contents('/tmp/plex_step_2.txt', json_encode($servers, JSON_PRETTY_PRINT), FILE_APPEND);
    $allLibraries = [];
    $seen = [];

    foreach ($servers as $server) {
        if (!is_array($server)) {
            continue;
        }

        $serverLibraries = plex_fetch_server_libraries($server, $accountToken);
        foreach ($serverLibraries as $library) {
            if (!is_array($library)) {
                continue;
            }

            $compoundKey = trim((string)($library['resource_identifier'] ?? '')) . '::' . trim((string)($library['library_key'] ?? ''));
            if ($compoundKey === '::' || isset($seen[$compoundKey])) {
                continue;
            }

            $seen[$compoundKey] = true;
            $allLibraries[] = $library;
        }
    }

    usort($allLibraries, static function (array $a, array $b): int {
        $serverCompare = strcmp((string)($a['server_name'] ?? ''), (string)($b['server_name'] ?? ''));
        if ($serverCompare !== 0) {
            return $serverCompare;
        }

        return strcmp((string)($a['library_title'] ?? ''), (string)($b['library_title'] ?? ''));
    });

    return $allLibraries;
}

function plex_sync_bot_library_catalog(int $userId, int $botId, int $plexAccountId, array $libraries): void
{
    if ($userId <= 0 || $botId <= 0 || $plexAccountId <= 0) {
        return;
    }

    $pdo = plex_get_pdo();

    $existingStmt = $pdo->prepare(
        'SELECT id, resource_identifier, library_key, is_allowed
         FROM bot_plex_libraries
         WHERE user_id = :user_id AND bot_id = :bot_id'
    );
    $existingStmt->execute([
        ':user_id' => $userId,
        ':bot_id' => $botId,
    ]);

    $existingMap = [];
    foreach ($existingStmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $compoundKey = trim((string)($row['resource_identifier'] ?? '')) . '::' . trim((string)($row['library_key'] ?? ''));
        if ($compoundKey === '::') {
            continue;
        }
        $existingMap[$compoundKey] = [
            'id' => (int)($row['id'] ?? 0),
            'is_allowed' => (int)($row['is_allowed'] ?? 0),
        ];
    }

    $seen = [];
    $insertStmt = $pdo->prepare(
        'INSERT INTO bot_plex_libraries
        (
            user_id,
            bot_id,
            plex_account_id,
            resource_identifier,
            server_name,
            library_key,
            library_title,
            library_type,
            plex_search_type,
            is_allowed,
            created_at,
            updated_at
        )
        VALUES
        (
            :user_id,
            :bot_id,
            :plex_account_id,
            :resource_identifier,
            :server_name,
            :library_key,
            :library_title,
            :library_type,
            :plex_search_type,
            :is_allowed,
            NOW(),
            NOW()
        )'
    );

    $updateStmt = $pdo->prepare(
        'UPDATE bot_plex_libraries
         SET plex_account_id = :plex_account_id,
             server_name = :server_name,
             library_title = :library_title,
             library_type = :library_type,
             plex_search_type = :plex_search_type,
             updated_at = NOW()
         WHERE id = :id'
    );

    foreach ($libraries as $library) {
        if (!is_array($library)) {
            continue;
        }

        $resourceIdentifier = trim((string)($library['resource_identifier'] ?? ''));
        $libraryKey = trim((string)($library['library_key'] ?? ''));
        $compoundKey = $resourceIdentifier . '::' . $libraryKey;

        if ($resourceIdentifier === '' || $libraryKey === '') {
            continue;
        }

        $seen[$compoundKey] = true;

        if (isset($existingMap[$compoundKey])) {
            $libType = trim((string)($library['library_type'] ?? ''));
            $updateStmt->execute([
                ':plex_account_id' => $plexAccountId,
                ':server_name' => trim((string)($library['server_name'] ?? '')),
                ':library_title' => trim((string)($library['library_title'] ?? '')),
                ':library_type' => $libType,
                ':plex_search_type' => plex_library_type_to_search_type($libType),
                ':id' => (int)$existingMap[$compoundKey]['id'],
            ]);
            continue;
        }

        $libType = trim((string)($library['library_type'] ?? ''));
        $insertStmt->execute([
            ':user_id' => $userId,
            ':bot_id' => $botId,
            ':plex_account_id' => $plexAccountId,
            ':resource_identifier' => $resourceIdentifier,
            ':server_name' => trim((string)($library['server_name'] ?? '')),
            ':library_key' => $libraryKey,
            ':library_title' => trim((string)($library['library_title'] ?? '')),
            ':library_type' => $libType,
            ':plex_search_type' => plex_library_type_to_search_type($libType),
            ':is_allowed' => 0,
        ]);
    }

    foreach ($existingMap as $compoundKey => $existing) {
        if (!isset($seen[$compoundKey])) {
            $deleteStmt = $pdo->prepare(
                'DELETE FROM bot_plex_libraries
                 WHERE user_id = :user_id
                   AND bot_id = :bot_id
                   AND CONCAT(resource_identifier, \'::\', library_key) = :compound_key'
            );
            $deleteStmt->execute([
                ':user_id' => $userId,
                ':bot_id' => $botId,
                ':compound_key' => $compoundKey,
            ]);
        }
    }
}

function plex_get_catalog_libraries_for_bot(int $userId, int $botId): array
{
    if ($userId <= 0 || $botId <= 0) {
        return [];
    }

    $pdo = plex_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT resource_identifier, server_name, library_key, library_title, library_type, is_allowed
         FROM bot_plex_libraries
         WHERE user_id = :user_id AND bot_id = :bot_id
         ORDER BY server_name ASC, library_title ASC, id ASC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':bot_id' => $botId,
    ]);

    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function plex_get_allowed_libraries_map_for_bot(int $userId, int $botId): array
{
    if ($userId <= 0 || $botId <= 0) {
        return [];
    }

    $pdo = plex_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT resource_identifier, library_key
         FROM bot_plex_libraries
         WHERE user_id = :user_id AND bot_id = :bot_id AND is_allowed = 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':bot_id' => $botId,
    ]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $compoundKey = trim((string)($row['resource_identifier'] ?? '')) . '::' . trim((string)($row['library_key'] ?? ''));
        if ($compoundKey === '::') {
            continue;
        }

        $map[$compoundKey] = true;
    }

    return $map;
}
function plex_assert_user_owns_bot(int $userId, int $botId): void
{
    if ($userId <= 0 || $botId <= 0) {
        throw new RuntimeException('Ungültige Bot-Zuordnung.');
    }

    $pdo = plex_get_pdo();

    $stmt = $pdo->prepare("
        SELECT id
        FROM bot_instances
        WHERE id = :bot_id
          AND owner_user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ':bot_id' => $botId,
        ':user_id' => $userId,
    ]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('Du hast keinen Zugriff auf diesen Bot.');
    }
}
function plex_get_bot_command_states(int $userId, int $botId): array
{
    if ($userId <= 0 || $botId <= 0) {
        return [];
    }

    $pdo  = plex_get_pdo();
    $keys = [
        'plex-search',
        'plex-random',
        'plex-info',
        'plex-stats',
        'plex-play',
        'plex-recently-added',
        'plex-on-deck',
    ];

    $ph   = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        "SELECT command_key, is_enabled FROM commands WHERE bot_id = ? AND command_key IN ($ph)"
    );
    $stmt->execute(array_merge([$botId], $keys));

    $states = array_fill_keys($keys, true); // default: enabled
    foreach ($stmt->fetchAll() as $row) {
        $k = trim((string)($row['command_key'] ?? ''));
        if ($k !== '') $states[$k] = !empty($row['is_enabled']);
    }

    // Ensure every known key has a DB row so slash-sync can find it.
    // INSERT IGNORE: no-op if row already exists, inserts default enabled=1 otherwise.
    $insert = $pdo->prepare(
        "INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, is_enabled, created_at, updated_at)
         VALUES (:bot_id, :key, 'predefined', :name, 1, NOW(), NOW())"
    );
    foreach ($keys as $key) {
        $insert->execute([':bot_id' => $botId, ':key' => $key, ':name' => $key]);
    }

    return $states;
}

function plex_upsert_bot_command_state(int $userId, int $botId, string $commandKey, bool $enabled): void
{
    if ($userId <= 0 || $botId <= 0 || $commandKey === '') {
        throw new RuntimeException('Ungültige Parameter für Command-Speicherung.');
    }

    plex_assert_user_owns_bot($userId, $botId);

    $pdo = plex_get_pdo();

    $pdo->prepare(
        "INSERT INTO commands (bot_id, command_key, command_type, name, is_enabled, created_at, updated_at)
         VALUES (:bot_id, :key, 'predefined', :name, :enabled, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             command_type = 'predefined',
             is_enabled   = VALUES(is_enabled),
             updated_at   = NOW()"
    )->execute([
        ':bot_id'  => $botId,
        ':key'     => $commandKey,
        ':name'    => $commandKey,
        ':enabled' => $enabled ? 1 : 0,
    ]);
}

function plex_notify_bot_reload(int $botId): void
{
    if ($botId <= 0) {
        return;
    }

    try {
        $appKey = trim((string)(plex_get_secret_config()['APP_KEY'] ?? ''));
        if ($appKey === '') {
            return;
        }

        $pdo = plex_get_pdo();
        $stmt = $pdo->query('SELECT endpoint FROM core_runners WHERE endpoint != \'\' ORDER BY id ASC');
        $runners = $stmt->fetchAll();
        if (!is_array($runners) || count($runners) === 0) {
            return;
        }

        foreach ($runners as $runner) {
            $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
            if ($endpoint === '') {
                continue;
            }

            $ch = curl_init($endpoint . '/reload/bot/' . $botId);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $appKey,
                    'Content-Type: application/json',
                ],
            ]);

            curl_exec($ch);
        }
    } catch (Throwable) {
        // Reload-Benachrichtigung ist nicht kritisch
    }
}

function plex_replace_bot_allowed_libraries(int $userId, int $botId, array $allowedCompoundKeys): void
{
    if ($userId <= 0 || $botId <= 0) {
        throw new RuntimeException('Ungültige Bot-Zuordnung.');
    }

    $pdo = plex_get_pdo();

    plex_assert_user_owns_bot($userId, $botId);

    $allowedMap = [];
    foreach ($allowedCompoundKeys as $compoundKey) {
        $compoundKey = trim((string)$compoundKey);
        if ($compoundKey === '' || !str_contains($compoundKey, '::')) {
            continue;
        }

        $allowedMap[$compoundKey] = true;
    }

    $stmt = $pdo->prepare(
        'SELECT id, resource_identifier, library_key
         FROM bot_plex_libraries
         WHERE user_id = :user_id
           AND bot_id = :bot_id'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':bot_id' => $botId,
    ]);

    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE bot_plex_libraries
         SET is_allowed = :is_allowed,
             updated_at = NOW()
         WHERE id = :id'
    );

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $compoundKey = trim((string)($row['resource_identifier'] ?? '')) . '::' . trim((string)($row['library_key'] ?? ''));
        if ($compoundKey === '::') {
            continue;
        }

        $isAllowed = isset($allowedMap[$compoundKey]) ? 1 : 0;

        $updateStmt->execute([
            ':is_allowed' => $isAllowed,
            ':id' => (int)($row['id'] ?? 0),
        ]);
    }
}