<?php

require __DIR__ . '/../includes/bootstrap.php';

auth(['admin']);

function f(string $type, bool $req = false, bool $null = false, $opt = null): array
{
    return ['type' => $type, 'req' => $req, 'null' => $null, 'opt' => $opt];
}

$levels = ['beginner', 'intermediate', 'advanced'];
$entities = [
    'scenario' => ['table' => 'simulation_scenarios', 'pk' => 'scenario_id', 'flag' => 'is_active', 'fields' => [
        'topic_id' => f('int', true),
        'scenario_type' => f('enum', true, false, ['phishing', 'website', 'download', 'warning']),
        'title' => f('str', true, false, 100),
        'sender' => f('str', false, true, 150),
        'subject' => f('str', false, true, 200),
        'content_body' => f('text', true),
        'link_url' => f('str', false, true, 255),
        'warning_message' => f('text', false, true),
        'recommended_action' => f('enum', true, false, ['avoid', 'proceed']),
        'explanation' => f('text', true),
        'is_active' => f('bool'),
    ]],
    'question' => ['table' => 'questions', 'pk' => 'question_id', 'flag' => 'is_active', 'fields' => [
        'topic_id' => f('int', true),
        'question_text' => f('text', true),
        'explanation' => f('text', true),
        'difficulty' => f('enum', false, false, $levels),
        'is_active' => f('bool'),
    ]],
    'quiz' => ['table' => 'quizzes', 'pk' => 'quiz_id', 'flag' => 'is_published', 'fields' => [
        'topic_id' => f('int', false, true),
        'lesson_id' => f('int', false, true),
        'quiz_type' => f('enum', false, false, ['quiz', 'awareness']),
        'title' => f('str', true, false, 100),
        'description' => f('str', false, true, 255),
        'pass_mark_percent' => f('int'),
        'is_published' => f('bool'),
    ]],
    'lesson' => ['table' => 'lessons', 'pk' => 'lesson_id', 'flag' => 'is_published', 'fields' => [
        'topic_id' => f('int', true),
        'lesson_number' => f('int', true),
        'title' => f('str', true, false, 100),
        'summary' => f('str', false, true, 255),
        'difficulty' => f('enum', false, false, $levels),
        'estimated_minutes' => f('int'),
        'is_published' => f('bool'),
    ]],
    'article' => ['table' => 'articles', 'pk' => 'article_id', 'flag' => 'is_published', 'fields' => [
        'category' => f('enum', true, false, ['threat', 'resource']),
        'topic_id' => f('int', false, true),
        'title' => f('str', true, false, 100),
        'summary' => f('str', true, false, 255),
        'body' => f('text', false, true),
        'sort_order' => f('int'),
        'is_published' => f('bool'),
    ]],
    'tip' => ['table' => 'security_tips', 'pk' => 'tip_id', 'flag' => 'is_active', 'fields' => [
        'tip_type' => f('enum', true, false, ['quick', 'daily', 'scam_of_week']),
        'title' => f('str', false, true, 100),
        'message' => f('str', true, false, 255),
        'active_from' => f('date', false, true),
        'active_until' => f('date', false, true),
        'is_active' => f('bool'),
    ]],
    'glossary' => ['table' => 'glossary_terms', 'pk' => 'term_id', 'flag' => null, 'fields' => [
        'term' => f('str', true, false, 80),
        'definition' => f('text', true),
    ]],
];

