
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$title = '';
$description = '';
$requiredSkills = '';
$maxMembers = 4;

$success = isset($_GET['created']) && $_GET['created'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $requiredSkills = trim($_POST['required_skills'] ?? '');
    $maxMembersInput = $_POST['max_members'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Your form session expired. Refresh and try again.';
    }

    if ($title === '' || strlen($title) > 150) {
        $errors[] = 'Enter a project title (maximum 150 characters).';
    }

    if ($description === '' || strlen($description) > 10000) {
        $errors[] = 'Enter a description (maximum 10,000 characters).';
    }

    if (strlen($requiredSkills) > 500) {
        $errors[] = 'Required skills must not exceed 500 characters.';
    }

    $validatedMaxMembers = filter_var(
        $maxMembersInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 2, 'max_range' => 20]]
    );

    if ($validatedMaxMembers === false) {
        $errors[] = 'Choose a team size between 2 and 20 students.';
    } else {
        $maxMembers = $validatedMaxMembers;
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO projects
                    (owner_id, title, description, required_skills, max_members, status)
                 VALUES
                    (:owner_id, :title, :description, :required_skills, :max_members, 'Open')"
            );

            $stmt->execute([
                'owner_id' => (int)$_SESSION['user_id'],
                'title' => $title,
                'description' => $description,
                'required_skills' => $requiredSkills !== ''
                    ? $requiredSkills
                    : null,
                'max_members' => $maxMembers
            ]);

            $projectId = (int)$pdo->lastInsertId();

            $memberStmt = $pdo->prepare(
                "INSERT INTO project_members (project_id, user_id, role)
                 VALUES (:project_id, :user_id, 'Owner')"
            );

            $memberStmt->execute([
                'project_id' => $projectId,
                'user_id' => (int)$_SESSION['user_id']
            ]);

            $pdo->commit();

            header('Location: create_project.php?created=1');
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Unable to create the project. Check the database tables and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project | StudentConnect</title>

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
            gap: 12px;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        header a {
            color: white;
            text-decoration: none;
            margin-right: 14px;
        }

        main {
            width: 92%;
            max-width: 700px;
            margin: 35px auto;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 5px 22px #0000000d;
        }

        h2 { margin-top: 0; }

        .subtitle {
            color: #64748b;
            line-height: 1.6;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 18px 0 7px;
        }

        input, textarea, select {
            display: block;
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        input:focus, textarea:focus, select:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        button, .back-link {
            display: inline-block;
            margin-top: 22px;
            padding: 12px 18px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover, .back-link:hover {
            background: #173b70;
        }

        .error, .success {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .hint {
            color: #64748b;
            font-size: 13px;
            margin-top: 6px;
        }

        @media (max-width: 500px) {
            .form-card { padding: 20px; }
        }
    </style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="find_teammates.php">Find Teammates</a>
        <a href="create_project.php">Create Project</a>
    </nav>
</header>

<main>
    <section class="form-card">
        <h2>Create a New Project</h2>
        <p class="subtitle">
            Share your idea and invite other students to collaborate.
        </p>

        <?php if ($success): ?>
            <div class="success">
                Project created successfully! You are its owner.
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

        <form method="POST" action="create_project.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHTML($_SESSION['csrf_token']) ?>"
            >

            <label for="title">Project Title *</label>
            <input
                type="text"
                id="title"
                name="title"
                maxlength="150"
                value="<?= escapeHTML($title) ?>"
                placeholder="e.g. Student Project Collaboration Platform"
                required
            >

            <label for="description">Project Description *</label>
            <textarea
                id="description"
                name="description"
                maxlength="10000"
                placeholder="Describe the problem, your proposed solution and project goals..."
                required
            ><?= escapeHTML($description) ?></textarea>

            <label for="required_skills">Required Skills</label>
            <input
                type="text"
                id="required_skills"
                name="required_skills"
                maxlength="500"
                value="<?= escapeHTML($requiredSkills) ?>"
                placeholder="e.g. PHP, MySQL, HTML, CSS, JavaScript"
            >
            <p class="hint">Separate skills with commas.</p>

            <label for="max_members">Maximum Team Size (including you)</label>
            <select id="max_members" name="max_members" required>
                <?php for ($i = 2; $i <= 20; $i++): ?>
                    <option
                        value="<?= $i ?>"
                        <?= (int)$maxMembers === $i ? 'selected' : '' ?>
                    >
                        <?= $i ?> students
                    </option>
                <?php endfor; ?>
            </select>

            <button type="submit">Create Project</button>
        </form>

        <p>
            <a class="back-link" href="dashboard.php">Back to Dashboard</a>
        </p>
    </section>
</main>
</body>
</html>
