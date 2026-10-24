<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$attemptId = in_int(body(), 'attempt_id', true, 1);

$attempt = db_row(
    'SELECT a.attempt_id, a.quiz_id, a.completed_at, z.pass_mark_percent
     FROM quiz_attempts a JOIN quizzes z ON z.quiz_id = a.quiz_id
     WHERE a.attempt_id = ? AND a.user_id = ?',
    [$attemptId, $user['user_id']]
);
if (!$attempt) {
    fail(404, 'Attempt not found');
}

$newAchievements = [];
if ($attempt['completed_at'] === null) {
    $total = (int)db_val(
        'SELECT COUNT(*) FROM quiz_questions qq JOIN questions q ON q.question_id = qq.question_id AND q.is_active = 1 WHERE qq.quiz_id = ?',
        [$attempt['quiz_id']]
    );
    $score = (int)db_val('SELECT COALESCE(SUM(is_correct), 0) FROM attempt_answers WHERE attempt_id = ?', [$attemptId]);
    $percentage = $total ? round(100 * $score / $total, 2) : 0;
    $passed = $percentage >= (int)$attempt['pass_mark_percent'] ? 1 : 0;

    db_exec(
        'UPDATE quiz_attempts SET completed_at = NOW(), score = ?, total_questions = ?, percentage = ?, passed = ? WHERE attempt_id = ?',
        [$score, $total, $percentage, $passed, $attemptId]
    );
    $newAchievements = award_achievements($user['user_id']);
}

$result = db_row('SELECT score, total_questions, percentage, passed FROM quiz_attempts WHERE attempt_id = ?', [$attemptId]);
$topics = db_rows(
    'SELECT t.topic_id, t.name AS topic, SUM(a.is_correct) AS correct, COUNT(*) AS total
     FROM attempt_answers a
     JOIN questions q ON q.question_id = a.question_id
     JOIN topics t ON t.topic_id = q.topic_id
     WHERE a.attempt_id = ?
     GROUP BY t.topic_id, t.name',
    [$attemptId]
);
foreach ($topics as &$t) {
    $t['correct'] = (int)$t['correct'];
    $t['total'] = (int)$t['total'];
    $t['is_weak'] = $t['correct'] / $t['total'] < 0.6;
}

ok([
    'score' => (int)$result['score'],
    'total_questions' => (int)$result['total_questions'],
    'percentage' => (float)$result['percentage'],
    'passed' => (bool)$result['passed'],
    'topic_breakdown' => $topics,
    'new_achievements' => $newAchievements,
]);
