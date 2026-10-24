<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$uid = $user['user_id'];

$achievements = db_rows(
    'SELECT a.achievement_id, a.code, a.name, a.description, a.icon, ua.earned_at
     FROM achievements a
     LEFT JOIN user_achievements ua ON ua.achievement_id = a.achievement_id AND ua.user_id = ?
     ORDER BY ua.earned_at IS NULL, ua.earned_at, a.achievement_id',
    [$uid]
);
foreach ($achievements as &$a) {
    $a['earned'] = $a['earned_at'] !== null;
}

ok([
    'user' => public_user($user),
    'percent_complete' => overall_progress(topic_progress($uid)),
    'stats' => user_stats($uid),
    'achievements' => $achievements,
]);
