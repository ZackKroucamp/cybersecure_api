<?php

require __DIR__ . '/db.php';
require __DIR__ . '/domain.php';

class ApiException extends Exception
{
    public $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ok($data = [], int $status = 200)
{
    respond($status, ['success' => true, 'data' => $data === [] ? new stdClass() : $data]);
}

function fail(int $status, string $message)
{
    throw new ApiException($status, $message);
}

set_exception_handler(function (Throwable $e) {
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable $ignored) {
    }

    if ($e instanceof ApiException) {
        respond($e->status, ['success' => false, 'error' => $e->getMessage()]);
    }
    if ($e instanceof PDOException) {
        $code = $e->errorInfo[1] ?? 0;
        if ($code === 1062) {
            respond(409, ['success' => false, 'error' => 'Record already exists']);
        }
        if ($code === 1451 || $code === 1452) {
            respond(422, ['success' => false, 'error' => 'Related record not found or still in use']);
        }
    }
    error_log((string)$e);
    respond(500, ['success' => false, 'error' => 'Server error']);
});

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        fail(405, 'Method not allowed');
    }
}

function body(): array
{
    static $b;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $b = [];
        } else {
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                fail(400, 'Invalid JSON body');
            }
            $b = $d;
        }
    }
    return $b;
}

function in_int(array $a, string $k, bool $req = true, int $min = 0, int $max = PHP_INT_MAX): ?int
{
    if (!isset($a[$k]) || $a[$k] === '') {
        if ($req) {
            fail(422, "$k is required");
        }
        return null;
    }
    $v = filter_var($a[$k], FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) {
        fail(422, "$k is invalid");
    }
    return $v;
}

function in_str(array $a, string $k, bool $req = true, int $min = 1, int $max = 255, bool $trim = true): ?string
{
    if (!isset($a[$k])) {
        if ($req) {
            fail(422, "$k is required");
        }
        return null;
    }
    if (!is_string($a[$k])) {
        fail(422, "$k is invalid");
    }
    $v = $trim ? trim($a[$k]) : $a[$k];
    $len = mb_strlen($v);
    if ($len < $min || $len > $max) {
        fail(422, "$k must be between $min and $max characters");
    }
    return $v;
}

function in_enum(array $a, string $k, array $allowed, bool $req = true): ?string
{
    if (!isset($a[$k])) {
        if ($req) {
            fail(422, "$k is required");
        }
        return null;
    }
    if (!in_array($a[$k], $allowed, true)) {
        fail(422, "$k must be one of: " . implode(', ', $allowed));
    }
    return $a[$k];
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $h = $value;
            }
        }
    }
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/', $h, $m) ? $m[1] : null;
}

function auth(array $roles = []): array
{
    $token = bearer_token();
    if ($token === null) {
        fail(401, 'Authentication required');
    }
    $user = db_row(
        'SELECT u.user_id, u.username, u.email, u.display_name, u.role, u.experience_level, t.token_id
         FROM auth_tokens t
         JOIN users u ON u.user_id = t.user_id
         WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > NOW() AND u.is_active = 1',
        [hash('sha256', $token)]
    );
    if (!$user) {
        fail(401, 'Invalid or expired token');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        fail(403, 'Access denied');
    }
    $user['user_id'] = (int)$user['user_id'];
    return $user;
}
