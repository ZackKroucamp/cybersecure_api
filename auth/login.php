<?php

require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$b = body();
$login = in_str($b, 'login', true, 1, 100);
$password = in_str($b, 'password', true, 1, 128, false);

$user = db_row('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1', [$login, strtolower($login)]);

if (!password_verify($password, $user['password_hash'] ?? DUMMY_HASH) || !$user) {
    fail(401, 'Invalid username or password');
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    db_exec('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
}
db_exec('UPDATE users SET last_login_at = NOW() WHERE user_id = ?', [$user['user_id']]);

ok(['user' => public_user($user)] + issue_token((int)$user['user_id']));
