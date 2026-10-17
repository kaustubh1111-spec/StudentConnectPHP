
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

// Create a CSRF token for the Join Project form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate the project ID from the URL.
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
    // Get project details and current team size.
    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.owner_id,
            p.title,
            p.description,
            p.required_skills,
            p.max_members,
            p.status,
            p.created_at,
            owner.full_name AS owner_name,
            (
                SELECT COUNT(*)
                FROM project_members pm
                WHERE pm.project_id = p.id
            ) AS member_count
         FROM projects p
         JOIN users owner ON owner.id = p.owner_id
         WHERE p.id = :id
         LIMIT 1"
    );

    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        http_response_code(404);
        exit('Project not found.');
    }

    // Get the project's team members.
    $memberStmt = $pdo->prepare(
        "SELECT
            u.id,
            u.full_name,
            u.course,
            u.skills,
            pm.role,
            pm.joined_at
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = :project_id
         ORDER BY
            CASE WHEN pm.role = 'Owner' THEN 0 ELSE 1 END,
            pm.joined_at ASC"
    );

    $memberStmt->execute(['project_id' => $projectId]);
    $members = $memberStmt->fetchAll();

    // Check whether the current student has joined.
    $myStmt = $pdo->prepare(
        "SELECT role
         FROM project_members
         WHERE project_id = :project_id
           AND user_id = :user_id
         LIMIT 1"
    );

    $myStmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId
    ]);

    $myMembership = $myStmt->fetch();

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load project details. Please try again.');
}

// Work out whether another student can join.
$memberCount = (int)$project['member_count'];
$maxMembers = (int)$project['max_members'];

$isOwner = (int)$project['owner_id'] === $userId;

$canJoin = $project['status'] === 'Open'
    && $memberCount < $maxMembers
    && !$myMembership;

$notice = '';
$noticeType = 'success';

if (
    isset($_GET['joined']) &&
    is_string($_GET['joined']) &&
    $_GET['joined'] === '1'
) {
    $notice = 'You have successfully joined this project.';
}

