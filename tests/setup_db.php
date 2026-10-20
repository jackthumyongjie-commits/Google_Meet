<?php
/**
 * Import install.sql using PDO (avoids broken mysql CLI plugins on some XAMPP installs).
 * Run: php tests/setup_db.php
 */

declare(strict_types=1);

$sqlFile = dirname(__DIR__) . '/database/install.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Missing install.sql\n");
    exit(1);
}

$configFile = dirname(__DIR__) . '/config/config.php';
$config = require $configFile;
$db = $config['db'];

$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int) $db['port'], $db['charset']);

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(
    'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db['name']) . '`
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$pdo->exec('USE `' . str_replace('`', '``', $db['name']) . '`');

$sql = file_get_contents($sqlFile);
// Remove CREATE DATABASE / USE lines; we already selected the target DB.
$sql = preg_replace('/^\s*CREATE DATABASE[\s\S]*?;\s*/i', '', $sql, 1) ?? $sql;
$sql = preg_replace('/^\s*USE\s+\w+\s*;\s*/im', '', $sql, 1) ?? $sql;

$statements = preg_split('/;\s*(?=\n|$)/', $sql) ?: [];
$count = 0;

foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    // Drop leading comment-only lines
    $lines = preg_split('/\R/', $statement) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    $statement = trim(implode("\n", $kept));
    if ($statement === '') {
        continue;
    }

    $pdo->exec($statement);
    $count++;
}

echo "Imported OK ($count statements). Database: {$db['name']}\n";
