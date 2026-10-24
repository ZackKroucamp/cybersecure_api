<?php

const DUMMY_HASH = '$2y$10$xtfzOv1QeJ.OmBFo9JrWeOs5sLG3ISRTEECugGas9.iS3Ms9YF/.C';

function public_user(array $u): array
{
    return [
        'user_id' => (int)$u['user_id'],
        'username' => $u['username'],
        'email' => $u['email'],
        'display_name' => $u['display_name'] ?? $u['username'],
        'role' => $u['role'],
        'experience_level' => $u['experience_level'],
    ];
}

function issue_token(int $uid): array
{
    $token = bin2hex(random_bytes(32));
    $days = (int)config()['token_ttl_days'];
    db_exec(
        'INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY))',
        [$uid, hash('sha256', $token)]
    );
    $expires = db_val('SELECT DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY)');
    return ['token' => $token, 'expires_at' => $expires];
}

function password_metrics(string $pw): array
{
    $len = mb_strlen($pw);
    $has = [
        'has_upper' => (bool)preg_match('/\p{Lu}/u', $pw),
        'has_lower' => (bool)preg_match('/\p{Ll}/u', $pw),
        'has_digit' => (bool)preg_match('/\d/', $pw),
        'has_symbol' => (bool)preg_match('/[^\p{L}\d]/u', $pw),
    ];

    static $common;
    $common = $common ?? require __DIR__ . '/common_passwords.php';
    $base = preg_replace('/[\d\W_]+$/u', '', mb_strtolower($pw));
    $isCommon = in_array(mb_strtolower($pw), $common, true) || in_array($base, $common, true);

    $classes = count(array_filter($has));
    $score = (int)round(min($len, 16) * 2.5 + $classes * 15);
    if ($isCommon) {
        $score = min($score, 10);
    }

    return $has + [
        'length_chars' => $len,
        'is_common' => $isCommon,
        'score' => $score,
        'is_secure' => !$isCommon && $len >= 12 && $classes >= 3,
    ];
}

function topic_progress(int $uid): array
{
    $rows = db_rows(
        "SELECT t.topic_id, t.slug, t.name, t.tagline, t.icon,
                COUNT(l.lesson_id) AS total_lessons, COUNT(p.lesson_id) AS completed_lessons
         FROM topics t
         LEFT JOIN lessons l ON l.topic_id = t.topic_id AND l.is_published = 1
         LEFT JOIN user_lesson_progress p
                ON p.lesson_id = l.lesson_id AND p.user_id = ? AND p.status = 'completed'
         WHERE t.is_active = 1
         GROUP BY t.topic_id, t.slug, t.name, t.tagline, t.icon, t.sort_order
         ORDER BY t.sort_order",
        [$uid]
    );
    foreach ($rows as &$r) {
        $r['topic_id'] = (int)$r['topic_id'];
        $r['total_lessons'] = (int)$r['total_lessons'];
        $r['completed_lessons'] = (int)$r['completed_lessons'];
        $r['percent_complete'] = $r['total_lessons']
            ? (int)round(100 * $r['completed_lessons'] / $r['total_lessons'])
            : 0;
    }
    return $rows;
}

function user_stats(int $uid): array
{
    $s = db_row('SELECT lessons_completed, quizzes_passed, best_score FROM v_user_stats WHERE user_id = ?', [$uid]);
    return [
        'lessons_completed' => (int)($s['lessons_completed'] ?? 0),
        'quizzes_passed' => (int)($s['quizzes_passed'] ?? 0),
        'best_score' => isset($s['best_score']) ? (float)$s['best_score'] : null,
    ];
}

function overall_progress(array $topics): int
{
    $total = array_sum(array_column($topics, 'total_lessons'));
    $done = array_sum(array_column($topics, 'completed_lessons'));
    return $total ? (int)round(100 * $done / $total) : 0;
}

function notify(int $uid, string $type, string $title, string $message): void
{
    db_exec(
        'INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)',
        [$uid, $type, $title, $message]
    );
}

function award_achievements(int $uid): array
{
    $stats = user_stats($uid);
    $completed = [];
    foreach (topic_progress($uid) as $t) {
        $completed[$t['topic_id']] = $t['total_lessons'] > 0 && $t['completed_lessons'] === $t['total_lessons'];
    }

    $pending = db_rows(
        'SELECT a.achievement_id, a.code, a.name, a.criteria_type, a.criteria_value, a.topic_id
         FROM achievements a
         LEFT JOIN user_achievements ua ON ua.achievement_id = a.achievement_id AND ua.user_id = ?
         WHERE ua.user_id IS NULL',
        [$uid]
    );

    $earned = [];
    foreach ($pending as $a) {
        $need = (int)$a['criteria_value'];
        switch ($a['criteria_type']) {
            case 'topic_completed':
                $met = $a['topic_id'] !== null
                    ? !empty($completed[(int)$a['topic_id']])
                    : count(array_filter($completed)) >= $need;
                break;
            case 'quizzes_passed':
                $met = $stats['quizzes_passed'] >= $need;
                break;
            case 'lessons_completed':
                $met = $stats['lessons_completed'] >= $need;
                break;
            case 'score_reached':
                $met = $stats['best_score'] !== null && $stats['best_score'] >= $need;
                break;
            default:
                $met = false;
        }
        if ($met && db_exec('INSERT IGNORE INTO user_achievements (user_id, achievement_id) VALUES (?, ?)', [$uid, $a['achievement_id']])) {
            notify($uid, 'achievement', 'Achievement unlocked', $a['name']);
            $earned[] = ['code' => $a['code'], 'name' => $a['name']];
        }
    }
    return $earned;
}

