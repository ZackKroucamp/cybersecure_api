<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$topics = topic_progress($user['user_id']);
$modulesDone = count(array_filter($topics, function ($t) {
    return $t['total_lessons'] > 0 && $t['completed_lessons'] === $t['total_lessons'];
}));

ok([
    'display_name' => $user['display_name'] ?? $user['username'],
    'progress_percent' => overall_progress($topics),
    'modules_completed' => $modulesDone,
    'modules_total' => count($topics),
    'featured_topics' => array_slice($topics, 0, 3),
    'quick_tip' => current_tip('quick'),
]);
