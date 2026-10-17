<?php
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Only logged-in users can access this page.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Deletion must be submitted using POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Verify the CSRF token.
$sessionToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !is_string($submittedToken) ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Security verification failed. Please go back and try again.');
}

// Validate the project ID.
$projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);

if (!$projectId || $projectId < 1) {
    http_response_code(400);
    exit('Invalid project ID.');
}

$userId = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // Lock the project row while checking ownership.
    $stmt = $pdo->prepare(
        'SELECT id, owner_id
         FROM projects
         WHERE id = :id
         FOR UPDATE'
    );

    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $pdo->rollBack();
        header('Location: my_projects.php');
        exit;
    }

    // Only the project owner is allowed to delete the project.
    if ((int) $project['owner_id'] !== $userId) {
        $pdo->rollBack();
        http_response_code(403);
        exit('You are not authorized to delete this project.');
    }

    // Delete the project. Related records are removed by
    // database foreign keys configured with ON DELETE CASCADE.
    $deleteStmt = $pdo->prepare(
        'DELETE FROM projects
         WHERE id = :id AND owner_id = :owner_id'
    );

    $deleteStmt->execute([
        'id' => $projectId,
        'owner_id' => $userId
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        throw new RuntimeException('Project could not be deleted.');
    }

    $pdo->commit();

    header('Location: my_projects.php?deleted=1');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Delete project error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to delete the project right now. Please try again.');
}