
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];

function team_h($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function team_redirect($projectId, $message, $type = 'error')
{
    $_SESSION['team_members_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: manage_project_members.php?id=' . (int) $projectId);
    exit;
}

$projectId = filter_var(
    $_GET['id'] ?? $_POST['project_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$projectId || $projectId < 1) {
    http_response_code(400);
    exit('Invalid project ID.');
}

// Process Add Member and Remove Member.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($submittedToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        team_redirect($projectId, 'Security verification failed. Please try again.');
    }

    if ((int) ($_POST['project_id'] ?? 0) !== (int) $projectId) {
        team_redirect($projectId, 'Invalid project information.');
    }

    $action = $_POST['action'] ?? '';
    $memberId = filter_var(
        $_POST['member_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    try {
        $pdo->beginTransaction();

        // Lock project and verify owner permission.
        $stmt = $pdo->prepare(
            'SELECT id, title, owner_id, max_members, status
             FROM projects
             WHERE id = :id
             FOR UPDATE'
        );

        $stmt->execute(['id' => $projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            $pdo->rollBack();
            team_redirect($projectId, 'Project not found.');
        }

        if ((int) $project['owner_id'] !== $userId) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Only the project owner can manage team members.');
        }

        if ($action === 'add_member') {

            if (!$memberId || $memberId < 1) {
                $pdo->rollBack();
                team_redirect($projectId, 'Please select a valid student.');
            }

            if ($memberId === (int) $project['owner_id']) {
                $pdo->rollBack();
                team_redirect($projectId, 'The project owner is already in the team.');
            }

            if ($project['status'] === 'Completed') {
                $pdo->rollBack();
                team_redirect($projectId, 'You cannot add members to a completed project.');
            }

            // Verify student account exists.
            $studentStmt = $pdo->prepare(
                'SELECT id FROM users WHERE id = :id'
            );
            $studentStmt->execute(['id' => $memberId]);

            if (!$studentStmt->fetch()) {
                $pdo->rollBack();
                team_redirect($projectId, 'Student account not found.');
            }

            // Check for duplicate membership.
            $existingStmt = $pdo->prepare(
                'SELECT 1
                 FROM project_members
                 WHERE project_id = :project_id
                   AND user_id = :user_id'
            );
            $existingStmt->execute([
                'project_id' => $projectId,
                'user_id' => $memberId
            ]);

            if ($existingStmt->fetch()) {
                $pdo->rollBack();
                team_redirect($projectId, 'This student is already in the team.');
            }

            // Count existing team memberships, including the owner.
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM project_members
                 WHERE project_id = :project_id'
            );
            $countStmt->execute(['project_id' => $projectId]);
            $memberCount = (int) $countStmt->fetchColumn();

            if ($memberCount >= (int) $project['max_members']) {
                $pdo->rollBack();
                team_redirect($projectId, 'The project team is already full.');
            }

            $insertStmt = $pdo->prepare(
                "INSERT INTO project_members (project_id, user_id, role)
                 VALUES (:project_id, :user_id, 'Member')"
            );
            $insertStmt->execute([
                'project_id' => $projectId,
                'user_id' => $memberId
            ]);
// Notify the student who was added.
$notifyStmt = $pdo->prepare(
    'INSERT INTO notifications (user_id, message)
     VALUES (:user_id, :message)'
);

$notifyStmt->execute([
    'user_id' => $memberId,
    'message' => 'You have been added to project: '
        . $project['title']
]);
            $pdo->commit();
            team_redirect(
                $projectId,
                'Student added to the project team successfully.',
                'success'
            );

        } elseif ($action === 'remove_member') {

            if (!$memberId || $memberId < 1) {
                $pdo->rollBack();
                team_redirect($projectId, 'Invalid team member.');
            }

            if ($memberId === (int) $project['owner_id']) {
                $pdo->rollBack();
                team_redirect($projectId, 'The project owner cannot be removed.');
            }

            // Remove membership only. The student's account is not deleted.
            $deleteStmt = $pdo->prepare(
                "DELETE FROM project_members
                 WHERE project_id = :project_id
                   AND user_id = :user_id
                   AND role = 'Member'"
            );

            $deleteStmt->execute([
                'project_id' => $projectId,
                'user_id' => $memberId
            ]);

            if ($deleteStmt->rowCount() !== 1) {
                $pdo->rollBack();
                team_redirect($projectId, 'This student is not a removable team member.');
            }
// Notify the student who was removed.
$notifyStmt = $pdo->prepare(
    'INSERT INTO notifications (user_id, message)
     VALUES (:user_id, :message)'
);

$notifyStmt->execute([
    'user_id' => $memberId,
    'message' => 'You have been removed from project: '
        . $project['title']
]);
            $pdo->commit();
            team_redirect(
                $projectId,
                'Student removed from the project team successfully.',
                'success'
            );

        } else {
            $pdo->rollBack();
            team_redirect($projectId, 'Invalid operation.');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Project member management error: ' . $e->getMessage());
        team_redirect($projectId, 'Unable to update the team. Please try again.');
    }
}

// Load project and verify that the current user owns it.
$stmt = $pdo->prepare(
    'SELECT id, title, owner_id, max_members, status
     FROM projects
     WHERE id = :id'
);
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    http_response_code(404);
    exit('Project not found.');
}

if ((int) $project['owner_id'] !== $userId) {
    http_response_code(403);
    exit('Only the project owner can manage team members.');
}

