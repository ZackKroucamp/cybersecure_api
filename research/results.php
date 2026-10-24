<?php

require __DIR__ . '/../includes/bootstrap.php';

require_method('GET');
auth(['researcher', 'admin']);

$where = '';
$params = [];
$topicId = in_int($_GET, 'topic_id', false, 1);
if ($topicId !== null) {
    $where = 'WHERE topic_id = ?';
    $params[] = $topicId;
}

$rows = db_rows(
    "SELECT CONCAT('P', LPAD(user_id, 4, '0')) AS participant, topic,
            questions_answered, awareness_accuracy_pct, simulations_done,
            safe_choice_pct, warnings_followed_pct,
            ROUND(awareness_accuracy_pct - safe_choice_pct, 1) AS knowledge_behaviour_gap
     FROM v_research_awareness_vs_behaviour $where
     ORDER BY user_id, topic_id",
    $params
);

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="research_results.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['participant', 'topic', 'questions_answered', 'awareness_accuracy_pct', 'simulations_done', 'safe_choice_pct', 'warnings_followed_pct', 'knowledge_behaviour_gap']);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    exit;
}

$summary = db_rows(
    "SELECT topic, COUNT(*) AS participants,
            ROUND(AVG(awareness_accuracy_pct), 1) AS avg_awareness_pct,
            ROUND(AVG(safe_choice_pct), 1) AS avg_safe_choice_pct,
            ROUND(AVG(warnings_followed_pct), 1) AS avg_warnings_followed_pct
     FROM v_research_awareness_vs_behaviour $where
     GROUP BY topic_id, topic
     ORDER BY topic_id",
    $params
);

ok(['summary' => $summary, 'results' => $rows]);
