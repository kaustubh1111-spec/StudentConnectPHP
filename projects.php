
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
$projects = [];
$error = '';

try {
    $sql = "SELECT p.id, p.owner_id, p.title, p.description,
                   p.required_skills, p.max_members, p.status,
                   p.created_at, owner.full_name AS owner_name,
                   (SELECT COUNT(*)
 FROM project_members pm
 WHERE pm.project_id = p.id) AS member_count,

(SELECT COUNT(*)
 FROM project_tasks t
 WHERE t.project_id = p.id) AS total_tasks,

(SELECT COUNT(*)
 FROM project_tasks t
 WHERE t.project_id = p.id
   AND t.status = 'Completed') AS completed_tasks
            FROM projects p
            JOIN users owner ON owner.id = p.owner_id";

    if ($search !== '') {
        $sql .= " WHERE p.title LIKE :title_q
                  OR p.description LIKE :description_q
                  OR p.required_skills LIKE :skills_q
                  OR owner.full_name LIKE :owner_q";
    }

    $sql .= " ORDER BY p.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt->execute([
            'title_q' => $like,
            'description_q' => $like,
            'skills_q' => $like,
            'owner_q' => $like
        ]);
    } else {
        $stmt->execute();
    }

    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Unable to load projects. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Explore Projects | StudentConnect</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f6fc;
    color: #1e293b;
}
.progress-track {
    width: 100%;
    height: 10px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    margin: 10px 0 6px;
}

.progress-fill {
    height: 100%;
    background: #2563eb;
    border-radius: 10px;
}

.progress-label {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    color: #64748b;
    font-size: 13px;
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

nav a:hover { text-decoration: underline; }

main {
    max-width: 1100px;
    width: 92%;
    margin: 35px auto;
}

.intro h2 { margin-bottom: 8px; }
.muted { color: #64748b; line-height: 1.6; }

.search-form {
    display: flex;
    gap: 10px;
    margin: 24px 0;
}

.search-form input {
    flex: 1;
    min-width: 0;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 15px;
}

.button {
    display: inline-block;
    padding: 11px 16px;
    background: #2563eb;
    border: 0;
    border-radius: 7px;
    color: white;
    font-size: 14px;
    text-decoration: none;
    cursor: pointer;
}

.button:hover { background: #173b70; }

.button.light {
    color: #173b70;
    background: #dbeafe;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 4px 18px #0000000d;
    overflow-wrap: anywhere;
}

.card h3 { margin-top: 0; }

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #dcfce7;
    color: #166534;
    font-size: 12px;
    font-weight: bold;
}

.status.other {
    background: #e2e8f0;
    color: #334155;
}

.skills {
    color: #334155;
    min-height: 40px;
}

.meta {
    color: #64748b;
    font-size: 14px;
    line-height: 1.8;
    margin-bottom: 18px;
}

.notice {
    padding: 20px;
    border-radius: 10px;
    background: white;
}

@media (max-width: 520px) {
    .search-form { flex-direction: column; }
}
</style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="find_teammates.php">Find Teammates</a>
        <a href="projects.php">Projects</a>
        <a href="create_project.php">Create Project</a>
    </nav>
</header>

<main>
    <section class="intro">
        <h2>Explore Projects</h2>
        <p class="muted">
            Discover project ideas, explore required skills and find a team to join.
        </p>
    </section>

    <form class="search-form" method="GET" action="projects.php">
        <input
            type="search"
            name="q"
            maxlength="100"
            placeholder="Search titles, skills or project owners..."
            value="<?= escapeHTML($search) ?>"
        >
        <button class="button" type="submit">Search</button>
        <a class="button light" href="projects.php">Reset</a>
    </form>

    <?php if ($error !== ''): ?>
        <div class="notice"><?= escapeHTML($error) ?></div>

    <?php elseif (empty($projects)): ?>
        <div class="notice">
            <h3>No projects found</h3>
            <p>
                <?= $search !== ''
                    ? 'Try a different search term or create a new project.'
                    : 'Be the first to share a project idea.' ?>
            </p>
            <a class="button" href="create_project.php">Create Project</a>
        </div>

    <?php else: ?>
        <div class="grid">
        <?php foreach ($projects as $project): ?>
            <?php
$totalTasks = (int)$project['total_tasks'];
$completedTasks = (int)$project['completed_tasks'];

$progress = $totalTasks > 0
    ? (int)round(($completedTasks / $totalTasks) * 100)
    : 0;
?>
            <article class="card">
                <span class="status <?= $project['status'] === 'Open' ? '' : 'other' ?>">
                    <?= escapeHTML($project['status']) ?>
                </span>

                <h3><?= escapeHTML($project['title']) ?></h3>

                <p class="muted">
                    <?= escapeHTML(substr($project['description'], 0, 180)) ?><?= strlen($project['description']) > 180 ? '...' : '' ?>
                </p>

                <p class="skills">
                    <strong>Required skills:</strong><br>
                    <?= escapeHTML($project['required_skills'] ?: 'Not specified') ?>
                </p>

                <div class="meta">
                    <div>Created by: <?= escapeHTML($project['owner_name']) ?></div>
                    <div>
                        Team: <?= (int)$project['member_count'] ?>
                        / <?= (int)$project['max_members'] ?> students
                    </div>
                    <div>Created: <?= escapeHTML($project['created_at']) ?></div>
                </div>
<div class="progress-label">
    <span>Project Progress</span>
    <strong><?= $progress ?>%</strong>
</div>

<div
    class="progress-track"
    role="progressbar"
    aria-label="Project task completion"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-valuenow="<?= $progress ?>"
>
    <div
        class="progress-fill"
        style="width: <?= $progress ?>%;"
    ></div>
</div>

<p class="muted">
    <?= $completedTasks ?> of <?= $totalTasks ?> tasks completed
</p>
                <a class="button"
                   href="project_details.php?id=<?= (int)$project['id'] ?>">
                    View Project
                </a>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();