<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
$user = auth();
ok(checkup_summary($user['user_id']));
