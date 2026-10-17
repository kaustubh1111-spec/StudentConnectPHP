<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Only logged-in users can manage a project.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];

function h($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function teamRedirect($projectId, $type, $message)
{
    $_SESSION['team_notice'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: manage_project.php?id=' . (int) $projectId);
    exit;
}

// Read and validate project ID.
$projectId = filter_var(
    $_GET['id'] ?? $_POST['project_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$projectId || $projectId < 1) {
    http_response_code(400);
    exit('Invalid project ID.');
}

// Handle Add Member and Remove Member forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !is_string($submittedToken) ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        teamRedirect(
            $projectId,
            'error',
            'Security verification failed. Please try again.'
        );
    }

    $postedProjectId = filter_var(
        $_POST['project_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ((int) $postedProjectId !== (int) $projectId) {
        teamRedirect($projectId, 'error', 'Invalid project information.');
    }

    $action = $_POST['action'] ?? '';
    $memberId = filter_var(
        $_POST['member_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    try {
        $pdo->beginTransaction();

        // Lock the project while checking permissions and capacity.
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

            teamRedirect(
                $projectId,
                'error',
                'This project no longer exists.'
            );
        }

        // Only the owner may add or remove members.
        if ((int) $project['owner_id'] !== $userId) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Only the project owner can manage team members.');
        }

        if ($action === 'add_member') {

            if (!$memberId || $memberId < 1) {
                $pdo->rollBack();
                teamRedirect($projectId, 'error', 'Please select a student.');
            }

            if ($memberId === (int) $project['owner_id']) {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'The project owner is already part of the team.'
                );
            }

            if ($project['status'] === 'Completed') {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'Members cannot be added to a completed project.'
                );
            }

            // Check that the selected account exists.
            $studentStmt = $pdo->prepare(
                'SELECT id FROM users WHERE id = :id'
            );

            $studentStmt->execute(['id' => $memberId]);

            if (!$studentStmt->fetch()) {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'The selected student account was not found.'
                );
            }

            // Prevent adding the same student twice.
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
                teamRedirect(
                    $projectId,
                    'error',
                    'This student is already a project member.'
                );
            }

            // Count the owner and all current members.
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM project_members
                 WHERE project_id = :project_id'
            );

            $countStmt->execute(['project_id' => $projectId]);
            $memberCount = (int) $countStmt->fetchColumn();

            if ($memberCount >= (int) $project['max_members']) {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'The project has reached its maximum team size.'
                );
            }

            // Add the selected student to the project group.
            $insertStmt = $pdo->prepare(
                "INSERT INTO project_members
                    (project_id, user_id, role)
                 VALUES
                    (:project_id, :user_id, 'Member')"
            );

            $insertStmt->execute([
                'project_id' => $projectId,
                'user_id' => $memberId
            ]);

            $pdo->commit();

            teamRedirect(
                $projectId,
                'success',
                'Student added to the project team successfully.'
            );

        } elseif ($action === 'remove_member') {

            if (!$memberId || $memberId < 1) {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'Invalid team member selected.'
                );
            }

            // The owner cannot remove themselves with this action.
            if ($memberId === (int) $project['owner_id']) {
                $pdo->rollBack();
                teamRedirect(
                    $projectId,
                    'error',
                    'The project owner cannot be removed.'
                );
            }

            // Remove membership only; do not delete the student's account.
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
                teamRedirect(
                    $projectId,
                    'error',
                    'That student is not a removable member of this project.'
                );
            }

            $pdo->commit();

            teamRedirect(
                $projectId,
                'success',
                'Student removed from the project team successfully.'
            );

        } else {
            $pdo->rollBack();
            teamRedirect($projectId, 'error', 'Invalid action.');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Manage project team error: ' . $e->getMessage());

        teamRedirect(
            $projectId,
            'error',
            'The team could not be updated. Please try again.'
        );
    }
}

// Load project details.
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

// Keep the management page owner-only.
if ((int) $project['owner_id'] !== $userId) {
    http_response_code(403);
    exit('Only the project owner can manage team members.');
}

// Load current project members.
$membersStmt = $pdo->prepare(
    'SELECT
        u.id,
        u.full_name,
        u.course,
        u.skills,
        pm.role,
        pm.joined_at
     FROM project_members pm
     INNER JOIN users u ON u.id = pm.user_id
     WHERE pm.project_id = :project_id
     ORDER BY
        CASE WHEN pm.role = "Owner" THEN 0 ELSE 1 END,
        u.full_name ASC'
);

$membersStmt->execute(['project_id' => $projectId]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

$memberCount = count($members);
$maxMembers = (int) $project['max_members'];

// Load registered students who are not already in this project.
$candidatesStmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.course, u.skills
     FROM users u
     LEFT JOIN project_members pm
       ON pm.user_id = u.id
      AND pm.project_id = :project_id
     WHERE pm.user_id IS NULL
       AND u.id <> :owner_id
     ORDER BY u.full_name ASC'
);

