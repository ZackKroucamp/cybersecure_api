<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();
$uid = $user['user_id'];

$sets = [];
$params = [];

$name = in_str($b, 'display_name', false, 1, 80);
if ($name !== null) {
    $sets[] = 'display_name = ?';
    $params[] = $name;
}
$level = in_enum($b, 'experience_level', ['beginner', 'intermediate', 'advanced'], false);
if ($level !== null) {
    $sets[] = 'experience_level = ?';
    $params[] = $level;
}

$new = in_str($b, 'new_password', false, 8, 128, false);
if ($new !== null) {
    $current = in_str($b, 'current_password', true, 1, 128, false);
    $hash = db_val('SELECT password_hash FROM users WHERE user_id = ?', [$uid]);
    if (!password_verify($current, $hash)) {
        fail(403, 'Current password is incorrect');
    }
    if (password_metrics($new)['is_common']) {
        fail(422, 'Password is too common');
    }
    $sets[] = 'password_hash = ?';
    $params[] = password_hash($new, PASSWORD_DEFAULT);
}

if (!$sets) {
    fail(422, 'Nothing to update');
}

$params[] = $uid;
db_exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $params);

if ($new !== null) {
    db_exec('UPDATE auth_tokens SET revoked = 1 WHERE user_id = ? AND token_id <> ?', [$uid, $user['token_id']]);
}

$fresh = db_row('SELECT user_id, username, email, display_name, role, experience_level FROM users WHERE user_id = ?', [$uid]);
ok(['user' => public_user($fresh)]);
