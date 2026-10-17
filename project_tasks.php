
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

// Validate project ID.
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
    // Load project and check team membership.
    $stmt = $pdo->prepare(
        'SELECT id, owner_id, title, status
         FROM projects
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        http_response_code(404);
        exit('Project not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT role
         FROM project_members
         WHERE project_id = :project_id
           AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId
    ]);
    $membership = $stmt->fetch();

    if (!$membership) {
        http_response_code(403);
        exit('Only project team members can access these tasks.');
    }

    // Load valid task assignees from this project team.
    $stmt = $pdo->prepare(
        'SELECT u.id, u.full_name
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = :project_id
         ORDER BY u.full_name'
    );
    $stmt->execute(['project_id' => $projectId]);
    $members = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load project information.');
}

$errors = [];
$title = '';
$description = '';
$assignedTo = '';
$dueDate = '';

// Handle creation or status updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Your form session expired. Refresh and try again.');
    }

    if ($action === 'create_task') {
        $title = is_string($_POST['title'] ?? null)
            ? trim($_POST['title'])
            : '';

        $description = is_string($_POST['description'] ?? null)
            ? trim($_POST['description'])
            : '';

        $assignedTo = is_string($_POST['assigned_to'] ?? null)
            ? $_POST['assigned_to']
            : '';

        $dueDate = is_string($_POST['due_date'] ?? null)
            ? $_POST['due_date']
            : '';

        if ($project['status'] === 'Completed') {
            $errors[] = 'New tasks cannot be created in a completed project.';
        }

        if ($title === '' || strlen($title) > 150) {
            $errors[] = 'Enter a task title of at most 150 characters.';
        }

        if (strlen($description) > 5000) {
            $errors[] = 'Description must not exceed 5000 characters.';
        }

        $validatedAssignee = null;

        if ($assignedTo !== '') {
            $validatedAssignee = filter_var(
                $assignedTo,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($validatedAssignee === false) {
                $errors[] = 'Select a valid team member.';
            } else {
                // An assignee must belong to this project.
                $check = $pdo->prepare(
                    'SELECT 1
                     FROM project_members
                     WHERE project_id = :project_id
                       AND user_id = :user_id
                     LIMIT 1'
                );

                $check->execute([
                    'project_id' => $projectId,
                    'user_id' => $validatedAssignee
                ]);

                if (!$check->fetchColumn()) {
                    $errors[] =
                        'The assigned student is not a member of this project.';
                }
            }
        }

        if ($dueDate !== '') {
            $dateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $dueDate
            ) {
                $errors[] = 'Enter a valid due date.';
            }
        }

        if (empty($errors)) {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO project_tasks
                        (project_id, title, description,
                         created_by, assigned_to, due_date)
                     VALUES
                        (:project_id, :title, :description,
                         :created_by, :assigned_to, :due_date)'
                );

                $insert->execute([
                    'project_id' => $projectId,
                    'title' => $title,
                    'description' => $description !== ''
                        ? $description : null,
                    'created_by' => $userId,
                    'assigned_to' => $validatedAssignee,
                    'due_date' => $dueDate !== '' ? $dueDate : null
                ]);

                header(
                    'Location: project_tasks.php?id=' .
                    (int)$projectId . '&created=1'
                );
                exit;

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Unable to create the task. Please try again.';
            }
        }

    } elseif ($action === 'update_status') {
        $taskValue = $_POST['task_id'] ?? null;
        $newStatus = $_POST['status'] ?? '';

        $taskId = is_string($taskValue)
            ? filter_var($taskValue, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1]
            ])
            : false;

        $allowedStatuses = ['To Do', 'In Progress', 'Completed'];

        if (!$taskId) {
            $errors[] = 'Invalid task ID.';
        }

        if (
            !is_string($newStatus) ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            $errors[] = 'Select a valid task status.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, created_by, assigned_to
                     FROM project_tasks
                     WHERE id = :task_id
                       AND project_id = :project_id
                     LIMIT 1'
                );

                $stmt->execute([
                    'task_id' => $taskId,
                    'project_id' => $projectId
                ]);

                $task = $stmt->fetch();

                if (!$task) {
                    $errors[] = 'Task not found.';
                } else {
                    $isCreator = (int)$task['created_by'] === $userId;
                    $isAssignee = $task['assigned_to'] !== null
                        && (int)$task['assigned_to'] === $userId;
                    $isOwner = (int)$project['owner_id'] === $userId;

                    if (!$isCreator && !$isAssignee && !$isOwner) {
                        http_response_code(403);
                        exit(
                            'Only the task creator, assignee, or project owner can update this task.'
                        );
                    }

                    $update = $pdo->prepare(
                        'UPDATE project_tasks
                         SET status = :status
                         WHERE id = :task_id
                           AND project_id = :project_id'
                    );

                    $update->execute([
                        'status' => $newStatus,
                        'task_id' => $taskId,
                        'project_id' => $projectId
                    ]);

                    header(
                        'Location: project_tasks.php?id=' .
                        (int)$projectId . '&updated=1'
                    );
                    exit;
                }

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Unable to update task status.';
            }
        }
    } else {
        $errors[] = 'Invalid action.';
    }
}

