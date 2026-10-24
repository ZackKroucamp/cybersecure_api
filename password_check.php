<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$password = in_str(body(), 'password', true, 1, 128, false);

$m = password_metrics($password);

db_exec(
    'INSERT INTO password_checks
        (user_id, length_chars, has_upper, has_lower, has_digit, has_symbol, is_common, calculated_score, is_secure)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [
        $user['user_id'], $m['length_chars'], (int)$m['has_upper'], (int)$m['has_lower'],
        (int)$m['has_digit'], (int)$m['has_symbol'], (int)$m['is_common'], $m['score'], (int)$m['is_secure'],
    ]
);

$tips = [];
if ($m['length_chars'] < 12) {
    $tips[] = 'Use at least 12 characters';
}
if (!$m['has_upper']) {
    $tips[] = 'Add an uppercase letter';
}
if (!$m['has_lower']) {
    $tips[] = 'Add a lowercase letter';
}
if (!$m['has_digit']) {
    $tips[] = 'Add a number';
}
if (!$m['has_symbol']) {
    $tips[] = 'Add a symbol';
}
if ($m['is_common']) {
    $tips[] = 'Avoid common words and patterns';
}

ok([
    'score' => $m['score'],
    'is_secure' => $m['is_secure'],
    'checks' => [
        'length_ok' => $m['length_chars'] >= 12,
        'has_upper' => $m['has_upper'],
        'has_lower' => $m['has_lower'],
        'has_digit' => $m['has_digit'],
        'has_symbol' => $m['has_symbol'],
        'not_common' => !$m['is_common'],
    ],
    'suggestions' => $tips,
]);
