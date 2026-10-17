
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

function escapeHTML($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

// Mark one notification or all notifications as read.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Invalid form session. Refresh the page and try again.');
    }

    try {
        if ($action === 'mark_all') {
            $stmt = $pdo->prepare(
                'UPDATE notifications
                 SET is_read = 1
                 WHERE recipient_id = :user_id
                   AND is_read = 0'
            );

            $stmt->execute(['user_id' => $userId]);

        } elseif ($action === 'mark_one') {
            $idValue = $_POST['notification_id'] ?? null;

            $notificationId = is_string($idValue)
                ? filter_var($idValue, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1]
                ])
                : false;

            if (!$notificationId) {
                http_response_code(400);
                exit('Invalid notification ID.');
            }

            $stmt = $pdo->prepare(
                'UPDATE notifications
                 SET is_read = 1
                 WHERE id = :id
                   AND recipient_id = :user_id'
            );

            $stmt->execute([
                'id' => $notificationId,
                'user_id' => $userId
            ]);

        } else {
            http_response_code(400);
            exit('Invalid notification action.');
        }

        header('Location: notifications.php?updated=1');
        exit;

    } catch (PDOException $e) {
        error_log($e->getMessage());
        $error = 'Unable to update notifications. Please try again.';
    }
}

try {
    // Count unread notifications belonging to this student.
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications
         WHERE recipient_id = :user_id
           AND is_read = 0'
    );

    $countStmt->execute(['user_id' => $userId]);
    $unreadCount = (int)$countStmt->fetchColumn();

    // Show the newest 100 notifications.
    $stmt = $pdo->prepare(
        'SELECT
            n.id,
            n.project_id,
            n.actor_id,
            n.type,
            n.message,
            n.is_read,
            n.created_at,
            p.title AS project_title
         FROM notifications n
         JOIN projects p ON p.id = n.project_id
         WHERE n.recipient_id = :user_id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 100'
    );

    $stmt->execute(['user_id' => $userId]);
    $notifications = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $unreadCount = 0;
    $notifications = [];
    $error = 'Unable to load notifications. Check the database and try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Notifications | StudentConnect</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fc;
            color: #1e293b;
        }

        header {
            background: #173b70;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        nav a {
            color: white;
            text-decoration: none;
        }

        nav a:hover {
            text-decoration: underline;
        }

        main {
            max-width: 900px;
            width: 92%;
            margin: 35px auto;
        }

        .top-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .count {
            display: inline-block;
            padding: 7px 11px;
            background: #dbeafe;
            color: #173b70;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .muted {
            color: #64748b;
            line-height: 1.6;
        }

        .notification {
            background: white;
            padding: 21px;
            margin: 16px 0;
            border-radius: 11px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 3px 12px #00000008;
            overflow-wrap: anywhere;
        }

        .notification.unread {
            border-left: 5px solid #2563eb;
            background: #fafdff;
        }

        .notification h3 {
            margin: 0 0 10px;
            font-size: 17px;
        }

        .notification p {
            line-height: 1.6;
        }

        .meta {
            color: #64748b;
            font-size: 13px;
            margin: 10px 0;
        }

        .button {
            display: inline-block;
            padding: 10px 14px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 7px;
            font-size: 14px;
            cursor: pointer;
        }

        .button:hover {
            background: #173b70;
        }

        .button.light {
            background: #dbeafe;
            color: #173b70;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .empty, .error, .success {
            padding: 20px;
            border-radius: 10px;
            margin: 18px 0;
        }

        .empty {
            background: white;
            color: #64748b;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        form {
            display: inline;
        }

        @media (max-width: 520px) {
            .notification {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>StudentConnect</h1>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="projects.php">Projects</a>
        <a href="my_projects.php">My Projects</a>
    </nav>
</header>

<main>

    <div class="top-row">
        <h2>Notifications</h2>

        <span class="count">
            <?= $unreadCount ?> unread
        </span>
    </div>

    <p class="muted">
        Stay informed about discussion messages and project team changes.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= escapeHTML($error) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="success">
            Your notification settings have been updated.
        </div>
    <?php endif; ?>

    <?php if ($unreadCount > 0): ?>
        <form method="POST" action="notifications.php">
            <input type="hidden" name="csrf_token"
                   value="<?= escapeHTML($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="mark_all">

            <button class="button" type="submit">
                Mark All as Read
            </button>
        </form>
    <?php endif; ?>

    <?php if (empty($notifications) && $error === ''): ?>

        <section class="empty">
            <h3>You're all caught up!</h3>
            <p>New project and team updates will appear here.</p>
        </section>

    <?php else: ?>

        <?php foreach ($notifications as $notification): ?>

            <article class="notification <?= (int)$notification['is_read'] === 0 ? 'unread' : '' ?>">

                <h3>
                    <?= (int)$notification['is_read'] === 0 ? '● ' : '' ?>
                    <?= escapeHTML($notification['message']) ?>
                </h3>

                <p class="muted">
                    Project:
                    <?= escapeHTML($notification['project_title']) ?>
                </p>

                <div class="meta">
                    <?= escapeHTML($notification['created_at']) ?>
                    ·
                    <?= (int)$notification['is_read'] === 0
                        ? 'Unread'
                        : 'Read' ?>
                </div>

                <div class="actions">

                    <a class="button light"
                       href="project_details.php?id=<?= (int)$notification['project_id'] ?>">
                        View Project
                    </a>

                    <?php if ((int)$notification['is_read'] === 0): ?>
                        <form method="POST" action="notifications.php">
                            <input type="hidden" name="csrf_token"
                                   value="<?= escapeHTML($_SESSION['csrf_token']) ?>">

                            <input type="hidden" name="action"
                                   value="mark_one">

                            <input type="hidden" name="notification_id"
                                   value="<?= (int)$notification['id'] ?>">

                            <button class="button" type="submit">
                                Mark as Read
                            </button>
                        </form>
                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
