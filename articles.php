<?php

require __DIR__ . '/includes/bootstrap.php';

require_method('GET');
auth();

$id = in_int($_GET, 'id', false, 1);
if ($id !== null) {
    $article = db_row(
        'SELECT article_id, category, topic_id, title, summary, body FROM articles WHERE article_id = ? AND is_published = 1',
        [$id]
    );
    if (!$article) {
        fail(404, 'Article not found');
    }
    ok(['article' => $article]);
}

$category = in_enum($_GET, 'category', ['threat', 'resource']);
ok(['articles' => db_rows(
    'SELECT article_id, category, topic_id, title, summary FROM articles
     WHERE is_published = 1 AND category = ? ORDER BY sort_order, article_id',
    [$category]
)]);