// Current team members.
$membersStmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.course, u.skills, pm.role
     FROM project_members pm
     INNER JOIN users u ON u.id = pm.user_id
     WHERE pm.project_id = :project_id
     ORDER BY
        CASE WHEN pm.role = "Owner" THEN 0 ELSE 1 END,
        u.full_name'
);
$membersStmt->execute(['project_id' => $projectId]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

$memberCount = count($members);

// Registered students who have not joined this project.
$candidatesStmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.course, u.skills
     FROM users u
     LEFT JOIN project_members pm
       ON pm.user_id = u.id
      AND pm.project_id = :project_id
     WHERE pm.user_id IS NULL
       AND u.id <> :owner_id
     ORDER BY u.full_name'
);
$candidatesStmt->execute([
    'project_id' => $projectId,
    'owner_id' => (int) $project['owner_id']
]);
$candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);

$canAdd =
    $project['status'] !== 'Completed'
    && $memberCount < (int) $project['max_members']
    && count($candidates) > 0;

$flash = $_SESSION['team_members_flash'] ?? null;
unset($_SESSION['team_members_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Team | StudentConnect</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        header {
            background: #173b69;
            color: white;
            padding: 18px 7%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        header h2 { margin: 0; }
        header a { color: white; margin-left: 14px; text-decoration: none; }

        main {
            max-width: 950px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .muted { color: #64748b; line-height: 1.6; }

        .notice {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        .member {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .member:last-child { border-bottom: none; }
        .member h3 { margin: 0 0 7px; }
        .member p { margin: 5px 0; color: #64748b; overflow-wrap: anywhere; }

        label {
            display: block;
            font-weight: bold;
            margin: 14px 0 7px;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: white;
        }

        button, .button {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            padding: 11px 15px;
            margin-top: 12px;
            cursor: pointer;
            text-decoration: none;
        }

        .add-button { background: #2563eb; color: white; }
        .remove-button { background: #b91c1c; color: white; margin-top: 0; }
        .back-button { background: #475569; color: white; }

        button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .role {
            display: inline-block;
            background: #dbeafe;
            color: #1d4ed8;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        @media (max-width: 600px) {
            header { padding: 18px 5%; }
            main { margin-top: 20px; padding: 0 14px; }
            .member { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>

<body>
<header>
    <h2>StudentConnect</h2>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="projects.php">Explore Projects</a>
        <a href="my_projects.php">My Projects</a>
    </nav>
</header>

<main>
    <h1>Manage Project Team</h1>

    <p class="muted">
        Project: <strong><?= team_h($project['title']) ?></strong><br>
        Team size: <?= $memberCount ?> / <?= (int) $project['max_members'] ?><br>
        Status: <?= team_h($project['status']) ?>
    </p>

    <?php if ($flash): ?>
        <div
            class="notice <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"
            role="status"
        >
            <?= team_h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Current Team Members</h2>

        <?php if (!$members): ?>
            <p class="muted">No team members found.</p>
        <?php endif; ?>

        <?php foreach ($members as $member): ?>
            <div class="member">
                <div>
                    <h3><?= team_h($member['full_name']) ?></h3>
                    <span class="role"><?= team_h($member['role']) ?></span>

                    <p>Course: <?= team_h($member['course'] ?: 'Not specified') ?></p>
                    <p>Skills: <?= team_h($member['skills'] ?: 'Not specified') ?></p>
                </div>

                <?php if ($member['role'] !== 'Owner'): ?>
                    <form
                        method="POST"
                        action="manage_project_members.php?id=<?= (int) $projectId ?>"
                        onsubmit="return confirm('Remove this student from the project group?');"
                    >
                        <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">
                        <input type="hidden" name="csrf_token" value="<?= team_h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="remove_member">
                        <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">

                        <button class="remove-button" type="submit">
                            Remove Member
                        </button>
                    </form>
                <?php else: ?>
                    <span class="muted">Project Owner</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
    <h2>Add a Student</h2>

    <p class="muted">
        Select a registered student who is not already in this project.
    </p>

    <p>
        Students available to add:
        <?= count($candidates) ?>
    </p>

    <?php if (empty($candidates)): ?>

        <p class="muted">
            No eligible students are available.
            Register another student account and try again.
        </p>

    <?php elseif ($project['status'] === 'Completed'): ?>

        <p class="muted">
            This project is completed. You cannot add new members.
        </p>

    <?php elseif ($memberCount >= (int) $project['max_members']): ?>

        <p class="muted">
            The team is full. Increase the member limit in Edit Project.
        </p>

    <?php else: ?>

        <form
            method="POST"
            action="manage_project_members.php?id=<?= (int) $projectId ?>"
        >
            <input
                type="hidden"
                name="project_id"
                value="<?= (int) $projectId ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= team_h($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_member"
            >

            <label for="member_id">Choose Student</label>

            <select id="member_id" name="member_id" required>
                <option value="">-- Select a student --</option>

                <?php foreach ($candidates as $candidate): ?>
                    <option value="<?= (int) $candidate['id'] ?>">
                        <?= team_h($candidate['full_name']) ?>
                        <?= !empty($candidate['course'])
                            ? ' — ' . team_h($candidate['course'])
                            : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="add-button">
                Add to Project
            </button>
        </form>

    <?php endif; ?>
</section>

    <a class="button back-button"
       href="project_details.php?id=<?= (int) $projectId ?>">
        Back to Project Details
    </a>
</main>
</body>
</html>
