<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();

ok([
    'topics' => topic_progress($user['user_id']),
    'recommended' => recommended_lesson($user['user_id']),
]);
