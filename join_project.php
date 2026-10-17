
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the Join Project button to submit a request.');
}

$token = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($token) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $token)
) {
    http_response_code(403);
    exit('Invalid form session. Return to the project and try again.');
}

$projectId = filter_input(
    INPUT_POST,
    'project_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$projectId) {
    http_response_code(400);
    exit('Invalid project ID.');
}

require_once __DIR__ . '/db.php';

function backToProject($projectId, $error) {
    header(
        'Location: project_details.php?id=' .
        (int)$projectId . '&join_error=' . rawurlencode($error)
    );
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock this project while checking capacity so concurrent
    // join requests cannot overfill the team.
    $stmt = $pdo->prepare(
        'SELECT id, status, max_members
         FROM projects
         WHERE id = :id
         FOR UPDATE'
    );

    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Project not found.');
    }

    if ($project['status'] !== 'Open') {
        $pdo->rollBack();
        backToProject($projectId, 'closed');
    }

    $existingStmt = $pdo->prepare(
        'SELECT 1 FROM project_members
         WHERE project_id = :project_id AND user_id = :user_id
         LIMIT 1'
    );

    $existingStmt->execute([
        'project_id' => $projectId,
        'user_id' => (int)$_SESSION['user_id']
    ]);

    if ($existingStmt->fetchColumn()) {
        $pdo->rollBack();
        backToProject($projectId, 'already_member');
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM project_members
         WHERE project_id = :project_id'
    );

    $countStmt->execute(['project_id' => $projectId]);
    $memberCount = (int)$countStmt->fetchColumn();

    if ($memberCount >= (int)$project['max_members']) {
        $pdo->rollBack();
        backToProject($projectId, 'full');
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO project_members (project_id, user_id, role)
         VALUES (:project_id, :user_id, 'Member')"
    );

   
$insertStmt->execute([
    'project_id' => $projectId,
    'user_id' => (int)$_SESSION['user_id']
]);

// Find the name of the student who joined.
$nameStmt = $pdo->prepare(
    'SELECT full_name FROM users WHERE id = :id'
);
$nameStmt->execute([
    'id' => (int)$_SESSION['user_id']
]);

$joiningName = $nameStmt->fetchColumn();

if (!$joiningName) {
    $joiningName = 'A student';
}

$notificationText = $joiningName . ' joined the project team.';

// Get the other project members, excluding the joining student.
$recipientsStmt = $pdo->prepare(
    'SELECT user_id
     FROM project_members
     WHERE project_id = :project_id
       AND user_id <> :joining_user'
);

$recipientsStmt->execute([
    'project_id' => $projectId,
    'joining_user' => (int)$_SESSION['user_id']
]);

$recipientIds = $recipientsStmt->fetchAll(PDO::FETCH_COLUMN);

$notifyStmt = $pdo->prepare(
    'INSERT INTO notifications
        (recipient_id, project_id, actor_id, type, message)
     VALUES
        (:recipient_id, :project_id, :actor_id, :type, :message)'
);

foreach ($recipientIds as $recipientId) {
    $notifyStmt->execute([
        'recipient_id' => (int)$recipientId,
        'project_id' => $projectId,
        'actor_id' => (int)$_SESSION['user_id'],
        'type' => 'member_joined',
        'message' => $notificationText
    ]);
}

$pdo->commit();


    header(
        'Location: project_details.php?id=' .
        (int)$projectId . '&joined=1'
    );
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    backToProject($projectId, 'failed');
}
?>
