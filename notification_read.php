<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = auth();
$b = body();

if (!empty($b['all'])) {
    $n = db_exec('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$user['user_id']]);
} else {
    $id = in_int($b, 'notification_id', true, 1);
    $n = db_exec('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?', [$id, $user['user_id']]);
}

ok(['marked_read' => $n]);
