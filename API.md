# CyberSecure API

Base URL (XAMPP): `http://localhost/cybersecure_api/`
Android emulator: `http://10.0.2.2/cybersecure_api/`

All requests and responses are JSON. Send `Content-Type: application/json` on POST.
Every endpoint except register and login needs `Authorization: Bearer <token>`.

## Response format

```json
{ "success": true,  "data": { } }
{ "success": false, "error": "Message safe to show to the user" }
```

| Status | Meaning |
|---|---|
| 200 / 201 | Success / created |
| 400 | Body is not valid JSON |
| 401 | Missing, invalid, expired or revoked token (send the user to login) |
| 403 | Wrong role, or wrong current password |
| 404 | Record not found |
| 405 | Wrong HTTP method |
| 409 | Duplicate or already done (e.g. question already answered) |
| 422 | Validation failed; `error` says which field |
| 500 | Server error |

## Auth

| Endpoint | Body | Returns |
|---|---|---|
| `POST auth/register.php` | `username`, `email`, `password` (min 8, not common), `display_name?` | 201 `user`, `token`, `expires_at` |
| `POST auth/login.php` | `login` (username or email), `password` | `user`, `token`, `expires_at` |
| `POST auth/logout.php` | – | `logged_out` |

Store the token securely (EncryptedSharedPreferences) and send it on every call.

## Learn (screens 01, 02, 03)

| Endpoint | Params | Returns |
|---|---|---|
| `GET home.php` | – | `display_name`, `progress_percent`, `modules_completed`, `modules_total`, `featured_topics[]`, `quick_tip` |
| `GET topics.php` | – | `topics[]` (`topic_id`, `name`, `tagline`, `icon`, `total_lessons`, `completed_lessons`, `percent_complete`), `recommended` (lesson card or null) |
| `GET lessons.php` | `topic_id` | `topic`, `lessons[]` (`lesson_id`, `lesson_number`, `title`, `difficulty`, `estimated_minutes`, `status`, `progress_percent`) |
| `GET lesson.php` | `id` | `lesson` (includes `lesson_number`, `lessons_in_topic` for "Lesson 1 of 4"), `sections[]` (`section_type` text/bullets/callout, `heading`, `body`, `items`), `progress`, `quiz_id` (quick check) |
| `POST lesson_progress.php` | `lesson_id`, `progress_percent` 0-100 | `progress`, `new_achievements[]`. Progress never decreases; 100 completes the lesson. |

## Quizzes (screen 04)

Flow: `quiz.php?id=` then `quiz_start.php`, then `quiz_answer.php` once per question, then `quiz_finish.php`.

| Endpoint | Params / body | Returns |
|---|---|---|
| `GET quiz.php` | `type?` quiz (default) or awareness, `topic_id?` | `quizzes[]` with `question_count`, `best_score` |
| `GET quiz.php` | `id` | `quiz`, `questions[]` each with `options[]` (`option_id`, `label`, `text`). The correct answer is not included. |
| `POST quiz_start.php` | `quiz_id` | 201 `attempt_id`, `total_questions` |
| `POST quiz_answer.php` | `attempt_id`, `question_id`, `option_id`, `response_time_ms?` | `is_correct`, `correct_option_id`, `correct_label`, `explanation` |
| `POST quiz_finish.php` | `attempt_id` | `score`, `total_questions`, `percentage`, `passed`, `topic_breakdown[]` (`is_weak`), `new_achievements[]`. Safe to call twice. |

The awareness baseline questionnaire used for the research is a quiz with `type=awareness`.

## Simulations and password activity

| Endpoint | Params / body | Returns |
|---|---|---|
| `GET scenarios.php` | `type?` phishing, website, download, warning; or `id` | `scenarios[]` / `scenario` (`title`, `sender`, `subject`, `content_body`, `link_url`, `warning_message`, `completed`). The safe answer is not included. |
| `POST scenario_decision.php` | `scenario_id`, `action` avoid or proceed, `response_time_ms?` | 201 `is_safe_choice`, `followed_warning` (null when no warning was shown), `recommended_action`, `explanation` |
| `POST password_check.php` | `password` | `score` 0-100, `is_secure`, `checks{}`, `suggestions[]`. The password itself is never stored. |

## Security checkup (screen 05)

| Endpoint | Body | Returns |
|---|---|---|
| `GET checkup.php` | – | `score`, `max_score`, `message`, `suggested_topic_id`, `items[]` (`item_id`, `title`, `status` done/needs_attention) |
| `POST checkup_update.php` | `item_id`, `status` | same as `checkup.php` after the change |

## Content (screens 06, 08)

| Endpoint | Params | Returns |
|---|---|---|
| `GET articles.php` | `category` threat or resource; or `id` | `articles[]` / `article` (with `body`) |
| `GET glossary.php` | `q?` | `terms[]` |
| `GET tips.php` | `type` quick, daily, scam_of_week | `tip` (`title`, `message`) or 404 |

## Profile and notifications (screen 07)

| Endpoint | Body / params | Returns |
|---|---|---|
| `GET profile.php` | – | `user`, `percent_complete`, `stats` (`lessons_completed`, `quizzes_passed`, `best_score`), `achievements[]` (`earned`, `earned_at`) |
| `POST profile_update.php` | any of `display_name`, `experience_level`, or `new_password` + `current_password` | `user`. Changing the password signs out other devices. |
| `GET notifications.php` | – | `unread_count`, `notifications[]`. A weekly security reminder is created automatically. |
| `POST notification_read.php` | `notification_id` or `all: true` | `marked_read` |

## Researcher (role: researcher or admin)

`GET research/results.php?topic_id=&format=csv` returns per-participant, per-topic awareness accuracy against safe-choice and warning-followed rates, plus a per-topic `summary`. Participants are pseudonymised (P0001); no emails are returned. Add `format=csv` to download.

## Admin (role: admin)

`GET admin/manage_content.php?entity=<name>[&id=]` lists or reads items (inactive ones included).
`POST admin/manage_content.php` with `{ "entity", "action": "create" | "update" | "delete", "id", "data": { } }`.

Entities: `question`, `quiz`, `lesson`, `scenario`, `article`, `tip`, `glossary`.

- `question` accepts `options: [{ "text", "is_correct" }]` (2-6 options, exactly one correct) and `quiz_ids: [1, 2]`. Options cannot be replaced once students have answered; create a new question instead.
- `lesson` accepts `sections: [{ "section_type", "heading", "body", "items" }]`.
- Delete hides the item (`is_active` / `is_published` = 0) so research history stays intact. Only `glossary` is deleted permanently.

Example:

```json
{ "entity": "question", "action": "create", "data": {
    "topic_id": 1,
    "question_text": "Which link is safest?",
    "explanation": "Always check the domain.",
    "options": [ { "text": "paypa1.com/login" }, { "text": "paypal.com/login", "is_correct": true } ],
    "quiz_ids": [1] } }
```

## Android notes

- Add `<uses-permission android:name="android.permission.INTERNET"/>`.
- Plain `http://` needs `android:usesCleartextTraffic="true"` in the manifest during development only. Use HTTPS in any real deployment.
- Run requests off the main thread (Volley and Retrofit do this when used asynchronously).
- On any 401, clear the stored token and return to the login screen.
