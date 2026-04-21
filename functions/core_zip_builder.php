<?php
declare(strict_types=1);

if (!function_exists('bh_core_zip_rrmdir')) {
    function bh_core_zip_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path) && !is_link($path)) {
                bh_core_zip_rrmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}

if (!function_exists('bh_core_zip_rcopy')) {
    function bh_core_zip_rcopy(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('Core-Template nicht gefunden: ' . $source);
        }

        if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new RuntimeException('Konnte Zielordner nicht erstellen: ' . $dest);
        }

        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException('Konnte Quellordner nicht lesen: ' . $source);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dest . DIRECTORY_SEPARATOR . $item;

            if (is_dir($srcPath) && !is_link($srcPath)) {
                bh_core_zip_rcopy($srcPath, $dstPath);
                continue;
            }

            if (!copy($srcPath, $dstPath)) {
                throw new RuntimeException('Konnte Datei nicht kopieren: ' . $srcPath);
            }
        }
    }
}

if (!function_exists('bh_core_zip_build')) {
    function bh_core_zip_build(
        string $coreTemplateDir,
        string $baseUrl,
        string $appKey,
        array $dbConfig,
        string $nodeEnv = 'production',
        int $corePort = 3000,
        string $runnerName = 'bothub-core-1',
        int $jobPollIntervalMs = 5000,
        string $storagePath = '',
        string $runnerEndpointOverride = ''
    ): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive fehlt. Bitte PHP zip extension aktivieren.');
        }

        $coreTemplateDir = rtrim($coreTemplateDir, DIRECTORY_SEPARATOR);
        $baseUrl = rtrim(trim($baseUrl), '/');
        $appKey = trim($appKey);
        $nodeEnv = trim($nodeEnv);
        $runnerName = trim($runnerName);

        $dbHost = trim((string)($dbConfig['host'] ?? ''));
        $dbPort = trim((string)($dbConfig['port'] ?? '3306'));
        $dbName = trim((string)($dbConfig['name'] ?? ''));
        $dbUser = trim((string)($dbConfig['user'] ?? ''));
        $dbPass = (string)($dbConfig['pass'] ?? '');

        if ($coreTemplateDir === '' || !is_dir($coreTemplateDir)) {
            throw new RuntimeException('Core-Template-Ordner fehlt: ' . $coreTemplateDir);
        }

        if ($baseUrl === '') {
            throw new RuntimeException('base_url ist leer.');
        }

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY ist leer.');
        }

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('DB-Konfiguration für core.zip ist unvollständig.');
        }

        if ($nodeEnv === '') {
            $nodeEnv = 'production';
        }

        if ($runnerName === '') {
            $runnerName = 'bothub-core-1';
        }

        if ($corePort < 1 || $corePort > 65535) {
            throw new RuntimeException('CORE_PORT ist ungültig.');
        }

        if ($jobPollIntervalMs < 1) {
            throw new RuntimeException('JOB_POLL_INTERVAL_MS ist ungültig.');
        }

        // Use explicit override if provided, otherwise derive from base URL + port
        $runnerEndpoint = $runnerEndpointOverride !== ''
            ? rtrim(trim($runnerEndpointOverride), '/')
            : rtrim($baseUrl, '/') . ':' . $corePort;

        $envContent = 'NODE_ENV=' . $nodeEnv . "\n"
            . 'DASHBOARD_BASE_URL=' . $baseUrl . "\n"
            . 'APP_KEY=' . $appKey . "\n"
            . 'CORE_PORT=' . $corePort . "\n"
            . 'RUNNER_NAME=' . $runnerName . "\n"
            . 'RUNNER_ENDPOINT=' . $runnerEndpoint . "\n"
            . 'JOB_POLL_INTERVAL_MS=' . $jobPollIntervalMs . "\n"
            . 'DB_HOST=' . $dbHost . "\n"
            . 'DB_PORT=' . $dbPort . "\n"
            . 'DB_NAME=' . $dbName . "\n"
            . 'DB_USER=' . $dbUser . "\n"
            . 'DB_PASS=' . $dbPass . "\n"
            . 'UPDATER_PORT=3099' . "\n"
            . 'SOUNDBOARD_STORAGE_PATH=' . rtrim($storagePath, '/') . "\n";

        // Write ZIP directly from the source — no temp copy needed
        $tmpBase    = sys_get_temp_dir() . '/bothub_core_' . bin2hex(random_bytes(8));
        $tmpZipPath = $tmpBase . '.zip';

        $zip = new ZipArchive();
        $openResult = $zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            throw new RuntimeException('Konnte ZIP nicht erstellen. Code: ' . (string)$openResult);
        }

        // Inject .env directly as a string — no disk write required
        if (!$zip->addFromString('.env', $envContent)) {
            $zip->close();
            @unlink($tmpZipPath);
            throw new RuntimeException('Konnte .env nicht zur ZIP hinzufügen.');
        }

        $baseLength = strlen($coreTemplateDir) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coreTemplateDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $absolutePath = $fileInfo->getPathname();
            $localPath    = substr($absolutePath, $baseLength);

            if ($localPath === false || $localPath === '') {
                continue;
            }

            $zipLocalPath = str_replace('\\', '/', $localPath);

            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($zipLocalPath);
                continue;
            }

            // Skip any existing .env in the template — we inject our own above
            if ($zipLocalPath === '.env') {
                continue;
            }

            if (!$zip->addFile($absolutePath, $zipLocalPath)) {
                $zip->close();
                @unlink($tmpZipPath);
                throw new RuntimeException('Konnte Datei nicht zur ZIP hinzufügen: ' . $absolutePath);
            }
        }

        $zip->close();

        if (!is_file($tmpZipPath)) {
            throw new RuntimeException('ZIP-Datei wurde nicht erzeugt.');
        }

        return [
            'tmp_base' => $tmpBase,
            'work_dir' => $coreTemplateDir,
            'zip_path' => $tmpZipPath,
        ];
    }
}