function clean_fields(array $fields, array $in, bool $create): array
{
    $out = [];
    foreach ($fields as $name => $s) {
        if (!array_key_exists($name, $in)) {
            if ($create && $s['req']) {
                fail(422, "$name is required");
            }
            continue;
        }
        $v = $in[$name];
        if ($v === null || $v === '') {
            if (!$s['null']) {
                fail(422, "$name cannot be empty");
            }
            $out[$name] = null;
            continue;
        }
        switch ($s['type']) {
            case 'int':
                $v = filter_var($v, FILTER_VALIDATE_INT);
                if ($v === false || $v < 0) {
                    fail(422, "$name is invalid");
                }
                break;
            case 'bool':
                $v = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v === null) {
                    fail(422, "$name is invalid");
                }
                $v = (int)$v;
                break;
            case 'enum':
                if (!in_array($v, $s['opt'], true)) {
                    fail(422, "$name must be one of: " . implode(', ', $s['opt']));
                }
                break;
            case 'date':
                $d = is_string($v) ? DateTime::createFromFormat('Y-m-d', $v) : false;
                if (!$d || $d->format('Y-m-d') !== $v) {
                    fail(422, "$name must be YYYY-MM-DD");
                }
                break;
            default:
                if (!is_string($v)) {
                    fail(422, "$name is invalid");
                }
                $v = trim($v);
                if ($v === '' || ($s['type'] === 'str' && mb_strlen($v) > $s['opt'])) {
                    fail(422, "$name is invalid or too long");
                }
        }
        $out[$name] = $v;
    }
    return $out;
}

function save_children(string $entity, int $id, array $data): void
{
    if ($entity === 'question') {
        if (isset($data['options'])) {
            save_options($id, $data['options']);
        }
        if (isset($data['quiz_ids'])) {
            save_quiz_links($id, $data['quiz_ids']);
        }
    } elseif ($entity === 'lesson' && isset($data['sections'])) {
        save_sections($id, $data['sections']);
    }
}

function save_options(int $questionId, $options): void
{
    if (!is_array($options) || count($options) < 2 || count($options) > 6) {
        fail(422, 'options must contain between 2 and 6 items');
    }
    if (db_val('SELECT 1 FROM attempt_answers WHERE question_id = ? LIMIT 1', [$questionId])) {
        fail(409, 'This question already has responses; create a new question instead');
    }
    $correct = 0;
    $rows = [];
    foreach (array_values($options) as $i => $o) {
        $text = is_array($o) ? in_str($o, 'text', true, 1, 255) : null;
        if ($text === null) {
            fail(422, 'Each option needs text');
        }
        $isCorrect = !empty($o['is_correct']) ? 1 : 0;
        $correct += $isCorrect;
        $rows[] = [chr(65 + $i), $text, $isCorrect];
    }
    if ($correct !== 1) {
        fail(422, 'Exactly one option must be correct');
    }
    db_exec('DELETE FROM question_options WHERE question_id = ?', [$questionId]);
    foreach ($rows as [$label, $text, $isCorrect]) {
        db_exec(
            'INSERT INTO question_options (question_id, option_label, option_text, is_correct) VALUES (?, ?, ?, ?)',
            [$questionId, $label, $text, $isCorrect]
        );
    }
}

function save_quiz_links(int $questionId, $quizIds): void
{
    if (!is_array($quizIds)) {
        fail(422, 'quiz_ids must be an array');
    }
    $ids = [];
    foreach ($quizIds as $q) {
        $v = filter_var($q, FILTER_VALIDATE_INT);
        if ($v === false || $v < 1) {
            fail(422, 'quiz_ids contains an invalid id');
        }
        $ids[] = $v;
    }
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        db_exec("DELETE FROM quiz_questions WHERE question_id = ? AND quiz_id NOT IN ($in)", array_merge([$questionId], $ids));
    } else {
        db_exec('DELETE FROM quiz_questions WHERE question_id = ?', [$questionId]);
    }
    foreach ($ids as $quizId) {
        db_exec(
            'INSERT IGNORE INTO quiz_questions (quiz_id, question_id, sort_order)
             SELECT ?, ?, COALESCE(MAX(sort_order), 0) + 1 FROM quiz_questions WHERE quiz_id = ?',
            [$quizId, $questionId, $quizId]
        );
    }
}

