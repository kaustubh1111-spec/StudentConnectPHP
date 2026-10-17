
<?php
session_start();
require_once __DIR__ . '/db.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

$form = [
    'id' => '',
    'name' => '',
    'email' => '',
    'course' => '',
    'mobile' => ''
];

// Load a student into the form when Edit is clicked.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editId = filter_input(
        INPUT_GET,
        'edit',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$editId) {
        http_response_code(400);
        exit('Invalid student ID.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, course, mobile
         FROM crud_students WHERE id = :id'
    );
    $stmt->execute(['id' => $editId]);
    $student = $stmt->fetch();

    if (!$student) {
        http_response_code(404);
        exit('Student record not found.');
    }

    $form = $student;
}

// Handle Add, Update and Delete form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Invalid form session. Refresh and try again.';
    } elseif ($action === 'delete') {
        $deleteId = filter_var(
            $_POST['student_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$deleteId) {
            $errors[] = 'Invalid student ID.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'DELETE FROM crud_students WHERE id = :id'
                );
                $stmt->execute(['id' => $deleteId]);

                if ($stmt->rowCount() === 0) {
                    header('Location: student_crud.php?missing=1');
                } else {
                    header('Location: student_crud.php?deleted=1');
                }
                exit;

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Unable to delete the student record.';
            }
        }
    } elseif ($action === 'save') {
        $idValue = $_POST['student_id'] ?? '';

        $form['id'] = is_string($idValue) ? $idValue : '';
        $form['name'] = is_string($_POST['name'] ?? null)
            ? trim($_POST['name']) : '';
        $form['email'] = is_string($_POST['email'] ?? null)
            ? trim($_POST['email']) : '';
        $form['course'] = is_string($_POST['course'] ?? null)
            ? trim($_POST['course']) : '';
        $form['mobile'] = is_string($_POST['mobile'] ?? null)
            ? trim($_POST['mobile']) : '';

        // Validate input.
        if ($form['name'] === '' || strlen($form['name']) > 100) {
            $errors[] = 'Name is required and must be at most 100 characters.';
        }

        if (
            !filter_var($form['email'], FILTER_VALIDATE_EMAIL) ||
            strlen($form['email']) > 190
        ) {
            $errors[] = 'Enter a valid email address.';
        }

        if (strlen($form['course']) > 100) {
            $errors[] = 'Course must be at most 100 characters.';
        }

        if (
            $form['mobile'] !== '' &&
            !preg_match('/^[0-9+\-\s()]{7,20}$/', $form['mobile'])
        ) {
            $errors[] = 'Enter a valid mobile number.';
        }

        $studentId = null;

        if ($form['id'] !== '') {
            $studentId = filter_var(
                $form['id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$studentId) {
                $errors[] = 'Invalid student ID for updating.';
            }
        }

        if (empty($errors)) {
            try {
                if ($studentId) {
                    // UPDATE: modify an existing student.
                    $stmt = $pdo->prepare(
                        'UPDATE crud_students
                         SET name = :name,
                             email = :email,
                             course = :course,
                             mobile = :mobile
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        'name' => $form['name'],
                        'email' => $form['email'],
                        'course' => $form['course'] ?: null,
                        'mobile' => $form['mobile'] ?: null,
                        'id' => $studentId
                    ]);

                    header('Location: student_crud.php?updated=1');
                    exit;

                } else {
                    // CREATE: insert a new student.
                    $stmt = $pdo->prepare(
                        'INSERT INTO crud_students
                         (name, email, course, mobile)
                         VALUES (:name, :email, :course, :mobile)'
                    );

                    $stmt->execute([
                        'name' => $form['name'],
                        'email' => $form['email'],
                        'course' => $form['course'] ?: null,
                        'mobile' => $form['mobile'] ?: null
                    ]);

                    header('Location: student_crud.php?added=1');
                    exit;
                }

            } catch (PDOException $e) {
                error_log($e->getMessage());

                if ($e->getCode() === '23000') {
                    $errors[] = 'This email already exists. Use another email.';
                } else {
                    $errors[] = 'Unable to save the record. Check the database.';
                }
            }
        }
    } else {
        $errors[] = 'Invalid operation.';
    }
}

