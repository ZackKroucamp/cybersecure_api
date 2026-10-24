<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();

if (!isset($_GET['id'])) {
    $type = in_enum($_GET, 'type', ['quiz', 'awareness'], false) ?? 'quiz';
    $sql = "SELECT z.quiz_id, z.title, z.description, z.quiz_type, z.topic_id, t.name AS topic_name,
                   z.lesson_id, z.pass_mark_percent,
                   (SELECT COUNT(*) FROM quiz_questions qq
                      JOIN questions q ON q.question_id = qq.question_id AND q.is_active = 1
                     WHERE qq.quiz_id = z.quiz_id) AS question_count,
                   (SELECT MAX(a.percentage) FROM quiz_attempts a
                     WHERE a.quiz_id = z.quiz_id AND a.user_id = ? AND a.completed_at IS NOT NULL) AS best_score
            FROM quizzes z
            LEFT JOIN topics t ON t.topic_id = z.topic_id
            WHERE z.is_published = 1 AND z.quiz_type = ?";
    $params = [$user['user_id'], $type];
    $topicId = in_int($_GET, 'topic_id', false, 1);
    if ($topicId !== null) {
        $sql .= ' AND z.topic_id = ?';
        $params[] = $topicId;
    }
    ok(['quizzes' => db_rows($sql . ' ORDER BY z.quiz_id', $params)]);
}

$id = in_int($_GET, 'id', true, 1);
$quiz = db_row(
    'SELECT quiz_id, title, description, quiz_type, pass_mark_percent
     FROM quizzes WHERE quiz_id = ? AND is_published = 1',
    [$id]
);
if (!$quiz) {
    fail(404, 'Quiz not found');
}

$questions = db_rows(
    'SELECT q.question_id, q.question_text
     FROM quiz_questions qq
     JOIN questions q ON q.question_id = qq.question_id AND q.is_active = 1
     WHERE qq.quiz_id = ?
     ORDER BY qq.sort_order, q.question_id',
    [$id]
);

$byQuestion = [];
if ($questions) {
    $ids = array_column($questions, 'question_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_rows("SELECT option_id, question_id, option_label, option_text FROM question_options WHERE question_id IN ($in) ORDER BY question_id, option_label", $ids) as $o) {
        $byQuestion[$o['question_id']][] = [
            'option_id' => (int)$o['option_id'],
            'label' => $o['option_label'],
            'text' => $o['option_text'],
        ];
    }
}
foreach ($questions as &$q) {
    $q['options'] = $byQuestion[$q['question_id']] ?? [];
}

ok(['quiz' => $quiz, 'questions' => $questions]);
