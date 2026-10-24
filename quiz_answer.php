<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();
$attemptId = in_int($b, 'attempt_id', true, 1);
$questionId = in_int($b, 'question_id', true, 1);
$optionId = in_int($b, 'option_id', true, 1);
$ms = in_int($b, 'response_time_ms', false, 0, 3600000);

$attempt = db_row('SELECT quiz_id, completed_at FROM quiz_attempts WHERE attempt_id = ? AND user_id = ?', [$attemptId, $user['user_id']]);
if (!$attempt) {
    fail(404, 'Attempt not found');
}
if ($attempt['completed_at'] !== null) {
    fail(409, 'Attempt already finished');
}

$question = db_row(
    'SELECT q.question_id, q.explanation
     FROM quiz_questions qq JOIN questions q ON q.question_id = qq.question_id
     WHERE qq.quiz_id = ? AND qq.question_id = ? AND q.is_active = 1',
    [$attempt['quiz_id'], $questionId]
);
if (!$question) {
    fail(404, 'Question is not part of this quiz');
}

$option = db_row('SELECT is_correct FROM question_options WHERE option_id = ? AND question_id = ?', [$optionId, $questionId]);
if (!$option) {
    fail(422, 'Option does not belong to this question');
}

$isCorrect = (int)$option['is_correct'];
db_exec(
    'INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, is_correct, response_time_ms) VALUES (?, ?, ?, ?, ?)',
    [$attemptId, $questionId, $optionId, $isCorrect, $ms]
);

$correct = db_row('SELECT option_id, option_label FROM question_options WHERE question_id = ? AND is_correct = 1 LIMIT 1', [$questionId]);

ok([
    'is_correct' => (bool)$isCorrect,
    'correct_option_id' => (int)$correct['option_id'],
    'correct_label' => $correct['option_label'],
    'explanation' => $question['explanation'],
]);
