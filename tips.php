<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
auth();

$type = in_enum($_GET, 'type', ['quick', 'daily', 'scam_of_week']);
$tip = current_tip($type);
if (!$tip) {
    fail(404, 'No tip available');
}
ok(['tip' => $tip]);
