<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();
$scenarioId = in_int($b, 'scenario_id', true, 1);
$action = in_enum($b, 'action', ['avoid', 'proceed']);
$ms = in_int($b, 'response_time_ms', false, 0, 3600000);

$scenario = db_row(
    'SELECT recommended_action, warning_message, explanation
     FROM simulation_scenarios WHERE scenario_id = ? AND is_active = 1',
    [$scenarioId]
);
if (!$scenario) {
    fail(404, 'Scenario not found');
}

$safe = $action === $scenario['recommended_action'] ? 1 : 0;
$hadWarning = $scenario['warning_message'] !== null && $scenario['warning_message'] !== '';
// A warning always urges caution, so it counts as followed only when the student avoided
$followed = $hadWarning ? ($action === 'avoid' ? 1 : 0) : null;

db_exec(
    'INSERT INTO simulation_decisions (user_id, scenario_id, student_action, is_safe_choice, followed_warning, response_time_ms)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$user['user_id'], $scenarioId, $action, $safe, $followed, $ms]
);

ok([
    'is_safe_choice' => (bool)$safe,
    'followed_warning' => $followed === null ? null : (bool)$followed,
    'recommended_action' => $scenario['recommended_action'],
    'explanation' => $scenario['explanation'],
], 201);
