<?php
declare(strict_types=1);

function requireAdminUser(PDO $pdo): array
{
    @session_start();

    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, verified, admin_email_notifications, admin_action_user_notifications FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $_SESSION['first_name'] = (string)$user['first_name'];
    $_SESSION['last_name'] = (string)$user['last_name'];
    $_SESSION['role'] = (string)($user['role'] ?? 'user');

    if (($_SESSION['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        $pageTitle = 'Not Bul | Yetkisiz Erişim';
        $pageKey = 'admin';
        require __DIR__ . '/header.php';
        ?>
        <main class="page-shell">
            <section class="container section-block">
                <div class="panel-card">
                    <h1 class="h3 mb-2">Yetkisiz erişim</h1>
                    <p class="mb-0 text-secondary">Bu sayfayı görüntülemek için admin yetkisi gerekiyor.</p>
                </div>
            </section>
        </main>
        <?php
        require __DIR__ . '/footer.php';
        exit;
    }

    return $user;
}

function adminCsrfToken(string $key): string
{
    @session_start();
    $sessionKey = 'csrf_token_' . $key;
    $token = (string)($_SESSION[$sessionKey] ?? '');

    if ($token === '') {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = hash('sha256', session_id() . $key . (string)microtime(true));
        }
        $_SESSION[$sessionKey] = $token;
    }

    return $token;
}

function adminValidateCsrfToken(string $key, string $requestToken): bool
{
    @session_start();
    $sessionToken = (string)($_SESSION['csrf_token_' . $key] ?? '');

    return $sessionToken !== '' && hash_equals($sessionToken, $requestToken);
}

function adminSetFlash(string $type, string $message): void
{
    @session_start();
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function adminGetFlash(): ?array
{
    @session_start();
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : null;
}