// Load tasks and names for display.
try {
    $stmt = $pdo->prepare(
        "SELECT
            t.id,
            t.title,
            t.description,
            t.status,
            t.due_date,
            t.created_at,
            t.created_by,
            t.assigned_to,
            creator.full_name AS creator_name,
            assignee.full_name AS assignee_name
         FROM project_tasks t
         JOIN users creator ON creator.id = t.created_by
         LEFT JOIN users assignee ON assignee.id = t.assigned_to
         WHERE t.project_id = :project_id
         ORDER BY
            CASE t.status
                WHEN 'To Do' THEN 0
                WHEN 'In Progress' THEN 1
                ELSE 2
            END,
            t.due_date IS NULL,
            t.due_date ASC,
            t.id DESC"
    );

    $stmt->execute(['project_id' => $projectId]);
    $tasks = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load tasks.');
}

$totalTasks = count($tasks);
$completedTasks = 0;
$inProgressTasks = 0;

foreach ($tasks as $task) {
    if ($task['status'] === 'Completed') {
        $completedTasks++;
    } elseif ($task['status'] === 'In Progress') {
        $inProgressTasks++;
    }
}

$created = isset($_GET['created']) && $_GET['created'] === '1';
$updated = isset($_GET['updated']) && $_GET['updated'] === '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Tasks | StudentConnect</title>

<style>
* { box-sizing: border-box; }

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

header h1 { margin: 0; font-size: 24px; }

nav {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

nav a {
    color: white;
    text-decoration: none;
}

main {
    max-width: 1050px;
    width: 92%;
    margin: 32px auto;
}

.card {
    background: white;
    padding: 24px;
    margin-bottom: 22px;
    border-radius: 12px;
    box-shadow: 0 4px 18px #0000000d;
    overflow-wrap: anywhere;
}

.muted {
    color: #64748b;
    line-height: 1.6;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}

.stat {
    background: white;
    padding: 18px;
    border-radius: 10px;
    box-shadow: 0 3px 14px #00000008;
}

.stat strong {
    display: block;
    margin-top: 7px;
    font-size: 26px;
    color: #173b70;
}

label {
    display: block;
    margin: 16px 0 7px;
    font-weight: bold;
}

input, textarea, select {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font: inherit;
}

textarea {
    min-height: 95px;
    resize: vertical;
}

button, .button {
    display: inline-block;
    margin-top: 16px;
    padding: 11px 16px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: white;
    text-decoration: none;
    font-size: 14px;
    cursor: pointer;
}

button:hover, .button:hover {
    background: #173b70;
}

.task {
    padding: 20px 0;
    border-bottom: 1px solid #e2e8f0;
}

.task:last-child { border-bottom: none; }

.task h3 { margin: 0 0 9px; }

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    background: #dbeafe;
    color: #173b70;
    font-size: 12px;
    font-weight: bold;
}

.badge.completed {
    background: #dcfce7;
    color: #166534;
}

.badge.progress {
    background: #fef3c7;
    color: #92400e;
}

.task-form {
    display: flex;
    align-items: end;
    gap: 10px;
    flex-wrap: wrap;
}

.task-form .field {
    flex: 1;
    min-width: 160px;
}

.task-form label { margin-top: 0; }

.notice {
    padding: 13px;
    border-radius: 7px;
    margin-bottom: 17px;
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
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    color: #64748b;
}

