<?php

declare(strict_types=1);

use SiteGate\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Миграции на три базы (D14):
 *   migrations/site/*.sql  → только DB1 (таблицы сайта);
 *   migrations/shard/*.sql → DB1, DB2, DB3 (аккаунты игроков).
 * В каждой базе своя таблица schema_migrations, версия = «site/001_init.sql».
 */

$plan = [
    'site' => [1],
    'shard' => range(1, Db::SHARDS),
];

$total = 0;
foreach ($plan as $dir => $targets) {
    $files = glob(dirname(__DIR__) . "/migrations/{$dir}/*.sql") ?: [];
    sort($files, SORT_STRING);
    foreach ($targets as $n) {
        try {
            $pdo = Db::shard($n);
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(255) NOT NULL PRIMARY KEY,
                    applied_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $applied = array_column(Db::shardAll($n, 'SELECT version FROM schema_migrations'), 'version');
        } catch (Throwable $ex) {
            fwrite(STDERR, "FAIL  DB{$n}: " . $ex->getMessage() . "\n");
            exit(1);
        }
        foreach ($files as $file) {
            $version = $dir . '/' . basename($file);
            if (in_array($version, $applied, true)) {
                echo "skip  DB{$n} {$version}\n";
                continue;
            }
            $sql = (string) file_get_contents($file);
            // Файл бьётся на выражения по «;» в конце строки
            $statements = preg_split('/;[ \t]*(?:\r?\n|$)/', $sql) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '' || preg_match('/^(--[^\n]*\n?)+$/', $statement)) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (Throwable $ex) {
                    fwrite(STDERR, "FAIL  DB{$n} {$version}: " . $ex->getMessage() . "\n");
                    exit(1);
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())')
                ->execute([$version]);
            echo "apply DB{$n} {$version}\n";
            $total++;
        }
    }
}
echo "Done, applied: {$total}\n";
