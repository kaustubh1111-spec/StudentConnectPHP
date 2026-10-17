
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
$errors = [];
$success = isset($_GET['saved']) && $_GET['saved'] === '1';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load the current student's profile.
$stmt = $pdo->prepare(
    'SELECT id, full_name, course, skills, bio
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Pre-fill the form with saved information.
$fullName = $user['full_name'];
$course = $user['course'] ?? '';
$skills = $user['skills'] ?? '';
$bio = $user['bio'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    $fullName = is_string($_POST['full_name'] ?? null)
        ? trim($_POST['full_name'])
        : '';

    $course = is_string($_POST['course'] ?? null)
        ? trim($_POST['course'])
        : '';

    $skills = is_string($_POST['skills'] ?? null)
        ? trim($_POST['skills'])
        : '';

    $bio = is_string($_POST['bio'] ?? null)
        ? trim($_POST['bio'])
        : '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Your form session expired. Refresh and try again.';
    }

    if ($fullName === '' || strlen($fullName) > 100) {
        $errors[] = 'Enter your name (maximum 100 characters).';
    }

    if (strlen($course) > 100) {
        $errors[] = 'Course must not exceed 100 characters.';
    }

    if (strlen($skills) > 500) {
        $errors[] = 'Skills must not exceed 500 characters.';
    }

    if (strlen($bio) > 3000) {
        $errors[] = 'Your bio must not exceed 3000 characters.';
    }

    if (empty($errors)) {
        try {
            $update = $pdo->prepare(
                'UPDATE users
                 SET full_name = :full_name,
                     course = :course,
                     skills = :skills,
                     bio = :bio
                 WHERE id = :id'
            );

            $update->execute([
                'full_name' => $fullName,
                'course' => $course !== '' ? $course : null,
                'skills' => $skills !== '' ? $skills : null,
                'bio' => $bio !== '' ? $bio : null,
                'id' => $userId
            ]);

            // Keep the dashboard's session name up to date.
            $_SESSION['user_name'] = $fullName;

            header('Location: edit_profile.php?saved=1');
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Unable to update your profile. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | StudentConnect</title>

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
            width: 92%;
            max-width: 650px;
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

        input, textarea {
            display: block;
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-family: inherit;
            font-size: 15px;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        input:focus, textarea:focus {
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
            margin-top: 20px;
            border: none;
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

        @media (max-width: 480px) {
            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>StudentConnect</h1>
    <a href="dashboard.php">← Dashboard</a>
</header>

<main>
    <section class="card">
        <h2>Edit Your Profile</h2>
        <p class="subtitle">
            Update your information so other students can discover your skills.
        </p>

        <?php if ($success): ?>
            <div class="success">
                Your profile has been updated successfully!
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

        <form method="POST" action="edit_profile.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHTML($_SESSION['csrf_token']) ?>"
            >

            <label for="full_name">Full Name *</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                maxlength="100"
                value="<?= escapeHTML($fullName) ?>"
                required
            >

            <label for="course">Course / Department</label>
            <input
                type="text"
                id="course"
                name="course"
                maxlength="100"
                placeholder="e.g. Third Year BTech CSE"
                value="<?= escapeHTML($course) ?>"
            >

            <label for="skills">Skills</label>
            <input
                type="text"
                id="skills"
                name="skills"
                maxlength="500"
                placeholder="e.g. PHP, MySQL, HTML, CSS"
                value="<?= escapeHTML($skills) ?>"
            >

            <p class="hint">Separate your skills with commas.</p>

            <label for="bio">About Me</label>
            <textarea
                id="bio"
                name="bio"
                maxlength="3000"
                placeholder="Tell other students about your interests and project goals..."
            ><?= escapeHTML($bio) ?></textarea>

            <button type="submit">Save Changes</button>
        </form>

        <p>
            <a class="back-link" href="dashboard.php">Back to Dashboard</a>
        </p>
    </section>
</main>

</body>
</html>
