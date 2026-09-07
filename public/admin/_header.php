<?php
/** @var string $activeNav */
$activeNav ??= '';
$navItems = [
    'dashboard' => ['label' => '📊 Dashboard', 'href' => '/admin/index.php'],
    'tags' => ['label' => '🏷️ Tags & Segmente', 'href' => '/admin/tags.php'],
    'contacts' => ['label' => '👥 Kontakte', 'href' => '/admin/contacts.php'],
    'campaigns' => ['label' => '📢 Kampagnen', 'href' => '/admin/campaigns.php'],
    'newsletter' => ['label' => '✉️ Newsletter', 'href' => '/admin/newsletter.php'],
    'templates' => ['label' => '🎨 Templates', 'href' => '/admin/templates.php'],
    'settings' => ['label' => '⚙️ Einstellungen', 'href' => '/admin/settings.php'],
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> – Email Marketing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/index.php">📧 Email Marketing</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <?php foreach ($navItems as $key => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeNav === $key ? 'active fw-bold' : '' ?>" href="<?= $item['href'] ?>"><?= $item['label'] ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <span class="nav-link text-secondary"><?= htmlspecialchars($_SESSION['admin_username'] ?? '') ?></span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid py-4 px-4">