$candidatesStmt->execute([
    'project_id' => $projectId,
    'owner_id' => (int) $project['owner_id']
]);

$candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);

$canAddMembers =
    $project['status'] !== 'Completed'
    && $memberCount < $maxMembers
    && !empty($candidates);

$notice = $_SESSION['team_notice'] ?? null;
unset($_SESSION['team_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Team | StudentConnect</title>

    <style>
        * {
            box-sizing: border-box;
        }

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

        header h2 {
            margin: 0;
        }

        header a {
            color: white;
            text-decoration: none;
            margin-left: 14px;
        }

        main {
            max-width: 950px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .muted {
            color: #64748b;
            line-height: 1.6;
        }

        .notice {
            padding: 13px 16px;
            border-radius: 8px;
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

        .member {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 17px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .member:last-child {
            border-bottom: none;
        }

        .member h3 {
            margin: 0 0 6px;
        }

        .member p {
            margin: 4px 0;
            color: #64748b;
            overflow-wrap: anywhere;
        }

        label {
            display: block;
            margin: 14px 0 7px;
            font-weight: bold;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
            background: white;
        }

        button,
        .button {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            padding: 11px 15px;
            margin-top: 12px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .add-button {
            background: #2563eb;
            color: white;
        }

        .remove-button {
            background: #b91c1c;
            color: white;
            margin-top: 0;
        }

        .back-button {
            background: #475569;
            color: white;
        }

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

        .empty {
            padding: 18px 0;
            color: #64748b;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            main {
                margin-top: 20px;
                padding: 0 14px;
            }

            .member {
                align-items: flex-start;
                flex-direction: column;
            }
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
        Project:
        <strong><?= h($project['title']) ?></strong>
    </p>

    <?php if ($notice): ?>
        <div
            class="notice <?= $notice['type'] === 'success' ? 'success' : 'error' ?>"
            role="status"
        >
            <?= h($notice['message']) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Team Members</h2>

        <p class="muted">
            Team size: <?= $memberCount ?> / <?= $maxMembers ?>
            &nbsp; | &nbsp;
            Project status: <?= h($project['status']) ?>
        </p>

        <?php if (empty($members)): ?>
            <p class="empty">No team members were found for this project.</p>
        <?php else: ?>

            <?php foreach ($members as $member): ?>
                <div class="member">

                    <div>
                        <h3><?= h($member['full_name']) ?></h3>

                        <span class="role">
                            <?= h($member['role']) ?>
                        </span>

                        <?php if (!empty($member['course'])): ?>
                            <p>Course: <?= h($member['course']) ?></p>
                        <?php endif; ?>

                        <p>
                            Skills:
                            <?= h($member['skills'] ?: 'Not specified') ?>
                        </p>
                    </div>

                    <div>
                        <?php if ($member['role'] !== 'Owner'): ?>
                            <form
                                method="POST"
                                action="manage_project.php?id=<?= (int) $projectId ?>"
                                onsubmit="return confirm('Remove this student from the project group?');"
                            >
                                <input
                                    type="hidden"
                                    name="project_id"
                                    value="<?= (int) $projectId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= h($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove_member"
                                >

                                <input
                                    type="hidden"
                                    name="member_id"
                                    value="<?= (int) $member['id'] ?>"
                                >

                                <button class="remove-button" type="submit">
                                    Remove Member
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Project Owner</span>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Add a Student</h2>

        <p class="muted">
            Select an existing registered student who is not already
            part of this project.
        </p>

        <?php if (empty($candidates)): ?>

            <p class="empty">
                No eligible students are available to add.
            </p>

        <?php else: ?>

            <form
                method="POST"
                action="manage_project.php?id=<?= (int) $projectId ?>"
            >
                <input
                    type="hidden"
                    name="project_id"
                    value="<?= (int) $projectId ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add_member"
                >

                <label for="member_id">Choose Student</label>

                <select
                    id="member_id"
                    name="member_id"
                    required
                >
                    <option value="">-- Select a student --</option>

                    <?php foreach ($candidates as $candidate): ?>
                        <option value="<?= (int) $candidate['id'] ?>">
                            <?= h($candidate['full_name']) ?>
                            <?php if (!empty($candidate['course'])): ?>
                                — <?= h($candidate['course']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button
                    type="submit"
                    class="add-button"
                    <?= !$canAddMembers ? 'disabled' : '' ?>
                >
                    Add to Project
                </button>
            </form>

            <?php if ($project['status'] === 'Completed'): ?>
                <p class="muted">
                    This project is completed, so no new members can be added.
                </p>
            <?php elseif ($memberCount >= $maxMembers): ?>
                <p class="muted">
                    The team is full. Increase the project member limit
                    using Edit Project before adding another student.
                </p>
            <?php endif; ?>

        <?php endif; ?>
    </section>

    <a
        class="button back-button"
        href="project_details.php?id=<?= (int) $projectId ?>"
    >
        Back to Project Details
    </a>

</main>

</body>
</html>