@media (max-width: 600px) {
    .stats { grid-template-columns: 1fr; }
    .card { padding: 18px; }
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
        <a href="notifications.php">Notifications</a>
    </nav>
</header>

<main>
    <section class="card">
        <h2>Team Tasks</h2>
        <h3><?= escapeHTML($project['title']) ?></h3>
        <p class="muted">
            Create tasks, assign responsibilities, and track team progress.
        </p>
        <a class="button"
           href="project_details.php?id=<?= (int)$projectId ?>">
            Back to Project
        </a>
    </section>

    <?php if ($created): ?>
        <div class="notice success">Task created successfully.</div>
    <?php endif; ?>

    <?php if ($updated): ?>
        <div class="notice success">Task status updated successfully.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="notice error">
            <strong>Please check the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeHTML($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            Total Tasks
            <strong><?= $totalTasks ?></strong>
        </div>
        <div class="stat">
            In Progress
            <strong><?= $inProgressTasks ?></strong>
        </div>
        <div class="stat">
            Completed
            <strong><?= $completedTasks ?></strong>
        </div>
    </section>

    <?php if ($project['status'] !== 'Completed'): ?>
        <section class="card">
            <h2>Create a Task</h2>

            <form method="POST"
                  action="project_tasks.php?id=<?= (int)$projectId ?>">

                <input type="hidden" name="csrf_token"
                       value="<?= escapeHTML($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_task">

                <label for="title">Task Title *</label>
                <input type="text" id="title" name="title"
                       maxlength="150" required
                       value="<?= escapeHTML($title) ?>"
                       placeholder="e.g. Design the login page">

                <label for="description">Description</label>
                <textarea id="description" name="description"
                          maxlength="5000"
                          placeholder="Explain what needs to be done..."><?= escapeHTML($description) ?></textarea>

                <label for="assigned_to">Assign To</label>
                <select id="assigned_to" name="assigned_to">
                    <option value="">Unassigned</option>

                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>"
                            <?= (string)$assignedTo === (string)$member['id']
                                ? 'selected' : '' ?>>
                            <?= escapeHTML($member['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="due_date">Due Date</label>
                <input type="date" id="due_date" name="due_date"
                       value="<?= escapeHTML($dueDate) ?>">

                <button type="submit">Create Task</button>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <p class="muted">
                This project is completed, so new tasks cannot be created.
            </p>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Project Tasks</h2>

        <?php if (empty($tasks)): ?>
            <div class="empty">
                No tasks have been created yet. Add the team's first task above.
            </div>
        <?php else: ?>

            <?php foreach ($tasks as $task): ?>
                <?php
                $isCreator = (int)$task['created_by'] === $userId;
                $isAssignee = $task['assigned_to'] !== null
                    && (int)$task['assigned_to'] === $userId;
                $isOwner = (int)$project['owner_id'] === $userId;

                $canUpdate = $isCreator || $isAssignee || $isOwner;

                $badgeClass = '';
                if ($task['status'] === 'Completed') {
                    $badgeClass = 'completed';
                } elseif ($task['status'] === 'In Progress') {
                    $badgeClass = 'progress';
                }
                ?>

                <article class="task">
                    <span class="badge <?= $badgeClass ?>">
                        <?= escapeHTML($task['status']) ?>
                    </span>

                    <h3><?= escapeHTML($task['title']) ?></h3>

                    <?php if (!empty($task['description'])): ?>
                        <p class="muted"
                           style="white-space:pre-wrap"><?= escapeHTML($task['description']) ?></p>
                    <?php endif; ?>

                    <p class="muted">
                        <strong>Assigned to:</strong>
                        <?= escapeHTML($task['assignee_name'] ?: 'Unassigned') ?>
                        <br>

                        <strong>Created by:</strong>
                        <?= escapeHTML($task['creator_name']) ?>
                        <br>

                        <strong>Due date:</strong>
                        <?= escapeHTML($task['due_date'] ?: 'Not set') ?>
                        <br>

                        <strong>Created:</strong>
                        <?= escapeHTML($task['created_at']) ?>
                    </p>

                    <?php if ($canUpdate): ?>
                        <form class="task-form"
                              method="POST"
                              action="project_tasks.php?id=<?= (int)$projectId ?>">

                            <input type="hidden" name="csrf_token"
                                   value="<?= escapeHTML($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action"
                                   value="update_status">
                            <input type="hidden" name="task_id"
                                   value="<?= (int)$task['id'] ?>">

                            <div class="field">
                                <label for="status_<?= (int)$task['id'] ?>">
                                    Update Status
                                </label>

                                <select id="status_<?= (int)$task['id'] ?>"
                                        name="status">
                                    <?php foreach (
                                        ['To Do', 'In Progress', 'Completed']
                                        as $statusOption
                                    ): ?>
                                        <option value="<?= escapeHTML($statusOption) ?>"
                                            <?= $task['status'] === $statusOption
                                                ? 'selected' : '' ?>>
                                            <?= escapeHTML($statusOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit">Save Status</button>
                        </form>
                    <?php endif; ?>
                </article>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>
</main>

</body>
</html>
