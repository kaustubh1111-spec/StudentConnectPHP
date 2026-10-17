
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

$idValue = $_GET['id'] ?? null;

$projectId = is_string($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ])
    : false;

if (!$projectId) {
    http_response_code(400);
    exit('Invalid project ID.');
}

try {
    // Find the project.
    $stmt = $pdo->prepare(
        'SELECT p.id, p.title, p.status, p.owner_id
         FROM projects p
         WHERE p.id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        http_response_code(404);
        exit('Project not found.');
    }

    // Verify that the current student belongs to this project.
    $memberStmt = $pdo->prepare(
        'SELECT role
         FROM project_members
         WHERE project_id = :project_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $memberStmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId
    ]);

    $membership = $memberStmt->fetch();

    if (!$membership) {
        http_response_code(403);
        exit('Only project team members can access this discussion.');
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load the project discussion.');
}

$errors = [];
$messageText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $messageText = is_string($_POST['message'] ?? null)
        ? trim($_POST['message'])
        : '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Your form session expired. Refresh and try again.';
    }

    $messageLength = function_exists('mb_strlen')
        ? mb_strlen($messageText, 'UTF-8')
        : strlen($messageText);

    if ($messageText === '') {
        $errors[] = 'Write a message before posting.';
    } elseif ($messageLength > 2000) {
        $errors[] = 'Your message must not exceed 2000 characters.';
    }

    
if (empty($errors)) {
    try {
        $pdo->beginTransaction();

        // Save the new discussion message.
        $insertStmt = $pdo->prepare(
            'INSERT INTO project_messages
                (project_id, user_id, message)
             VALUES
                (:project_id, :user_id, :message)'
        );

        $insertStmt->execute([
            'project_id' => $projectId,
            'user_id' => $userId,
            'message' => $messageText
        ]);

        // Get the sender's current name.
        $nameStmt = $pdo->prepare(
            'SELECT full_name FROM users WHERE id = :id'
        );
        $nameStmt->execute(['id' => $userId]);

        $senderName = $nameStmt->fetchColumn();

        if (!$senderName) {
            $senderName = 'A teammate';
        }

        $notificationText =
            $senderName . ' posted a new discussion message.';

        // Notify the other members of this project.
        $recipientsStmt = $pdo->prepare(
            'SELECT user_id
             FROM project_members
             WHERE project_id = :project_id
               AND user_id <> :sender_id'
        );

        $recipientsStmt->execute([
            'project_id' => $projectId,
            'sender_id' => $userId
        ]);

        $recipientIds = $recipientsStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifyStmt = $pdo->prepare(
            'INSERT INTO notifications
                (recipient_id, project_id, actor_id, type, message)
             VALUES
                (:recipient_id, :project_id, :actor_id, :type, :message)'
        );

        foreach ($recipientIds as $recipientId) {
            $notifyStmt->execute([
                'recipient_id' => (int)$recipientId,
                'project_id' => $projectId,
                'actor_id' => $userId,
                'type' => 'message',
                'message' => $notificationText
            ]);
        }

        $pdo->commit();

        header(
            'Location: project_discussion.php?id=' .
            (int)$projectId . '&sent=1'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($e->getMessage());
        $errors[] = 'Unable to post your message. Please try again.';
    }
}

}

// Load the latest 100 messages.
try {
    $messagesStmt = $pdo->prepare(
        'SELECT
            pm.id,
            pm.message,
            pm.created_at,
            u.id AS sender_id,
            u.full_name AS sender_name
         FROM project_messages pm
         JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = :project_id
         ORDER BY pm.id DESC
         LIMIT 100'
    );

    $messagesStmt->execute(['project_id' => $projectId]);

    // Display older messages first among the latest 100.
    $messages = array_reverse($messagesStmt->fetchAll());

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load messages.');
}

$sent = isset($_GET['sent'])
    && is_string($_GET['sent'])
    && $_GET['sent'] === '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Team Discussion | StudentConnect</title>

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
            gap: 15px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
        }

        nav a:hover {
            text-decoration: underline;
        }

        main {
            width: 92%;
            max-width: 900px;
            margin: 32px auto;
        }

        .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 4px 18px #0000000d;
        }

        h2 {
            margin-top: 0;
        }

        .muted {
            color: #64748b;
            line-height: 1.6;
        }

        .message-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 16px;
            border-radius: 9px;
            overflow-wrap: anywhere;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .message-header strong {
            color: #173b70;
        }

        .message-date {
            font-size: 12px;
            color: #64748b;
        }

        .message-text {
            white-space: pre-wrap;
            line-height: 1.65;
            margin: 0;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        textarea {
            width: 100%;
            min-height: 115px;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
            resize: vertical;
        }

        textarea:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        button, .button {
            display: inline-block;
            margin-top: 14px;
            padding: 12px 17px;
            background: #2563eb;
            color: white;
            border: 0;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        button:hover, .button:hover {
            background: #173b70;
        }

        .success, .error {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            color: #64748b;
        }

        .hint {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 0;
        }

        @media (max-width: 520px) {
            .card {
                padding: 18px;
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

    <section class="card">
        <h2>Team Discussion</h2>

        <h3><?= escapeHTML($project['title']) ?></h3>

        <p class="muted">
            Share ideas, coordinate tasks, and discuss your project with
            your teammates.
        </p>

        <a class="button"
           href="project_details.php?id=<?= (int)$projectId ?>">
            Back to Project
        </a>
    </section>

    <?php if ($sent): ?>
        <div class="success">
            Your message was posted successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <strong>Unable to post your message:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeHTML($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2>Post a Message</h2>

        <form method="POST"
              action="project_discussion.php?id=<?= (int)$projectId ?>">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHTML($_SESSION['csrf_token']) ?>"
            >

            <label for="message">Your message</label>

            <textarea
                id="message"
                name="message"
                maxlength="2000"
                placeholder="Write an update or ask your teammates a question..."
                required
            ><?= escapeHTML($messageText) ?></textarea>

            <p class="hint">Maximum 2000 characters.</p>

            <button type="submit">Post Message</button>
        </form>
    </section>

    <section class="card">
        <h2>Recent Discussion</h2>

        <?php if (empty($messages)): ?>
            <div class="empty">
                No messages yet. Start the discussion with your team!
            </div>
        <?php else: ?>
            <div class="message-list">
                <?php foreach ($messages as $msg): ?>
                    <article class="message">

                        <div class="message-header">
                            <strong>
                                <?= escapeHTML($msg['sender_name']) ?>
                            </strong>

                            <span class="message-date">
                                <?= escapeHTML($msg['created_at']) ?>
                            </span>
                        </div>

                        <p class="message-text"><?= escapeHTML($msg['message']) ?></p>

                        <a class="muted"
                           href="student_profile.php?id=<?= (int)$msg['sender_id'] ?>">
                            View student profile
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

</body>
</html>
