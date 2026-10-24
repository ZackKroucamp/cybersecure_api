<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$id = in_int($_GET, 'id', true, 1);

$lesson = db_row(
    'SELECT l.lesson_id, l.topic_id, l.lesson_number, l.title, l.summary, l.difficulty, l.estimated_minutes,
            t.name AS topic_name
     FROM lessons l JOIN topics t ON t.topic_id = l.topic_id
     WHERE l.lesson_id = ? AND l.is_published = 1',
    [$id]
);
if (!$lesson) {
    fail(404, 'Lesson not found');
}

$lesson['lessons_in_topic'] = (int)db_val('SELECT COUNT(*) FROM lessons WHERE topic_id = ? AND is_published = 1', [$lesson['topic_id']]);

$sections = db_rows(
    'SELECT section_type, heading, body, items FROM lesson_sections WHERE lesson_id = ? ORDER BY sort_order, section_id',
    [$id]
);
foreach ($sections as &$s) {
    $s['items'] = $s['items'] === null ? null : json_decode($s['items'], true);
}

$progress = db_row('SELECT status, progress_percent FROM user_lesson_progress WHERE user_id = ? AND lesson_id = ?', [$user['user_id'], $id])
    ?? ['status' => 'not_started', 'progress_percent' => 0];

ok([
    'lesson' => $lesson,
    'sections' => $sections,
    'progress' => $progress,
    'quiz_id' => db_val('SELECT quiz_id FROM quizzes WHERE lesson_id = ? AND is_published = 1 ORDER BY quiz_id LIMIT 1', [$id]),
]);
