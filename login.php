
<?php
session_start();
require_once __DIR__ . '/db.php';

function escapeHTML($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password)
    ) {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, password_hash
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify(
                $password,
                $user['password_hash']
            )) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['full_name'];

                // Rotate the CSRF token after login.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid email or password.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Login is temporarily unavailable. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | StudentConnect</title>

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
            padding: 22px 15px;
            text-align: center;
        }

        header h1 { margin: 0 0 8px; }
        header p { margin: 0; color: #dbeafe; }

        .container {
            max-width: 450px;
            width: 92%;
            margin: 45px auto;
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 6px 24px #1e293b12;
        }

        h2 { text-align: center; margin-top: 0; }

        .subtitle {
            color: #64748b;
            text-align: center;
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 16px 0 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        button {
            width: 100%;
            padding: 13px;
            margin-top: 22px;
            background: #2563eb;
            color: white;
            border: 0;
            border-radius: 7px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #173b70; }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 7px;
        }

        .footer {
            text-align: center;
            margin-top: 22px;
        }

        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>

<body>
<header>
    <h1>StudentConnect</h1>
    <p>Student Project Collaboration Platform</p>
</header>

<main class="container">
    <h2>Welcome Back</h2>
    <p class="subtitle">Login to connect with your project team.</p>

    <?php if (isset($_GET['logged_out'])): ?>
        <p>You have been logged out successfully.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= escapeHTML($error) ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <input type="hidden" name="csrf_token"
               value="<?= escapeHTML($_SESSION['csrf_token']) ?>">

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               autocomplete="username" maxlength="190"
               value="<?= escapeHTML($email) ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>

        <button type="submit">Login</button>
    </form>

    <div class="footer">
        Don't have an account?
        <a href="register.php">Register here</a>
        <p><a href="index.php">← Back to Home</a></p>
    </div>
</main>
</body>
</html>
