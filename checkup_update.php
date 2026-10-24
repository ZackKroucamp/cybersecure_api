<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();
$itemId = in_int($b, 'item_id', true, 1);
$status = in_enum($b, 'status', ['done', 'needs_attention']);

if (!db_val('SELECT 1 FROM checkup_items WHERE item_id = ? AND is_active = 1', [$itemId])) {
    fail(404, 'Checkup item not found');
}

db_exec(
    'INSERT INTO user_checkup_status (user_id, item_id, status) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE status = VALUES(status)',
    [$user['user_id'], $itemId, $status]
);

ok(checkup_summary($user['user_id']));
