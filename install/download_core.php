<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__); // Dynamische Ermittlung des Projekt-Stammverzeichnisses
require_once $projectRoot . '/functions/core_zip_builder.php';

$coreTemplateDir = $projectRoot . '/core/installer';
$secretCfgPath = $projectRoot . '/db/config/secret.php';
$appCfgPath = $projectRoot . '/db/config/app.php';
$lockPath = $projectRoot . '/db/config/install.lock';

if (is_file($lockPath) && empty($_SESSION['installer_can_download_core'])) {
    http_response_code(403);
    echo 'Zugriff verweigert. Die Installation ist bereits abgeschlossen.';
    exit;
}

if (!is_file($secretCfgPath)) {
    http_response_code(500);
    echo 'Secret-Datei fehlt: ' . htmlspecialchars($secretCfgPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (!is_file($appCfgPath)) {
    http_response_code(500);
    echo 'App-Config fehlt: ' . htmlspecialchars($appCfgPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

$secretConfig = require $secretCfgPath;
if (!is_array($secretConfig)) {
    http_response_code(500);
    echo 'Secret-Datei ist ungültig.';
    exit;
}

$appConfig = require $appCfgPath;
if (!is_array($appConfig)) {
    http_response_code(500);
    echo 'App-Config ist ungültig.';
    exit;
}

$appKey = trim((string)($secretConfig['APP_KEY'] ?? ''));
if ($appKey === '') {
    http_response_code(500);
    echo 'APP_KEY fehlt in /db/config/secret.php';
    exit;
}

$baseUrl = trim((string)($appConfig['base_url'] ?? ''));
if ($baseUrl === '') {
    http_response_code(500);
    echo 'base_url fehlt in /db/config/app.php';
    exit;
}

$dbConfig = $appConfig['db'] ?? null;
if (!is_array($dbConfig)) {
    http_response_code(500);
    echo 'db fehlt oder ist ungültig in /db/config/app.php';
    exit;
}

$coreConfig       = is_array($appConfig['core'] ?? null) ? $appConfig['core'] : [];
$corePort         = isset($coreConfig['port']) && is_numeric($coreConfig['port']) ? (int)$coreConfig['port'] : 3000;
$runnerName       = trim((string)($coreConfig['runner_name'] ?? 'bothub-core-1')) ?: 'bothub-core-1';
$runnerEndpoint   = trim((string)($coreConfig['runner_endpoint'] ?? ''));
$storagePath      = trim((string)($coreConfig['storage_path'] ?? '')) ?: ($projectRoot . '/storage/soundboard');

$build = null;

try {
    $build = bh_core_zip_build(
        $coreTemplateDir,
        $baseUrl,
        $appKey,
        $dbConfig,
        'production',
        $corePort,
        $runnerName,
        5000,
        $storagePath,
        $runnerEndpoint
    );

    $zipPath = (string)($build['zip_path'] ?? '');

    if ($zipPath === '' || !is_file($zipPath)) {
        throw new RuntimeException('ZIP-Datei wurde nicht erzeugt.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="core.zip"');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($zipPath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
} finally {
    if (is_array($build) && isset($build['tmp_base']) && is_string($build['tmp_base'])) {
        bh_core_zip_rrmdir($build['tmp_base']);
    }
}