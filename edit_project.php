
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

$idValue = $_GET['id'] ?? '';

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
    $stmt = $pdo->prepare(
        "SELECT p.*,
            (SELECT COUNT(*)
             FROM project_members pm
             WHERE pm.project_id = p.id) AS member_count
         FROM projects p
         WHERE p.id = :id
         LIMIT 1"
    );

    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Unable to load the project.');
}

if (!$project) {
    http_response_code(404);
    exit('Project not found.');
}

// Only the actual project owner can edit it.
if ((int)$project['owner_id'] !== $userId) {
    http_response_code(403);
    exit('You are not authorized to edit this project.');
}

$title = $project['title'];
$description = $project['description'];
$requiredSkills = $project['required_skills'] ?? '';
$maxMembers = (string)$project['max_members'];
$status = $project['status'];

$errors = [];
$success = isset($_GET['saved']) && $_GET['saved'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    $title = is_string($_POST['title'] ?? null)
        ? trim($_POST['title'])
        : '';

    $description = is_string($_POST['description'] ?? null)
        ? trim($_POST['description'])
        : '';

    $requiredSkills = is_string($_POST['required_skills'] ?? null)
        ? trim($_POST['required_skills'])
        : '';

    $maxMembers = is_string($_POST['max_members'] ?? null)
        ? $_POST['max_members']
        : '';

    $status = is_string($_POST['status'] ?? null)
        ? $_POST['status']
        : '';

    // Validate the request.
    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Your form session expired. Refresh and try again.';
    }

    if ($title === '' || strlen($title) > 150) {
        $errors[] = 'Enter a title (maximum 150 characters).';
    }

    if ($description === '' || strlen($description) > 10000) {
        $errors[] = 'Enter a description (maximum 10,000 characters).';
    }

    if (strlen($requiredSkills) > 500) {
        $errors[] = 'Required skills must not exceed 500 characters.';
    }

    $validatedMaxMembers = filter_var(
        $maxMembers,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 2, 'max_range' => 20]]
    );

    if ($validatedMaxMembers === false) {
        $errors[] = 'Team size must be between 2 and 20 students.';
    }

    $allowedStatuses = ['Open', 'In Progress', 'Completed'];

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Select a valid project status.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock the project while checking its owner and team size.
            $lockStmt = $pdo->prepare(
                'SELECT id, owner_id
                 FROM projects
                 WHERE id = :id
                 FOR UPDATE'
            );

            $lockStmt->execute(['id' => $projectId]);
            $lockedProject = $lockStmt->fetch();

            if (
                !$lockedProject ||
                (int)$lockedProject['owner_id'] !== $userId
            ) {
                $pdo->rollBack();
                http_response_code(403);
                exit('You are not authorized to edit this project.');
            }

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM project_members
                 WHERE project_id = :project_id'
            );

            $countStmt->execute(['project_id' => $projectId]);
            $memberCount = (int)$countStmt->fetchColumn();

            if ($validatedMaxMembers < $memberCount) {
                $errors[] =
                    'Team capacity cannot be lower than the current number of members.';
            }

            if (
                $status === 'Open' &&
                $validatedMaxMembers <= $memberCount
            ) {
                $errors[] =
                    'An open project needs at least one available team place.';
            }

            if (!empty($errors)) {
                $pdo->rollBack();
            } else {
                $update = $pdo->prepare(
                    'UPDATE projects
                     SET title = :title,
                         description = :description,
                         required_skills = :required_skills,
                         max_members = :max_members,
                         status = :status
                     WHERE id = :id AND owner_id = :owner_id'
                );

                $update->execute([
                    'title' => $title,
                    'description' => $description,
                    'required_skills' =>
                        $requiredSkills !== '' ? $requiredSkills : null,
                    'max_members' => $validatedMaxMembers,
                    'status' => $status,
                    'id' => $projectId,
                    'owner_id' => $userId
                ]);

                $pdo->commit();

                header(
                    'Location: edit_project.php?id=' .
                    (int)$projectId . '&saved=1'
                );
                exit;
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Unable to update the project. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project | StudentConnect</title>

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
            padding: 20px 5%;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 720px;
            width: 92%;
            margin: 35px auto;
        }

        .card {
            background: white;
            padding: 28px;
            border-radius: 13px;
            box-shadow: 0 5px 22px #0000000d;
        }

        h2 {
            margin-top: 0;
        }

        .subtitle {
            color: #64748b;
            line-height: 1.6;
        }

        label {
            display: block;
            margin: 18px 0 7px;
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
            min-height: 150px;
            resize: vertical;
        }

        input:focus, textarea:focus, select:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        .hint {
            color: #64748b;
            font-size: 13px;
        }

        button, .back-link {
            display: inline-block;
            padding: 12px 17px;
            margin-top: 22px;
            border: 0;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover, .back-link:hover {
            background: #173b70;
        }

        .success, .error {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 7px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 500px) {
            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
    <a href="projects.php">← Explore Projects</a>
</header>

<main>
    <section class="card">
        <h2>Edit Project</h2>
        <p class="subtitle">
            Update the project information and manage its availability.
        </p>

        <?php if ($success): ?>
            <div class="success">
                Project details updated successfully!
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= escapeHTML($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="hint">
            Current team members:
            <?= (int)$project['member_count'] ?>
        </p>

        <form method="POST"
              action="edit_project.php?id=<?= (int)$projectId ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= escapeHTML($_SESSION['csrf_token']) ?>">

            <label for="title">Project Title *</label>
            <input type="text" id="title" name="title"
                   maxlength="150"
                   value="<?= escapeHTML($title) ?>" required>

            <label for="description">Project Description *</label>
            <textarea id="description" name="description"
                      maxlength="10000" required><?= escapeHTML($description) ?></textarea>

            <label for="required_skills">Required Skills</label>
            <input type="text" id="required_skills"
                   name="required_skills" maxlength="500"
                   value="<?= escapeHTML($requiredSkills) ?>"
                   placeholder="e.g. PHP, MySQL, JavaScript">

            <label for="max_members">Maximum Team Size</label>
            <select id="max_members" name="max_members" required>
                <?php for ($i = 2; $i <= 20; $i++): ?>
                    <option value="<?= $i ?>"
                        <?= (string)$i === (string)$maxMembers
                            ? 'selected'
                            : '' ?>>
                        <?= $i ?> students
                    </option>
                <?php endfor; ?>
            </select>

            <p class="hint">
                Team size includes the owner. It cannot be lower than
                the number of current members.
            </p>

            <label for="status">Project Status</label>
            <select id="status" name="status" required>
                <?php foreach (
                    ['Open', 'In Progress', 'Completed'] as $option
                ): ?>
                    <option value="<?= escapeHTML($option) ?>"
                        <?= $status === $option ? 'selected' : '' ?>>
                        <?= escapeHTML($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Save Changes</button>
        </form>

        <p>
            <a class="back-link"
               href="project_details.php?id=<?= (int)$projectId ?>">
                Back to Project
            </a>
        </p>
    </section>
</main>
</body>
</html>
