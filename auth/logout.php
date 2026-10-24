<?php

require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = auth();
db_exec('UPDATE auth_tokens SET revoked = 1 WHERE token_id = ?', [$user['token_id']]);
ok(['logged_out' => true]);
