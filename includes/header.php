<?php
declare(strict_types=1);
@session_start();

$pageTitle = $pageTitle ?? 'Not Bul';
$pageKey = $pageKey ?? 'home';

require_once __DIR__ . '/env.php';

if (!function_exists('notbul_meta_text')) {
    function notbul_meta_text(?string $value, int $maxLength = 180): string
    {
        $text = trim(strip_tags((string)$value));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            return rtrim(mb_substr($text, 0, $maxLength - 3)) . '...';
        }

        return $text;
    }
}

if (!function_exists('notbul_base_url')) {
    function notbul_base_url(): string
    {
        $configuredUrl = trim((string)(envValue('SITE_URL') ?? envValue('APP_URL') ?? ''));
        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );

        return ($isHttps ? 'https' : 'http') . '://' . $host;
    }
}

if (!function_exists('notbul_absolute_url')) {
    function notbul_absolute_url(?string $url, string $baseUrl): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if ($baseUrl === '') {
            return $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
}

$siteName = 'Not Bul';
$defaultDescription = 'Üniversite ders notlarını bul, incele ve güvenli şekilde paylaş.';
$baseUrl = notbul_base_url();
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$currentUrl = $requestUri !== '' ? notbul_absolute_url($requestUri, $baseUrl) : $baseUrl;
$metaTitle = notbul_meta_text($metaTitle ?? $pageTitle, 70);
$metaDescription = notbul_meta_text($metaDescription ?? $defaultDescription, 180);
$metaType = $metaType ?? 'website';
$metaUrl = notbul_absolute_url($metaUrl ?? $currentUrl, $baseUrl);
$metaImage = notbul_absolute_url($metaImage ?? 'assets/icons/apple-touch-icon.png', $baseUrl);
$metaImageAlt = notbul_meta_text($metaImageAlt ?? $siteName, 120);
$canonicalUrl = notbul_absolute_url($canonicalUrl ?? $metaUrl, $baseUrl);

$navItems = [
    ['key' => 'search', 'label' => 'Ders Notu Bul', 'href' => 'search.php'],
    ['key' => 'upload', 'label' => 'Not Yükle', 'href' => 'upload.php'],
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#eef3f8" id="themeColorMeta">
    <meta name="apple-mobile-web-app-status-bar-style" content="default" id="appleStatusBarMeta">
    <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($canonicalUrl !== ''): ?>
        <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta property="og:locale" content="tr_TR">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="<?= htmlspecialchars($metaType, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?= htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($metaUrl !== ''): ?>
        <meta property="og:url" content="<?= htmlspecialchars($metaUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($metaImage, ENT_QUOTES, 'UTF-8'); ?>">
        <meta property="og:image:alt" content="<?= htmlspecialchars($metaImageAlt, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= htmlspecialchars($metaImage, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <script>
        (() => {
            const root = document.documentElement;
            const themeColorMeta = document.getElementById('themeColorMeta');
            const appleStatusBarMeta = document.getElementById('appleStatusBarMeta');
            const themeChrome = {
                light: {
                    color: '#eef3f8',
                    appleStatusBar: 'default'
                },
                dark: {
                    color: '#10151f',
                    appleStatusBar: 'black'
                }
            };

            const setTheme = (theme) => {
                const chrome = themeChrome[theme] || themeChrome.light;
                root.dataset.theme = theme;
                root.dataset.bsTheme = theme;

                if (themeColorMeta) {
                    themeColorMeta.setAttribute('content', chrome.color);
                }

                if (appleStatusBarMeta) {
                    appleStatusBarMeta.setAttribute('content', chrome.appleStatusBar);
                }
            };

            if (!('matchMedia' in window)) {
                setTheme('light');
                return;
            }

            const themeQuery = window.matchMedia('(prefers-color-scheme: dark)');
            const syncTheme = () => {
                setTheme(themeQuery.matches ? 'dark' : 'light');
            };

            syncTheme();

            if (typeof themeQuery.addEventListener === 'function') {
                themeQuery.addEventListener('change', syncTheme);
            } else if (typeof themeQuery.addListener === 'function') {
                themeQuery.addListener(syncTheme);
            }
        })();
    </script>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/icons/favicon.svg">
    <link rel="shortcut icon" href="assets/icons/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body data-page="<?= htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8'); ?>">
<header class="site-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg py-2">
            <a class="navbar-brand brand-mark" href="index.php">
                <i class="fa-solid fa-book-open-reader brand-icon" aria-hidden="true"></i>
                <span>Not Bul</span>
            </a>
            <div class="mobile-quick-actions d-lg-none" aria-label="Hızlı işlemler">
                <a class="header-action-btn <?= $pageKey === 'search' ? 'active' : ''; ?>" href="search.php" title="Ders Notu Bul" aria-label="Ders Notu Bul">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a class="header-action-btn <?= $pageKey === 'upload' ? 'active' : ''; ?>" href="upload.php" title="Not Yükle" aria-label="Not Yükle">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                    </a>
                    <a class="header-action-btn <?= in_array($pageKey, ['profile', 'profile_edit'], true) ? 'active' : ''; ?>" href="profile.php" title="Profilim" aria-label="Profilim">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                    </a>
                    <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                        <a class="header-action-btn <?= $pageKey === 'admin' ? 'active' : ''; ?>" href="admin.php" title="Admin Paneli" aria-label="Admin Paneli">
                            <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                    <a class="header-action-btn header-action-danger" href="logout.php" title="Çıkış Yap" aria-label="Çıkış Yap">
                        <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <a class="header-action-btn <?= $pageKey === 'login' ? 'active' : ''; ?>" href="login.php" title="Giriş Yap" aria-label="Giriş Yap">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                    </a>
                    <a class="header-action-btn <?= $pageKey === 'register' ? 'active' : ''; ?>" href="register.php" title="Kayıt Ol" aria-label="Kayıt Ol">
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
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
                        <?php
                            $currentFirstName = trim((string)($_SESSION['first_name'] ?? ''));
                            $displayFirstName = $currentFirstName !== '' ? $currentFirstName : 'Hesabım';
                            $userInitial = mb_strtoupper(mb_substr($displayFirstName, 0, 1, 'UTF-8'), 'UTF-8');
                        ?>
                        <div class="user-quickbar" aria-label="Kullanıcı işlemleri">
                            <a class="user-chip <?= $pageKey === 'profile' ? 'active' : ''; ?>" href="profile.php" aria-label="Profilim">
                                <span class="user-avatar" aria-hidden="true"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="user-chip-copy">
                                    <span class="user-chip-label">Profilim</span>
                                    <span class="user-chip-name"><?= htmlspecialchars($displayFirstName, ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </a>
                            <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                                <a class="header-action-btn <?= $pageKey === 'admin' ? 'active' : ''; ?>" href="admin.php" title="Admin Paneli" aria-label="Admin Paneli">
                                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <a class="header-action-btn" href="profile_edit.php" title="Profili Düzenle" aria-label="Profili Düzenle">
                                <i class="fa-solid fa-user-pen" aria-hidden="true"></i>
                            </a>
                            <a class="header-action-btn header-action-danger" href="logout.php" title="Çıkış Yap" aria-label="Çıkış Yap">
                                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                            </a>
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
