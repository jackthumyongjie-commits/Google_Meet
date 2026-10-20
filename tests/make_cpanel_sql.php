<?php
declare(strict_types=1);
$src = dirname(__DIR__) . '/database/install.sql';
$s = file_get_contents($src);
$s = preg_replace('/^CREATE DATABASE.*?^USE connectmeet;\s*/ms', '', $s) ?? $s;
$s = str_replace(
    ["DEFAULT 'ConnectMeet Meeting'", "('app_name', 'ConnectMeet')"],
    ["DEFAULT 'VeloraMeet Meeting'", "('app_name', 'VeloraMeet')"],
    $s
);
$header = <<<'HDR'
-- VeloraMeet schema for cPanel
-- 1) Create the MySQL database + user in cPanel first
-- 2) Select that database in phpMyAdmin
-- 3) Import THIS file (no CREATE DATABASE)

HDR;
file_put_contents(dirname(__DIR__) . '/database/install_cpanel.sql', $header . $s);
echo "OK\n";
