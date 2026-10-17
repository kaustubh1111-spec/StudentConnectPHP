
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

$studentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$studentId) {
    http_response_code(400);
    exit('Invalid student profile ID.');
}

$stmt = $pdo->prepare(
    'SELECT id, full_name, email, course, skills, bio, created_at
     FROM users
     WHERE id = :id
     LIMIT 1'
);

$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Student profile not found.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile | StudentConnect</title>

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
            padding: 22px 5%;
            color: white;
        }

        header h1 { margin: 0; }

        main {
            width: 92%;
            max-width: 700px;
            margin: 40px auto;
        }

        .profile {
            padding: 30px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 5px 22px #0000000d;
        }

        .avatar {
            width: 85px;
            height: 85px;
            background: #dbeafe;
            color: #173b70;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
        }

        h2 { margin-bottom: 6px; }

        .subtitle {
            color: #64748b;
            margin-top: 0;
        }

        .detail {
            padding: 15px 0;
            border-bottom: 1px solid #e2e8f0;
            overflow-wrap: anywhere;
        }

        .detail strong {
            display: block;
            margin-bottom: 6px;
            color: #475569;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        .button {
            display: inline-block;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 7px;
            padding: 12px 16px;
        }

        .button.secondary {
            color: #173b70;
            background: #dbeafe;
        }
    </style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
</header>

<main>
    <section class="profile">
        <div class="avatar">
            <?= escapeHTML(strtoupper(substr($student['full_name'], 0, 1))) ?>
        </div>

        <h2><?= escapeHTML($student['full_name']) ?></h2>
        <p class="subtitle">Student Profile</p>

        <div class="detail">
            <strong>Course / Department</strong>
            <?= escapeHTML($student['course'] ?: 'Not provided') ?>
        </div>

        <div class="detail">
            <strong>Skills</strong>
            <?= escapeHTML($student['skills'] ?: 'Not provided') ?>
        </div>

        <div class="detail">
            <strong>About</strong>
            <?= escapeHTML($student['bio'] ?: 'This student has not added a bio yet.') ?>
        </div>

        <div class="detail">
            <strong>Member Since</strong>
            <?= escapeHTML($student['created_at']) ?>
        </div>

        <div class="actions">
            <a class="button" href="find_teammates.php">Find Teammates</a>
            <a class="button secondary" href="dashboard.php">My Dashboard</a>
        </div>
    </section>
</main>
</body>
</html>
