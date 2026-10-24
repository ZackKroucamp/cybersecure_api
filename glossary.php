<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
auth();

$sql = 'SELECT term_id, term, definition FROM glossary_terms';
$params = [];
$q = in_str($_GET, 'q', false, 1, 50);
if ($q !== null) {
    $sql .= ' WHERE term LIKE ?';
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}

ok(['terms' => db_rows($sql . ' ORDER BY term', $params)]);