function recommended_lesson(int $uid): ?array
{
    $next = "SELECT l.lesson_id
             FROM lessons l
             JOIN topics t ON t.topic_id = l.topic_id AND t.is_active = 1
             LEFT JOIN user_lesson_progress p
                    ON p.lesson_id = l.lesson_id AND p.user_id = ? AND p.status = 'completed'
             WHERE l.is_published = 1 AND p.lesson_id IS NULL";

    $lessonId = null;
    $reason = null;

    $weak = db_rows(
        'SELECT q.topic_id, AVG(a.is_correct) AS acc
         FROM attempt_answers a
         JOIN quiz_attempts qa ON qa.attempt_id = a.attempt_id
         JOIN questions q ON q.question_id = a.question_id
         WHERE qa.user_id = ?
         GROUP BY q.topic_id
         HAVING acc < 0.7
         ORDER BY acc',
        [$uid]
    );
    foreach ($weak as $w) {
        $id = db_val($next . ' AND l.topic_id = ? ORDER BY l.lesson_number LIMIT 1', [$uid, $w['topic_id']]);
        if ($id) {
            $lessonId = (int)$id;
            $reason = 'Based on your quiz results';
            break;
        }
    }
    if (!$lessonId) {
        $id = db_val($next . ' ORDER BY t.sort_order, l.lesson_number LIMIT 1', [$uid]);
        if ($id) {
            $lessonId = (int)$id;
            $reason = 'Next up in your learning path';
        }
    }
    if (!$lessonId) {
        return null;
    }

    db_exec('UPDATE user_recommendations SET is_dismissed = 1 WHERE user_id = ? AND lesson_id <> ? AND is_dismissed = 0', [$uid, $lessonId]);
    if (!db_val('SELECT 1 FROM user_recommendations WHERE user_id = ? AND lesson_id = ? AND is_dismissed = 0', [$uid, $lessonId])) {
        db_exec('INSERT INTO user_recommendations (user_id, lesson_id, reason) VALUES (?, ?, ?)', [$uid, $lessonId, $reason]);
    }

    $card = db_row(
        'SELECT l.lesson_id, l.title, t.name AS topic_name, l.estimated_minutes, l.difficulty,
                COALESCE(p.progress_percent, 0) AS progress_percent
         FROM lessons l
         JOIN topics t ON t.topic_id = l.topic_id
         LEFT JOIN user_lesson_progress p ON p.lesson_id = l.lesson_id AND p.user_id = ?
         WHERE l.lesson_id = ?',
        [$uid, $lessonId]
    );
    $card['reason'] = $reason;
    return $card;
}

function checkup_summary(int $uid): array
{
    $items = db_rows(
        "SELECT i.item_id, i.title, i.description, i.weight, i.topic_id,
                COALESCE(s.status, 'needs_attention') AS status
         FROM checkup_items i
         LEFT JOIN user_checkup_status s ON s.item_id = i.item_id AND s.user_id = ?
         WHERE i.is_active = 1
         ORDER BY i.sort_order",
        [$uid]
    );

    $total = 0;
    $done = 0;
    $suggested = null;
    foreach ($items as &$i) {
        $i['item_id'] = (int)$i['item_id'];
        $i['weight'] = (int)$i['weight'];
        $total += $i['weight'];
        if ($i['status'] === 'done') {
            $done += $i['weight'];
        } elseif ($suggested === null && $i['topic_id'] !== null) {
            $suggested = (int)$i['topic_id'];
        }
    }

    $score = $total ? (int)round(100 * $done / $total) : 0;
    $message = $score >= 80 ? 'Great job, keep it up'
        : ($score >= 50 ? "Good start, let's make it stronger" : "Let's get your security habits started");

    return [
        'score' => $score,
        'max_score' => 100,
        'message' => $message,
        'suggested_topic_id' => $suggested,
        'items' => $items,
    ];
}

function ensure_reminder(int $uid): void
{
    if (db_val("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'reminder' AND created_at > NOW() - INTERVAL 7 DAY LIMIT 1", [$uid])) {
        return;
    }
    $item = db_val(
        "SELECT i.title
         FROM checkup_items i
         LEFT JOIN user_checkup_status s ON s.item_id = i.item_id AND s.user_id = ?
         WHERE i.is_active = 1 AND (s.status IS NULL OR s.status = 'needs_attention')
         ORDER BY i.sort_order LIMIT 1",
        [$uid]
    );
    $message = $item
        ? "Review your security checkup item: $item."
        : 'Take a minute to review your account security settings.';
    notify($uid, 'reminder', 'Security reminder', $message);
}

function current_tip(string $type): ?array
{
    $seed = $type === 'scam_of_week' ? 'YEARWEEK(CURDATE(), 1)' : 'CURDATE()';
    return db_row(
        "SELECT tip_id, title, message FROM security_tips
         WHERE tip_type = ? AND is_active = 1
           AND (active_from IS NULL OR active_from <= CURDATE())
           AND (active_until IS NULL OR active_until >= CURDATE())
         ORDER BY MD5(CONCAT(tip_id, $seed)) LIMIT 1",
        [$type]
    );
}
