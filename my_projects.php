
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Only logged-in students can view their projects.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$projects = [];
$loadError = false;

try {
    $sql = "
        SELECT
            p.id,
            p.title,
            p.description,
            p.required_skills,
            p.max_members,
            p.status,
            p.created_at,
            u.full_name AS owner_name,

            CASE
                WHEN p.owner_id = :owner_case THEN 'Owner'
                ELSE 'Member'
            END AS my_role,

            (
                SELECT COUNT(*)
                FROM project_members pm_count
                WHERE pm_count.project_id = p.id
            ) AS member_count

        FROM projects p

        INNER JOIN users u
            ON u.id = p.owner_id

        LEFT JOIN project_members pm
            ON pm.project_id = p.id
            AND pm.user_id = :member_id

        WHERE p.owner_id = :owner_filter
           OR pm.user_id = :member_filter

        ORDER BY p.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'owner_case'   => $userId,
        'member_id'    => $userId,
        'owner_filter' => $userId,
        'member_filter'=> $userId
    ]);

    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('My Projects error: ' . $e->getMessage());
    $loadError = true;
}

function h($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Projects | StudentConnect</title>

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
            gap: 15px;
            flex-wrap: wrap;
        }

        header h2 {
            margin: 0;
        }

        header a {
            color: white;
            text-decoration: none;
            margin-left: 16px;
        }

        main {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 24px;
        }

        .page-heading p {
            color: #64748b;
        }

        .success-message {
            background: #dcfce7;
            color: #166534;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 14px;
            border-radius: 8px;
        }

        .project-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .project-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .project-card h3 {
            margin-top: 0;
            color: #173b69;
        }

        .description {
            line-height: 1.6;
            color: #475569;
            overflow-wrap: anywhere;
        }

        .metadata {
            font-size: 14px;
            line-height: 1.9;
            color: #475569;
            margin: 16px 0;
        }

        .role {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: bold;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #f1f5f9;
            font-size: 12px;
            font-weight: bold;
        }

        .button {
            display: inline-block;
            padding: 10px 15px;
            margin-top: 8px;
            margin-right: 6px;
            border-radius: 6px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .button:hover {
            background: #1d4ed8;
        }

        .secondary {
            background: #475569;
        }

        .secondary:hover {
            background: #334155;
        }

        .empty-state {
            background: white;
            padding: 35px 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .empty-state a {
            color: #2563eb;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            header a {
                margin: 0 10px 0 0;
            }

            main {
                margin-top: 20px;
                padding: 0 14px;
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
        <a href="create_project.php">Create Project</a>
    </nav>
</header>

<main>

    <div class="page-heading">
        <h1>My Projects</h1>
        <p>Manage the projects you created and the projects you joined.</p>
    </div>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
        <div class="success-message" role="status">
            Project deleted successfully.
        </div>
    <?php endif; ?>

    <?php if ($loadError): ?>

        <div class="error-message">
            Unable to load your projects. Please check your database
            connection and try again.
        </div>

    <?php elseif (empty($projects)): ?>

        <div class="empty-state">
            <h2>No projects yet</h2>
            <p>
                You have not created or joined any projects.
                Explore existing projects or start your own.
            </p>

            <a class="button" href="projects.php">Explore Projects</a>
            <a class="button secondary" href="create_project.php">
                Create Project
            </a>
        </div>

    <?php else: ?>

        <div class="project-grid">

            <?php foreach ($projects as $project): ?>

                <article class="project-card">

                    <h3><?= h($project['title']) ?></h3>

                    <span class="role">
                        <?= h($project['my_role']) ?>
                    </span>

                    <span class="status">
                        <?= h($project['status']) ?>
                    </span>

                    <p class="description">
                        <?php
                        $description = (string) ($project['description'] ?? '');

                        if (strlen($description) > 160) {
                            $description = substr($description, 0, 160) . '...';
                        }

                        echo h($description);
                        ?>
                    </p>

                    <div class="metadata">
                        <strong>Project Owner:</strong>
                        <?= h($project['owner_name']) ?><br>

                        <strong>Team Members:</strong>
                        <?= (int) $project['member_count'] ?>
                        / <?= (int) $project['max_members'] ?><br>

                        <strong>Required Skills:</strong>
                        <?= h($project['required_skills'] ?: 'Not specified') ?>
                    </div>

                    <a
                        class="button"
                        href="project_details.php?id=<?= (int) $project['id'] ?>"
                    >
                        View Project
                    </a>

                    <?php if ($project['my_role'] === 'Owner'): ?>
                        <a
                            class="button secondary"
                            href="edit_project.php?id=<?= (int) $project['id'] ?>"
                        >
                            Edit Project
                        </a>
                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