if (isset($_GET['join_error']) && is_string($_GET['join_error'])) {
    $noticeType = 'error';

    $messages = [
        'closed' => 'This project is not open for new members.',
        'full' => 'This project has reached its maximum team size.',
        'already_member' => 'You are already a member of this project.',
        'failed' => 'Unable to join the project. Please try again.'
    ];

    $notice = $messages[$_GET['join_error']]
        ?? 'The join request could not be completed.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Project Details | StudentConnect</title>

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
            flex-wrap: wrap;
            gap: 14px;
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
            max-width: 950px;
            width: 92%;
            margin: 35px auto;
        }

        .card {
            background: white;
            padding: 26px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 4px 18px #0000000d;
            overflow-wrap: anywhere;
        }

        .card h2:first-child {
            margin-top: 0;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            background: #dcfce7;
            color: #166534;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status.other {
            background: #e2e8f0;
            color: #334155;
        }

        .description {
            white-space: pre-wrap;
            line-height: 1.7;
        }

        .muted {
            color: #64748b;
            line-height: 1.6;
        }

        .button {
            display: inline-block;
            padding: 12px 17px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .button:hover {
            background: #173b70;
        }

        /* Edit Project button */
        .button.secondary {
            background: #dbeafe;
            color: #173b70;
        }

        .button.secondary:hover {
            background: #bfdbfe;
        }

        .notice {
            padding: 13px;
            margin-bottom: 20px;
            border-radius: 7px;
            background: #dcfce7;
            color: #166534;
        }

        .notice.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .member {
            padding: 16px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .member:last-child {
            border-bottom: none;
        }

        .member h3 {
            margin: 0 0 7px;
        }

        .member p {
            overflow-wrap: anywhere;
        }

        .member a {
            color: #2563eb;
            text-decoration: none;
        }

        .member a:hover {
            text-decoration: underline;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        @media (max-width: 520px) {
            .card {
                padding: 19px;
            }
        }
    </style>
</head>
<?php if ($isOwner): ?>

<div style="margin: 20px 0;">
    <a
        href="manage_project_members.php?id=<?= (int) $project['id'] ?>"
        style="display: inline-block; background: #2563eb; color: white;
               padding: 12px 18px; border-radius: 6px;
               text-decoration: none;"
    >
        Manage Team Members
    </a>
</div>

<?php endif; ?>
<body>

<header>
    <h1>StudentConnect</h1>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="find_teammates.php">Find Teammates</a>
        <a href="projects.php">Projects</a>
        <a href="create_project.php">Create Project</a>
        <a href="my_projects.php">My Projects</a>
    </nav>
</header>

<main>

    <?php if ($notice !== ''): ?>
        <div class="notice <?= $noticeType === 'error' ? 'error' : '' ?>">
            <?= escapeHTML($notice) ?>
        </div>
    <?php endif; ?>

    <!-- PROJECT DETAILS CARD -->
    <section class="card">

        <span class="status <?= $project['status'] === 'Open' ? '' : 'other' ?>">
            <?= escapeHTML($project['status']) ?>
        </span>

        <h2><?= escapeHTML($project['title']) ?></h2>

        <p class="muted">
            Created by <?= escapeHTML($project['owner_name']) ?>
            · <?= escapeHTML($project['created_at']) ?>
        </p>

        <h3>Project Description</h3>

        <div class="description"><?= escapeHTML($project['description']) ?></div>

        <h3>Required Skills</h3>

        <p>
            <?= escapeHTML($project['required_skills'] ?: 'Not specified') ?>
        </p>

        <h3>Team Capacity</h3>

        <p>
            <?= $memberCount ?> of <?= $maxMembers ?> students have joined.
        </p>

        <!-- JOIN PROJECT SECTION -->
        <?php if ($myMembership): ?>

            <p>
                <strong>
                    You are part of this project as
                    <?= escapeHTML($myMembership['role']) ?>.
                </strong>
            </p>

        <?php elseif ($canJoin): ?>

            <form action="join_project.php" method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeHTML($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="project_id"
                    value="<?= (int)$project['id'] ?>"
                >

                <button class="button" type="submit">
                    Join Project
                </button>
            </form>

        <?php elseif ($project['status'] !== 'Open'): ?>

            <p class="muted">
                This project is not accepting new members.
            </p>

        <?php else: ?>

            <p class="muted">
                This project has reached its maximum team capacity.
            </p>

        <?php endif; ?>

        <!-- EDIT PROJECT BUTTON: VISIBLE ONLY TO THE OWNER -->
        
<?php if ($isOwner): ?>
    <div class="actions">
        <a
            class="button secondary"
            href="edit_project.php?id=<?= (int)$project['id'] ?>"
        >
            Edit Project
        </a>

        <a
            class="button secondary"
            href="manage_project.php?id=<?= (int)$project['id'] ?>"
        >
            Manage Team
        </a>
        <form
    action="delete_project.php"
    method="POST"
    style="display: inline-block; margin: 8px 0;"
    onsubmit="return confirm('Are you sure you want to permanently delete this project? Its team memberships, tasks, and discussion messages will also be deleted. This action cannot be undone.');"
>
    <input
        type="hidden"
        name="project_id"
        value="<?= (int) $project['id'] ?>"
    >

    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars(
            $_SESSION['csrf_token'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >

    <button
        type="submit"
        style="background: #b91c1c; color: white; border: 0;
               padding: 10px 15px; border-radius: 6px; cursor: pointer;"
    >
        Delete Project
    </button>
</form>
    </div>
<?php endif; ?>



<?php if ($myMembership || $isOwner): ?>
    <div class="actions">

        <?php if ($myMembership): ?>
            <a
                class="button"
                href="project_discussion.php?id=<?= (int)$project['id'] ?>"
            >
                Team Discussion
            </a>
        <?php endif; ?>

        <a
            class="button"
            href="project_tasks.php?id=<?= (int)$project['id'] ?>"
        >
            Team Tasks
        </a>

    </div>
<?php endif; ?>


    </section>

    <!-- PROJECT TEAM CARD -->
    <section class="card">

        <h2>Project Team (<?= count($members) ?>)</h2>

        <?php if (empty($members)): ?>

            <p class="muted">
                No team members are listed yet.
            </p>

        <?php else: ?>

            <?php foreach ($members as $member): ?>

                <div class="member">

                    <h3><?= escapeHTML($member['full_name']) ?></h3>

                    <p class="muted">
                        <strong>Role:</strong>
                        <?= escapeHTML($member['role']) ?>

                        <?php if (!empty($member['course'])): ?>
                            · <?= escapeHTML($member['course']) ?>
                        <?php endif; ?>
                    </p>

                    <p>
                        <strong>Skills:</strong>
                        <?= escapeHTML($member['skills'] ?: 'Not provided') ?>
                    </p>

                    <a href="student_profile.php?id=<?= (int)$member['id'] ?>">
                        View Student Profile
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <p>
        <a href="projects.php">← Back to Projects</a>
    </p>

</main>

</body>
</html>