function save_sections(int $lessonId, $sections): void
{
    if (!is_array($sections)) {
        fail(422, 'sections must be an array');
    }
    $rows = [];
    foreach (array_values($sections) as $i => $s) {
        if (!is_array($s)) {
            fail(422, 'Invalid section');
        }
        $items = $s['items'] ?? null;
        if ($items !== null && (!is_array($items) || array_filter($items, 'is_string') !== $items)) {
            fail(422, 'items must be an array of strings');
        }
        $rows[] = [
            in_enum($s, 'section_type', ['text', 'bullets', 'callout']),
            in_str($s, 'heading', false, 1, 100),
            in_str($s, 'body', false, 1, 65000),
            $items === null ? null : json_encode(array_values($items), JSON_UNESCAPED_UNICODE),
            in_int($s, 'sort_order', false, 0, 32000) ?? $i + 1,
        ];
    }
    db_exec('DELETE FROM lesson_sections WHERE lesson_id = ?', [$lessonId]);
    foreach ($rows as $r) {
        db_exec('INSERT INTO lesson_sections (lesson_id, section_type, heading, body, items, sort_order) VALUES (?, ?, ?, ?, ?, ?)', array_merge([$lessonId], $r));
    }
}

function load_children(string $entity, array $row, array $e): array
{
    $id = (int)$row[$e['pk']];
    if ($entity === 'question') {
        $row['options'] = db_rows('SELECT option_label, option_text, is_correct FROM question_options WHERE question_id = ? ORDER BY option_label', [$id]);
        $row['quiz_ids'] = array_map('intval', array_column(db_rows('SELECT quiz_id FROM quiz_questions WHERE question_id = ?', [$id]), 'quiz_id'));
    } elseif ($entity === 'lesson') {
        $sections = db_rows('SELECT section_type, heading, body, items, sort_order FROM lesson_sections WHERE lesson_id = ? ORDER BY sort_order', [$id]);
        foreach ($sections as &$s) {
            $s['items'] = $s['items'] === null ? null : json_decode($s['items'], true);
        }
        $row['sections'] = $sections;
    }
    return $row;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? body() : $_GET;
$entity = in_enum($input, 'entity', array_keys($entities));
$e = $entities[$entity];
$table = $e['table'];
$pk = $e['pk'];

if ($method === 'GET') {
    $id = in_int($_GET, 'id', false, 1);
    if ($id !== null) {
        $row = db_row("SELECT * FROM $table WHERE $pk = ?", [$id]);
        if (!$row) {
            fail(404, 'Not found');
        }
        ok(['item' => load_children($entity, $row, $e)]);
    }
    ok(['items' => db_rows("SELECT * FROM $table ORDER BY $pk DESC LIMIT 500")]);
}

require_method('POST');
$action = in_enum($input, 'action', ['create', 'update', 'delete']);

if ($action === 'create') {
    $data = $input['data'] ?? null;
    if (!is_array($data)) {
        fail(422, 'data is required');
    }
    $vals = clean_fields($e['fields'], $data, true);
    db()->beginTransaction();
    $cols = implode(', ', array_keys($vals));
    $marks = implode(', ', array_fill(0, count($vals), '?'));
    db_exec("INSERT INTO $table ($cols) VALUES ($marks)", array_values($vals));
    $id = (int)db()->lastInsertId();
    save_children($entity, $id, $data);
    db()->commit();
    ok(['id' => $id], 201);
}

$id = in_int($input, 'id', true, 1);
if (!db_val("SELECT 1 FROM $table WHERE $pk = ?", [$id])) {
    fail(404, 'Not found');
}

if ($action === 'update') {
    $data = $input['data'] ?? null;
    if (!is_array($data)) {
        fail(422, 'data is required');
    }
    $vals = clean_fields($e['fields'], $data, false);
    db()->beginTransaction();
    if ($vals) {
        $sets = implode(', ', array_map(function ($c) {
            return "$c = ?";
        }, array_keys($vals)));
        db_exec("UPDATE $table SET $sets WHERE $pk = ?", array_merge(array_values($vals), [$id]));
    }
    save_children($entity, $id, $data);
    db()->commit();
    ok(['id' => $id]);
}

if ($e['flag'] !== null) {
    db_exec("UPDATE $table SET {$e['flag']} = 0 WHERE $pk = ?", [$id]);
} else {
    db_exec("DELETE FROM $table WHERE $pk = ?", [$id]);
}
ok(['id' => $id, 'deleted' => true]);
