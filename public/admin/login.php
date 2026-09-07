<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Support\Bootstrap;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$app = Bootstrap::boot();
$adminConfig = $app->appConfig()['admin'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $validUsername = hash_equals($adminConfig['username'], $username);
    $validPassword = $adminConfig['password_hash'] !== ''
        && password_verify($password, $adminConfig['password_hash']);

    if ($validUsername && $validPassword) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_username'] = $username;
        $app->auditLogger()->log($username, 'admin.login', null, null, [], $_SERVER['REMOTE_ADDR'] ?? null);
        header('Location: /admin/index.php');
        exit;
    }

    $error = 'Benutzername oder Passwort ist falsch.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login – <?= htmlspecialchars($app->appConfig()['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 400px; margin-top: 100px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3 text-center">📧 Email Marketing System</h1>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Benutzername</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Passwort</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Anmelden</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
