
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentConnect | Home</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            margin: 0;
            color: #1f2937;
        }

        header {
            background: #173b70;
            color: white;
            padding: 22px;
            text-align: center;
        }

        main {
            max-width: 760px;
            margin: 60px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 18px #00000012;
        }

        .button {
            display: inline-block;
            background: #2563eb;
            color: white;
            padding: 12px 22px;
            border-radius: 7px;
            text-decoration: none;
            margin: 8px;
        }

        .button:hover {
            background: #173b70;
        }
    </style>
</head>
<body>

<header>
    <h1>StudentConnect</h1>
    <p>Student Project Collaboration Platform</p>
</header>

<main>
    <h2>Find Your Perfect Project Team</h2>
    <p>Connect with students, share your skills and build projects together.</p>

    <a class="button" href="register.php">Get Started</a>
    <a class="button" href="login.php">Login</a>

    <p>
        <?php
            echo "PHP is working successfully!";
        ?>
    </p>
</main>

</body>
</html>
