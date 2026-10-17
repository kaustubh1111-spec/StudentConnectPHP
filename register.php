
<?php
session_start();
require_once __DIR__ . '/db.php';

// Create a CSRF token to protect the registration form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeHTML($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$fullName = '';
$email = '';
$course = '';
$skills = '';
$success = isset($_GET['success']) && $_GET['success'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    // Verify form token.
    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }

    // Validate form fields.
    if ($fullName === '' || strlen($fullName) > 100) {
        $errors[] = 'Enter your name (maximum 100 characters).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($course) > 100) {
        $errors[] = 'Course must not exceed 100 characters.';
    }

    if (strlen($skills) > 500) {
        $errors[] = 'Skills must not exceed 500 characters.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    // Check email and save the student.
    if (empty($errors)) {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = :email LIMIT 1'
            );
            $check->execute(['email' => $email]);

            if ($check->fetch()) {
                $errors[] = 'This email is already registered.';
            } else {
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $insert = $pdo->prepare(
                    'INSERT INTO users
                    (full_name, email, password_hash, course, skills)
                    VALUES
                    (:full_name, :email, :password_hash, :course, :skills)'
                );

                $insert->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'course' => $course !== '' ? $course : null,
                    'skills' => $skills !== '' ? $skills : null
                ]);

                header('Location: register.php?success=1');
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'This email may already be registered. Please try another.';
            } else {
                $errors[] = 'Registration failed. Please check the database configuration.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | StudentConnect</title>

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
            text-align: center;
            padding: 22px 15px;
        }

        header h1 {
            margin: 0 0 8px;
        }

        header p {
            margin: 0;
            color: #dbeafe;
        }

        .container {
            width: 92%;
            max-width: 560px;
            margin: 32px auto;
            padding: 28px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 6px 24px #1e293b12;
        }

        h2 {
            margin-top: 0;
            text-align: center;
        }

        .subtitle {
            color: #64748b;
            text-align: center;
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 15px 0 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #173b70;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 16px;
        }

        .error {
            color: #991b1b;
            background: #fee2e2;
        }

        .success {
            color: #166534;
            background: #dcfce7;
        }

        .footer {
            margin-top: 22px;
            text-align: center;
        }

        a {
            color: #2563eb;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>StudentConnect</h1>
    <p>Student Project Collaboration Platform</p>
</header>

<main class="container">
    <h2>Create Your Account</h2>
    <p class="subtitle">Join students and collaborate on projects.</p>

    <?php if ($success): ?>
        <div class="message success">
            Registration successful! Your account has been created.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="message error">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeHTML($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="register.php" method="POST">
        <input type="hidden" name="csrf_token"
               value="<?= escapeHTML($_SESSION['csrf_token']) ?>">

        <label for="full_name">Full Name *</label>
        <input type="text" id="full_name" name="full_name"
               maxlength="100" autocomplete="name"
               value="<?= escapeHTML($fullName) ?>" required>

        <label for="email">Email Address *</label>
        <input type="email" id="email" name="email"
               maxlength="190" autocomplete="email"
               value="<?= escapeHTML($email) ?>" required>

        <label for="course">Course / Department</label>
        <input type="text" id="course" name="course"
               maxlength="100"
               placeholder="e.g. Third Year BTech CSE"
               value="<?= escapeHTML($course) ?>">

        <label for="skills">Your Skills</label>
        <input type="text" id="skills" name="skills"
               maxlength="500"
               placeholder="e.g. PHP, HTML, CSS, MySQL"
               value="<?= escapeHTML($skills) ?>">

        <label for="password">Password *</label>
        <input type="password" id="password" name="password"
               minlength="8" autocomplete="new-password" required>

        <label for="confirm_password">Confirm Password *</label>
        <input type="password" id="confirm_password"
               name="confirm_password" minlength="8"
               autocomplete="new-password" required>

        <button type="submit">Create Account</button>
    </form>

    <div class="footer">
        <a href="index.php">← Back to Home</a>
    </div>
</main>

</body>
</html>
