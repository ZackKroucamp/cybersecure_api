<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$types = ['phishing', 'website', 'download', 'warning'];

$sql = "SELECT s.scenario_id, s.topic_id, s.scenario_type, s.title, s.sender, s.subject,
               s.content_body, s.link_url, s.warning_message,
               EXISTS(SELECT 1 FROM simulation_decisions d
                       WHERE d.scenario_id = s.scenario_id AND d.user_id = ?) AS completed
        FROM simulation_scenarios s
        WHERE s.is_active = 1";
$params = [$user['user_id']];

$id = in_int($_GET, 'id', false, 1);
if ($id !== null) {
    $row = db_row($sql . ' AND s.scenario_id = ?', array_merge($params, [$id]));
    if (!$row) {
        fail(404, 'Scenario not found');
    }
    $row['completed'] = (bool)$row['completed'];
    ok(['scenario' => $row]);
}

$type = in_enum($_GET, 'type', $types, false);
if ($type !== null) {
    $sql .= ' AND s.scenario_type = ?';
    $params[] = $type;
}
$rows = db_rows($sql . ' ORDER BY s.scenario_id', $params);
foreach ($rows as &$r) {
    $r['completed'] = (bool)$r['completed'];
}

ok(['scenarios' => $rows]);
