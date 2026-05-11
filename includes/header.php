<?php
declare(strict_types=1);
@session_start();

$pageTitle = $pageTitle ?? 'Not Bul';
$pageKey = $pageKey ?? 'home';
$navItems = [
    ['key' => 'search', 'label' => 'Ders Notu Bul', 'href' => 'search.php'],
    ['key' => 'upload', 'label' => 'Not Yükle', 'href' => 'upload.php'],
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/icons/favicon.svg">
    <link rel="shortcut icon" href="assets/icons/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body data-page="<?= htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8'); ?>">
<header class="site-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light py-2">
            <a class="navbar-brand brand-mark" href="index.php">
                <i class="fa-solid fa-book-open-reader brand-icon" aria-hidden="true"></i>
                <span>Not Bul</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Navigasyonu Aç">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2 me-lg-2">
                    <?php foreach ($navItems as $item): ?>
                        <li class="nav-item">
                            <a class="nav-link top-link <?= $pageKey === $item['key'] ? 'active' : ''; ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="d-flex gap-2 auth-actions">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" id="userMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-user"></i> <span><?= htmlspecialchars($_SESSION['first_name']) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenuButton">
                                <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                                    <li><a class="dropdown-item" href="admin.php"><i class="fa-solid fa-gauge-high ms-1 me-2 text-primary"></i>Admin Paneli</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-id-card ms-1 me-2 text-primary"></i>Profilim</a></li>
                                <li><a class="dropdown-item" href="profile_edit.php"><i class="fa-solid fa-user-pen ms-1 me-2 text-primary"></i>Profili Düzenle</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket ms-1 me-2"></i>Çıkış Yap</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-primary" href="login.php">Giriş Yap</a>
                        <a class="btn btn-sm btn-primary" href="register.php">Kayıt Ol</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </div>
</header>
