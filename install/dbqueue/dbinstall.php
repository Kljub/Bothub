<?php
declare(strict_types=1);
// SQL installer - executes db_v1.sql schema

function install_database_schema(PDO $pdo, string $projectRoot): array
{
    $schemaPath = $projectRoot . '/db/installer/db_v1.sql';

    if (!is_file($schemaPath)) {
        return ['ok' => false, 'errors' => ['Schema-Datei nicht gefunden.']];
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false || trim($sql) === '') {
        return ['ok' => false, 'errors' => ['Schema-Datei ist leer oder nicht lesbar.']];
    }

    // NOTE:
    // MySQL performs implicit commits for many DDL statements (CREATE/ALTER/DROP...).
    // Wrapping the whole schema import in a transaction will lead to:
    // "There is no active transaction" on commit/rollback.
    // Therefore we execute statements without a surrounding transaction.

    $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql);
    if (!is_array($statements)) {
        return ['ok' => false, 'errors' => ['Schema-Split fehlgeschlagen.']];
    }

    try {
        foreach ($statements as $st) {
            $st = trim($st);
            if ($st === '') {
                continue;
            }

            $stNoWs = ltrim($st);

            // Skip pure comment chunks (but keep chunks that actually contain SQL keywords)
            if (str_starts_with($stNoWs, '--') || str_starts_with($stNoWs, '/*')) {
                if (!preg_match('/\b(SET|CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $st)) {
                    continue;
                }
            }

            $pdo->exec($st);
        }

        return ['ok' => true, 'errors' => []];
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Schema-Import fehlgeschlagen: ' . $e->getMessage()]];
    }
}