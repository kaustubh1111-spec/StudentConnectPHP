<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];

function dashboard_h($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$currentUser = [
    'full_name' => 'Student',
    'course' => ''
];

$stats = [
    'students' => 0,
    'projects' => 0,
    'my_projects' => 0,
    'open_projects' => 0
];

$recentProjects = [];
$dashboardError = false;

try {
    // Load the signed-in student's basic profile.
    $userStmt = $pdo->prepare(
        'SELECT full_name, course FROM users WHERE id = :user_id LIMIT 1'
    );
    $userStmt->execute(['user_id' => $userId]);
    $loadedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loadedUser) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $currentUser = $loadedUser;

    $stats['students'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM users'
    )->fetchColumn();

    $stats['projects'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM projects'
    )->fetchColumn();

    $stats['open_projects'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM projects WHERE status = 'Open'"
    )->fetchColumn();

    $myProjectsStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT p.id)
         FROM projects p
         LEFT JOIN project_members pm
            ON pm.project_id = p.id AND pm.user_id = :member_user
         WHERE p.owner_id = :owner_user OR pm.user_id = :member_filter'
    );
    $myProjectsStmt->execute([
        'member_user' => $userId,
        'owner_user' => $userId,
        'member_filter' => $userId
    ]);
    $stats['my_projects'] = (int) $myProjectsStmt->fetchColumn();

    $recentStmt = $pdo->prepare(
        'SELECT
            p.id,
            p.title,
            p.status,
            p.created_at,
            u.full_name AS owner_name,
            (SELECT COUNT(*)
             FROM project_members pm_count
             WHERE pm_count.project_id = p.id) AS member_count
         FROM projects p
         INNER JOIN users u ON u.id = p.owner_id
         LEFT JOIN project_members pm
            ON pm.project_id = p.id AND pm.user_id = :member_user
         WHERE p.owner_id = :owner_user OR pm.user_id = :member_filter
         ORDER BY p.created_at DESC
         LIMIT 5'
    );
    $recentStmt->execute([
        'member_user' => $userId,
        'owner_user' => $userId,
        'member_filter' => $userId
    ]);
    $recentProjects = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Dashboard load error: ' . $e->getMessage());
    $dashboardError = true;
}

