<?php

require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$b = body();

$username = in_str($b, 'username', true, 3, 50);
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
    fail(422, 'Username may only contain letters, numbers, dots, dashes and underscores');
}
$email = strtolower(in_str($b, 'email', true, 5, 100));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'Email is invalid');
}
$password = in_str($b, 'password', true, 8, 128, false);
if (password_metrics($password)['is_common']) {
    fail(422, 'Password is too common');
}
$displayName = in_str($b, 'display_name', false, 1, 80) ?? $username;

if (db_val('SELECT 1 FROM users WHERE username = ?', [$username])) {
    fail(409, 'Username is already taken');
}
if (db_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
    fail(409, 'Email is already registered');
}

db_exec(
    'INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)',
    [$username, $email, password_hash($password, PASSWORD_DEFAULT), $displayName]
);
$uid = (int)db()->lastInsertId();
$user = db_row('SELECT user_id, username, email, display_name, role, experience_level FROM users WHERE user_id = ?', [$uid]);

ok(['user' => public_user($user)] + issue_token($uid), 201);