// READ: retrieve all student records.
$stmt = $pdo->query(
    'SELECT id, name, email, course, mobile, created_at
     FROM crud_students
     ORDER BY id DESC'
);
$students = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student CRUD | StudentConnect</title>

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
        }

        header h1 { margin: 0; }

        main {
            max-width: 1100px;
            width: 92%;
            margin: 30px auto;
        }

        .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 18px #0000000d;
        }

        h2 { margin-top: 0; }

        label {
            display: block;
            margin: 15px 0 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        button, .button {
            display: inline-block;
            padding: 10px 14px;
            border: 0;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        button:hover, .button:hover { background: #173b70; }

        .secondary {
            background: #64748b;
            color: white;
        }

        .danger {
            background: #dc2626;
        }

        .danger:hover {
            background: #991b1b;
        }

        .success, .error {
            padding: 12px;
            margin-bottom: 16px;
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

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            overflow-wrap: anywhere;
        }

        th {
            background: #eff6ff;
        }

        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .actions form { margin: 0; }

        @media (max-width: 600px) {
            .card { padding: 17px; }
        }
    </style>
</head>

<body>

<header>
    <h1>Student Record Management</h1>
    <p>PHP and MySQL CRUD Practical — Assignment 13</p>
</header>

<main>

    <section class="card">
        <h2>
            <?= $form['id'] !== '' ? 'Update Student' : 'Add Student' ?>
        </h2>

        <?php if (isset($_GET['added'])): ?>
            <div class="success">Student added successfully.</div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="success">Student updated successfully.</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="success">Student deleted successfully.</div>
        <?php elseif (isset($_GET['missing'])): ?>
            <div class="error">That student record no longer exists.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="student_crud.php">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="student_id"
                   value="<?= h($form['id']) ?>">

            <label for="name">Student Name *</label>
            <input type="text" id="name" name="name"
                   maxlength="100" required
                   value="<?= h($form['name']) ?>">

            <label for="email">Email Address *</label>
            <input type="email" id="email" name="email"
                   maxlength="190" required
                   value="<?= h($form['email']) ?>">

            <label for="course">Course / Department</label>
            <input type="text" id="course" name="course"
                   maxlength="100"
                   value="<?= h($form['course']) ?>"
                   placeholder="e.g. Computer Engineering">

            <label for="mobile">Mobile Number</label>
            <input type="text" id="mobile" name="mobile"
                   maxlength="20"
                   value="<?= h($form['mobile']) ?>"
                   placeholder="e.g. 9876543210">

            <br><br>

            <button type="submit">
                <?= $form['id'] !== '' ? 'Update Student' : 'Add Student' ?>
            </button>

            <?php if ($form['id'] !== ''): ?>
                <a class="button secondary" href="student_crud.php">
                    Cancel Edit
                </a>
            <?php endif; ?>
        </form>
    </section>

    <section class="card">
        <h2>Student Records</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Mobile</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6">No student records yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= (int)$student['id'] ?></td>
                            <td><?= h($student['name']) ?></td>
                            <td><?= h($student['email']) ?></td>
                            <td><?= h($student['course'] ?: '—') ?></td>
                            <td><?= h($student['mobile'] ?: '—') ?></td>
                            <td>
                                <div class="actions">
                                    <a class="button"
                                       href="student_crud.php?edit=<?= (int)$student['id'] ?>">
                                        Edit
                                    </a>

                                    <form method="POST"
                                          action="student_crud.php"
                                          onsubmit="return confirm('Delete this student record?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="action"
                                               value="delete">

                                        <input type="hidden" name="student_id"
                                               value="<?= (int)$student['id'] ?>">

                                        <button type="submit" class="danger">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
