<?php

function config(): array
{
    static $cfg;
    return $cfg ??= require __DIR__ . '/../config.php';
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $c = config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_rows(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $r = db_query($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function db_val(string $sql, array $params = [])
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}
