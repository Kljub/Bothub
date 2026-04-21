<?php
declare(strict_types=1);

/**
 * Discord REST API helpers (Bot Token).
 */

/**
 * @return array{ok:bool, http:int, data:array<string,mixed>|null, error:string|null}
 */
function discord_api_request_bot(string $botToken, string $method, string $path, ?array $jsonBody = null): array
{
    $method = strtoupper(trim($method));
    if ($method === '') {
        $method = 'GET';
    }

    $path = trim($path);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    $url = 'https://discord.com/api/v10' . $path;

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'curl_init fehlgeschlagen'];
    }

    $headers = [
        'Authorization: Bot ' . $botToken,
        'User-Agent: BotHub-Dashboard (self-hosted)',
    ];

    $payload = null;
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'json_encode() fehlgeschlagen'];
        }
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $err = curl_error($ch);
            return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Discord request fehlgeschlagen: ' . $err];
    }



    $rawTrim = trim((string)$raw);

    // Some endpoints may return 204 No Content
    if ($rawTrim === '') {
        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'http' => $http, 'data' => null, 'error' => null];
        }
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Discord Antwort ist leer. HTTP ' . $http];
    }

    $data = json_decode($rawTrim, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Discord Antwort ist kein JSON. HTTP ' . $http];
    }

    if ($http < 200 || $http >= 300) {
        $msg = (string)($data['message'] ?? 'Unbekannter Fehler');
        return ['ok' => false, 'http' => $http, 'data' => $data, 'error' => 'Discord Fehler: ' . $msg . ' (HTTP ' . $http . ')'];
    }

    return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => null];
}

/**
 * GET /users/@me
 * @return array{ok:bool, http:int, data:array<string,mixed>|null, error:string|null}
 */
function discord_api_get_me(string $botToken): array
{
    return discord_api_request_bot($botToken, 'GET', '/users/@me', null);
}