$fullName = trim((string) ($currentUser['full_name'] ?? 'Student'));
$firstNameParts = preg_split('/\s+/', $fullName);
$firstName = $firstNameParts[0] ?? 'Student';
$initial = strtoupper(substr($firstName, 0, 1));
$course = trim((string) ($currentUser['course'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | StudentConnect</title>
    <style>
        :root {
            --navy: #152b4a;
            --navy-2: #203d64;
            --blue: #3268d8;
            --blue-light: #eaf1ff;
            --ink: #1b2638;
            --muted: #718096;
            --line: #e6ebf2;
            --page: #f5f7fb;
            --white: #ffffff;
            --green: #17845d;
            --green-light: #e5f7ef;
            --amber: #a9650a;
            --amber-light: #fff3dc;
            --shadow: 0 8px 24px rgba(26, 45, 76, 0.06);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--ink);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }
        a { color: inherit; }
        .app-shell { min-height: 100vh; display: flex; }
        .sidebar {
            width: 250px;
            flex: 0 0 250px;
            background: var(--navy);
            color: #e8effa;
            padding: 25px 16px 18px;
            display: flex;
            flex-direction: column;
        }
        .brand { display: flex; align-items: center; gap: 11px; padding: 0 10px 28px; }
        .brand-mark {
            height: 40px; width: 40px; display: grid; place-items: center;
            border-radius: 13px; background: #4778e5; color: white;
            font-size: 17px; font-weight: 800; letter-spacing: -1px;
        }
        .brand-name { font-size: 18px; font-weight: 750; letter-spacing: -.4px; color: white; }
        .brand-tagline { color: #a8bbd7; font-size: 11px; margin-top: 1px; }
        .nav-label { padding: 0 12px; margin: 8px 0 10px; color: #91a7c7; font-size: 10px; font-weight: 800; letter-spacing: 1.4px; text-transform: uppercase; }
        .nav-list { display: grid; gap: 5px; }
        .nav-link {
            display: flex; align-items: center; gap: 12px; padding: 11px 12px;
            border-radius: 9px; text-decoration: none; color: #c7d4e8;
            transition: background .15s ease, color .15s ease;
        }
        .nav-link:hover { background: rgba(255,255,255,.08); color: white; }
        .nav-link.active { background: #2d4b75; color: white; box-shadow: inset 3px 0 #82a6ff; }
        .nav-icon { width: 20px; text-align: center; font-size: 16px; opacity: .95; }
        .sidebar-bottom { margin-top: auto; padding: 18px 9px 0; }
        .sidebar-note { background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.09); border-radius: 12px; padding: 14px; }
        .sidebar-note strong { display: block; font-size: 13px; color: white; margin-bottom: 5px; }
        .sidebar-note p { color: #adbeda; font-size: 12px; line-height: 1.5; margin: 0; }
        .main-area { min-width: 0; flex: 1; }
        .topbar {
            min-height: 78px; padding: 16px 34px; background: rgba(255,255,255,.92);
            border-bottom: 1px solid var(--line); display: flex; align-items: center;
            justify-content: space-between; gap: 20px;
        }
        .breadcrumb { color: var(--muted); font-size: 13px; }
        .breadcrumb strong { color: var(--ink); font-weight: 650; }
        .account { display: flex; align-items: center; gap: 11px; }
        .avatar { width: 41px; height: 41px; border-radius: 50%; display: grid; place-items: center; background: #e4ebff; color: #2956ad; font-weight: 800; font-size: 16px; }
        .account-name { font-size: 13px; font-weight: 750; }
        .account-course { font-size: 11px; color: var(--muted); margin-top: 1px; }
        .logout-form { margin-left: 6px; }
        .logout-button { background: transparent; border: 1px solid var(--line); border-radius: 8px; color: #46546a; padding: 9px 12px; font-size: 12px; cursor: pointer; }
        .logout-button:hover { background: #f7f9fc; border-color: #ccd6e4; }
        .content { max-width: 1440px; margin: 0 auto; padding: 30px 34px 40px; }
        .welcome-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 25px; }
        .eyebrow { color: var(--blue); text-transform: uppercase; letter-spacing: 1.4px; font-size: 10px; font-weight: 850; margin-bottom: 7px; }
        h1 { margin: 0; color: #17253b; font-size: clamp(25px, 2.5vw, 32px); line-height: 1.2; letter-spacing: -.8px; }
        .welcome-copy { margin: 9px 0 0; color: var(--muted); font-size: 14px; }
        .welcome-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 15px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; transition: transform .15s ease, background .15s ease; white-space: nowrap; }
        .button:hover { transform: translateY(-1px); }
        .button-primary { background: var(--blue); color: white; box-shadow: 0 4px 10px rgba(50,104,216,.16); }
        .button-primary:hover { background: #275bc4; }
        .button-secondary { background: white; color: #33435a; border: 1px solid var(--line); }
        .button-secondary:hover { background: #f9fbff; }
        .alert { border-radius: 10px; padding: 13px 16px; margin: 0 0 20px; font-size: 13px; }
        .alert-error { background: #fff0ef; color: #a3352c; border: 1px solid #f5d0cc; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 25px; }
        .stat-card { background: var(--white); border: 1px solid var(--line); border-radius: 14px; padding: 19px; box-shadow: var(--shadow); min-width: 0; }
        .stat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .stat-label { color: var(--muted); font-size: 12px; font-weight: 650; }
        .stat-icon { height: 37px; width: 37px; border-radius: 11px; display: grid; place-items: center; font-size: 17px; }
        .stat-icon.blue { background: var(--blue-light); color: var(--blue); }
        .stat-icon.green { background: var(--green-light); color: var(--green); }
        .stat-icon.amber { background: var(--amber-light); color: var(--amber); }
        .stat-value { font-size: 29px; font-weight: 800; letter-spacing: -1px; margin-top: 12px; color: #18263b; line-height: 1.1; }
        .stat-foot { color: #97a1b1; font-size: 11px; margin-top: 7px; }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 15px; margin-bottom: 15px; }
        .section-heading h2 { margin: 0; font-size: 18px; letter-spacing: -.3px; }
        .section-heading p { margin: 4px 0 0; color: var(--muted); font-size: 12px; }
        .text-link { text-decoration: none; color: var(--blue); font-weight: 750; font-size: 12px; white-space: nowrap; }
        .text-link:hover { text-decoration: underline; }
        .projects-panel { border-radius: 14px; background: white; border: 1px solid var(--line); box-shadow: var(--shadow); overflow: hidden; }
        .projects-panel-header { padding: 18px 21px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .projects-panel-header h2 { margin: 0; font-size: 16px; }
        .projects-panel-header p { margin: 4px 0 0; color: var(--muted); font-size: 12px; }
        .project-list { width: 100%; border-collapse: collapse; }
        .project-list th { padding: 12px 20px; color: #8994a5; background: #fafbfd; font-size: 10px; letter-spacing: .9px; text-transform: uppercase; text-align: left; font-weight: 800; }
        .project-list td { padding: 15px 20px; border-top: 1px solid #eef1f6; vertical-align: middle; font-size: 13px; }
        .project-list tr:hover td { background: #fcfdff; }
        .project-title { font-weight: 750; color: #27364d; text-decoration: none; }
        .project-title:hover { color: var(--blue); }
        .project-subtitle { color: #98a1ae; font-size: 11px; margin-top: 3px; }
        .status { display: inline-block; padding: 5px 9px; border-radius: 30px; font-size: 10px; font-weight: 800; white-space: nowrap; }
        .status-open { background: #e5f7ef; color: #18744f; }
        .status-in-progress { background: #eaf1ff; color: #2c5cc0; }
        .status-completed { background: #eef0f4; color: #5c6677; }
        .view-link { font-size: 12px; color: var(--blue); font-weight: 750; text-decoration: none; white-space: nowrap; }
        .view-link:hover { text-decoration: underline; }
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-icon { width: 48px; height: 48px; border-radius: 14px; background: var(--blue-light); color: var(--blue); margin: 0 auto 12px; display: grid; place-items: center; font-size: 22px; }
        .empty-state h3 { margin: 0; font-size: 16px; }
        .empty-state p { margin: 7px auto 17px; max-width: 400px; color: var(--muted); font-size: 13px; }
        .quick-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 15px; margin-top: 25px; }
        .quick-card { display: flex; align-items: flex-start; gap: 13px; background: white; border: 1px solid var(--line); border-radius: 13px; padding: 17px; text-decoration: none; box-shadow: var(--shadow); transition: border .15s ease, transform .15s ease; }
        .quick-card:hover { border-color: #bdcdf2; transform: translateY(-2px); }
        .quick-icon { flex: 0 0 39px; height: 39px; border-radius: 11px; background: #f0f4fc; color: #315eb5; display: grid; place-items: center; font-size: 17px; }
        .quick-card strong { display: block; font-size: 13px; margin: 1px 0 5px; }
        .quick-card span { display: block; color: var(--muted); font-size: 11px; line-height: 1.5; }
        .footer { margin-top: 27px; color: #9aa4b3; font-size: 11px; text-align: center; }
        @media (max-width: 1100px) {
            .sidebar { width: 220px; flex-basis: 220px; }
            .content { padding: 26px 24px 35px; }
            .topbar { padding: 15px 24px; }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 760px) {
            .app-shell { display: block; }
            .sidebar { width: 100%; padding: 13px 14px; }
            .brand { padding: 0 5px 12px; }
            .nav-label, .sidebar-bottom { display: none; }
            .nav-list { display: flex; overflow-x: auto; padding-bottom: 2px; }
            .nav-link { flex: 0 0 auto; padding: 9px 11px; font-size: 12px; }
            .nav-icon { display: none; }
            .topbar { min-height: 65px; padding: 12px 17px; }
            .breadcrumb { display: none; }
            .content { padding: 24px 16px 30px; }
            .welcome-row { display: block; }
            .welcome-actions { margin-top: 18px; }
            .quick-grid { grid-template-columns: 1fr; }
            .project-list thead { display: none; }
            .project-list, .project-list tbody, .project-list tr, .project-list td { display: block; width: 100%; }
            .project-list tr { padding: 5px 0; border-top: 1px solid #eef1f6; }
            .project-list td { border: 0; padding: 6px 17px; }
            .project-list td:first-child { padding-top: 12px; }
            .project-list td:last-child { padding-bottom: 12px; }
            .project-list td::before { content: attr(data-label); display: inline-block; color: #929cac; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; width: 88px; }
        }
        @media (max-width: 420px) {
            .stats-grid { gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-value { font-size: 25px; }
            .stat-icon { width: 31px; height: 31px; }
            .account-course { display: none; }
            .logout-button { padding: 8px 9px; }
            .button { padding: 10px 12px; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">SC</div>
            <div>
                <div class="brand-name">StudentConnect</div>
                <div class="brand-tagline">Build better, together.</div>
            </div>
        </div>

        <div class="nav-label">Workspace</div>
        <nav class="nav-list" aria-label="Main navigation">
            <a class="nav-link active" href="dashboard.php"><span class="nav-icon">▦</span>Dashboard</a>
            <a class="nav-link" href="find_teammates.php"><span class="nav-icon">♧</span>Find Teammates</a>
            <a class="nav-link" href="projects.php"><span class="nav-icon">▤</span>Explore Projects</a>
            <a class="nav-link" href="my_projects.php"><span class="nav-icon">◫</span>My Projects</a>
        </nav>

        <div class="nav-label" style="margin-top: 25px;">Your Account</div>
        <nav class="nav-list" aria-label="Account navigation">
            <a class="nav-link" href="create_project.php"><span class="nav-icon">＋</span>Create Project</a>
            <a class="nav-link" href="edit_profile.php"><span class="nav-icon">◎</span>Edit Profile</a>
            <a class="nav-link" href="student_profile.php?id=<?= $userId ?>"><span class="nav-icon">◉</span>My Profile</a>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-note">
                <strong>Have a project idea?</strong>
                <p>Create a project, share the skills you need, and build your team.</p>
            </div>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="breadcrumb">Workspace <span style="color:#b5becc; padding: 0 7px;">/</span> <strong>Dashboard</strong></div>
            <div class="account">
                <div class="avatar"><?= dashboard_h($initial) ?></div>
                <div>
                    <div class="account-name"><?= dashboard_h($fullName) ?></div>
                    <div class="account-course"><?= dashboard_h($course !== '' ? $course : 'Student account') ?></div>
                </div>
                <form class="logout-form" action="logout.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= dashboard_h($_SESSION['csrf_token']) ?>">
                    <button class="logout-button" type="submit">Log out ↗</button>
                </form>
            </div>
        </header>

        <main class="content">
            <?php if ($dashboardError): ?>
                <div class="alert alert-error" role="alert">
                    Some dashboard information could not be loaded. Check the database connection and table structure, then refresh this page.
                </div>
            <?php endif; ?>

            <section class="welcome-row">
                <div>
                    <div class="eyebrow">Student workspace</div>
                    <h1>Welcome back, <?= dashboard_h($firstName) ?>.</h1>
                    <p class="welcome-copy">Discover collaborators, keep track of your projects, and turn ideas into something real.</p>
                </div>
                <div class="welcome-actions">
                    <a class="button button-secondary" href="projects.php">⌕ Explore Projects</a>
                    <a class="button button-primary" href="create_project.php">＋ Create Project</a>
                </div>
            </section>

            <section class="stats-grid" aria-label="Platform statistics">
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">Registered Students</span><span class="stat-icon blue">♧</span></div>
                    <div class="stat-value"><?= number_format($stats['students']) ?></div>
                    <div class="stat-foot">Students on the platform</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">All Projects</span><span class="stat-icon green">▤</span></div>
                    <div class="stat-value"><?= number_format($stats['projects']) ?></div>
                    <div class="stat-foot">Projects shared by students</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">My Projects</span><span class="stat-icon amber">◫</span></div>
                    <div class="stat-value"><?= number_format($stats['my_projects']) ?></div>
                    <div class="stat-foot">Created or joined by you</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">Open Projects</span><span class="stat-icon blue">↗</span></div>
                    <div class="stat-value"><?= number_format($stats['open_projects']) ?></div>
                    <div class="stat-foot">Currently marked as open</div>
                </article>
            </section>

            <section aria-labelledby="recent-heading">
                <div class="section-heading">
                    <div>
                        <h2 id="recent-heading">Your Recent Projects</h2>
                        <p>A quick view of projects you own or have joined.</p>
                    </div>
                    <a class="text-link" href="my_projects.php">View all projects →</a>
                </div>

                <div class="projects-panel">
                    <div class="projects-panel-header">
                        <div>
                            <h2>Project overview</h2>
                            <p>Latest activity from your project list</p>
                        </div>
                        <a class="text-link" href="create_project.php">＋ New project</a>
                    </div>

                    <?php if (empty($recentProjects)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">▤</div>
                            <h3>Your project journey starts here</h3>
                            <p>You haven't created or joined a project yet. Browse available projects or start one of your own.</p>
                            <a class="button button-primary" href="create_project.php">Create your first project</a>
                            <a class="button button-secondary" href="projects.php">Explore projects</a>
                        </div>
                    <?php else: ?>
                        <table class="project-list">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Owner</th>
                                    <th>Team</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentProjects as $project): ?>
                                    <?php
                                    $status = (string) ($project['status'] ?? 'Open');
                                    $statusClass = strtolower(str_replace(' ', '-', $status));
                                    ?>
                                    <tr>
                                        <td data-label="Project">
                                            <a class="project-title" href="project_details.php?id=<?= (int) $project['id'] ?>"><?= dashboard_h($project['title']) ?></a>
                                            <div class="project-subtitle">Created <?= dashboard_h(date('d M Y', strtotime((string) $project['created_at']))) ?></div>
                                        </td>
                                        <td data-label="Owner"><?= dashboard_h($project['owner_name']) ?></td>
                                        <td data-label="Team"><?= (int) $project['member_count'] ?> member<?= (int) $project['member_count'] === 1 ? '' : 's' ?></td>
                                        <td data-label="Status"><span class="status status-<?= dashboard_h($statusClass) ?>"><?= dashboard_h($status) ?></span></td>
                                        <td data-label="Action"><a class="view-link" href="project_details.php?id=<?= (int) $project['id'] ?>">View details →</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section class="quick-grid" aria-label="Quick actions">
                <a class="quick-card" href="find_teammates.php">
                    <div class="quick-icon">♧</div>
                    <div><strong>Find teammates</strong><span>Discover students by their course and skills.</span></div>
                </a>
                <a class="quick-card" href="projects.php">
                    <div class="quick-icon">⌕</div>
                    <div><strong>Explore projects</strong><span>Find project ideas and teams looking for members.</span></div>
                </a>
                <a class="quick-card" href="edit_profile.php">
                    <div class="quick-icon">◎</div>
                    <div><strong>Complete your profile</strong><span>Keep your skills and course information up to date.</span></div>
                </a>
            </section>

            <div class="footer">StudentConnect · A space for students to collaborate and build together</div>
        </main>
    </div>
</div>
</body>
</html>
