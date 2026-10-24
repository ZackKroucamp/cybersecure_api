<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
$uid = $user['user_id'];

ensure_reminder($uid);

$visible = 'user_id = ? AND (scheduled_for IS NULL OR scheduled_for <= NOW())';
$rows = db_rows(
    "SELECT notification_id, type, title, message, is_read, created_at
     FROM notifications WHERE $visible ORDER BY created_at DESC, notification_id DESC LIMIT 50",
    [$uid]
);
foreach ($rows as &$r) {
    $r['is_read'] = (bool)$r['is_read'];
}

ok([
    'unread_count' => (int)db_val("SELECT COUNT(*) FROM notifications WHERE $visible AND is_read = 0", [$uid]),
    'notifications' => $rows,
]);
