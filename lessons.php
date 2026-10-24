<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$topicId = in_int($_GET, 'topic_id', true, 1);

$topic = db_row('SELECT topic_id, name, tagline FROM topics WHERE topic_id = ? AND is_active = 1', [$topicId]);
if (!$topic) {
    fail(404, 'Topic not found');
}

$lessons = db_rows(
    "SELECT l.lesson_id, l.lesson_number, l.title, l.summary, l.difficulty, l.estimated_minutes,
            COALESCE(p.status, 'not_started') AS status, COALESCE(p.progress_percent, 0) AS progress_percent
     FROM lessons l
     LEFT JOIN user_lesson_progress p ON p.lesson_id = l.lesson_id AND p.user_id = ?
     WHERE l.topic_id = ? AND l.is_published = 1
     ORDER BY l.lesson_number",
    [$user['user_id'], $topicId]
);

ok(['topic' => $topic, 'lessons' => $lessons]);
