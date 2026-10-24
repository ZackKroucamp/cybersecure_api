<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$quizId = in_int(body(), 'quiz_id', true, 1);

if (!db_val('SELECT 1 FROM quizzes WHERE quiz_id = ? AND is_published = 1', [$quizId])) {
    fail(404, 'Quiz not found');
}
$total = (int)db_val(
    'SELECT COUNT(*) FROM quiz_questions qq JOIN questions q ON q.question_id = qq.question_id AND q.is_active = 1 WHERE qq.quiz_id = ?',
    [$quizId]
);
if ($total === 0) {
    fail(422, 'Quiz has no questions');
}

db_exec('INSERT INTO quiz_attempts (user_id, quiz_id) VALUES (?, ?)', [$user['user_id'], $quizId]);
ok(['attempt_id' => (int)db()->lastInsertId(), 'total_questions' => $total], 201);
