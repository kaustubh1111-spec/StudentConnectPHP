
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

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

$search = substr($search, 0, 100);
$students = [];
$error = '';

try {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, course, skills, bio
             FROM users
             WHERE id <> :current_user
             AND (
                 full_name LIKE :name_search
                 OR course LIKE :course_search
                 OR skills LIKE :skills_search
             )
             ORDER BY full_name ASC
             LIMIT 100"
        );

        $like = '%' . $search . '%';

        $stmt->execute([
            'current_user' => (int)$_SESSION['user_id'],
            'name_search' => $like,
            'course_search' => $like,
            'skills_search' => $like
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, course, skills, bio
             FROM users
             WHERE id <> :current_user
             ORDER BY full_name ASC
             LIMIT 100"
        );

        $stmt->execute([
            'current_user' => (int)$_SESSION['user_id']
        ]);
    }

    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Unable to load students. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Teammates | StudentConnect</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #1e293b;
            background: #f3f6fc;
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
            margin-left: 14px;
        }

        main {
            width: 92%;
            max-width: 1100px;
            margin: 35px auto;
        }

        .intro {
            margin-bottom: 25px;
        }

        .intro h2 {
            margin-bottom: 8px;
        }

        .intro p {
            color: #64748b;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin: 22px 0;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }

        button, .profile-link {
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 7px;
            padding: 12px 17px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        button:hover, .profile-link:hover {
            background: #173b70;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 18px #0000000d;
            overflow-wrap: anywhere;
        }

        .avatar {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: #dbeafe;
            color: #173b70;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .card h3 {
            margin: 0 0 8px;
        }

        .course {
            color: #64748b;
            font-size: 14px;
        }

        .skills {
            margin: 15px 0;
            color: #334155;
        }

        .empty, .error {
            background: white;
            padding: 25px;
            border-radius: 10px;
        }

        .error {
            color: #991b1b;
            background: #fee2e2;
        }

        @media (max-width: 520px) {
            .search-form {
                flex-direction: column;
            }

            header a {
                margin-left: 0;
                margin-right: 12px;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="find_teammates.php">Find Teammates</a>
        <a href="logout.php">Use dashboard Logout button</a>
    </nav>
</header>

<main>
    <section class="intro">
        <h2>Find Your Teammates</h2>
        <p>Discover students, explore their skills and build your next project team.</p>
    </section>

    <form class="search-form" method="GET" action="find_teammates.php">
        <input
            type="search"
            name="q"
            placeholder="Search by name, course or skills..."
            value="<?= escapeHTML($search) ?>"
            maxlength="100"
        >
        <button type="submit">Search</button>
        <a class="profile-link" href="find_teammates.php">Reset</a>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error"><?= escapeHTML($error) ?></div>

    <?php elseif (empty($students)): ?>
        <div class="empty">
            <h3>No teammates found</h3>
            <p>
                <?php if ($search !== ''): ?>
                    Try another name, course or skill.
                <?php else: ?>
                    Register another test student to see profiles here.
                <?php endif; ?>
            </p>
        </div>

    <?php else: ?>
        <div class="grid">
            <?php foreach ($students as $student): ?>
                <article class="card">
                    <div class="avatar">
                        <?= escapeHTML(
                            strtoupper(substr($student['full_name'], 0, 1))
                        ) ?>
                    </div>

                    <h3><?= escapeHTML($student['full_name']) ?></h3>

                    <p class="course">
                        <?= escapeHTML($student['course'] ?: 'Course not provided') ?>
                    </p>

                    <p class="skills">
                        <strong>Skills:</strong><br>
                        <?= escapeHTML($student['skills'] ?: 'Not provided yet') ?>
                    </p>

                    <a
                        class="profile-link"
                        href="student_profile.php?id=<?= (int)$student['id'] ?>"
                    >
                        View Profile
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
