<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();
$lessonId = in_int($b, 'lesson_id', true, 1);
$percent = in_int($b, 'progress_percent', true, 0, 100);

if (!db_val('SELECT 1 FROM lessons WHERE lesson_id = ? AND is_published = 1', [$lessonId])) {
    fail(404, 'Lesson not found');
}

$completed = $percent >= 100;
db_exec(
    "INSERT INTO user_lesson_progress (user_id, lesson_id, status, progress_percent, started_at, completed_at)
     VALUES (?, ?, ?, ?, NOW(), " . ($completed ? 'NOW()' : 'NULL') . ")
     ON DUPLICATE KEY UPDATE
         progress_percent = GREATEST(progress_percent, VALUES(progress_percent)),
         status = IF(status = 'completed' OR VALUES(progress_percent) >= 100, 'completed', 'in_progress'),
         completed_at = IF(completed_at IS NOT NULL, completed_at, VALUES(completed_at))",
    [$user['user_id'], $lessonId, $completed ? 'completed' : 'in_progress', $percent]
);

ok([
    'progress' => db_row('SELECT status, progress_percent FROM user_lesson_progress WHERE user_id = ? AND lesson_id = ?', [$user['user_id'], $lessonId]),
    'new_achievements' => award_achievements($user['user_id']),
]);
