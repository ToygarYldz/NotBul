<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/user_notifications.php';

$status = 'danger';
$message = 'Geçersiz doğrulama bağlantısı.';

$token = trim($_GET['token'] ?? '');

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, email_verification_token_expires_at
         FROM users
         WHERE email_verification_token = :token
           AND verified = 0
         LIMIT 1"
    );
    $stmt->execute(['token' => $tokenHash]);
    $user = $stmt->fetch();

    if ($user) {
        $expiresAtRaw = $user['email_verification_token_expires_at'] ?? null;
        $isExpired = false;

        if ($expiresAtRaw !== null && $expiresAtRaw !== '') {
            $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiresAtRaw);
            if ($expiresAt instanceof DateTimeImmutable && $expiresAt < new DateTimeImmutable('now')) {
                $isExpired = true;
            }
        }

        if ($isExpired) {
            $status = 'warning';
            $message = 'Doğrulama bağlantısının süresi dolmuş. Lütfen tekrar kayıt olarak yeni bağlantı iste.';
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET verified = 1,
                     email_verification_token = NULL,
                     email_verification_token_expires_at = NULL,
                     verified_at = NOW()
                 WHERE id = :id
                   AND verified = 0"
            );
            $updateStmt->execute(['id' => $user['id']]);

            if ($updateStmt->rowCount() === 1) {
                $status = 'success';
                $message = 'E-posta adresin başarıyla doğrulandı. Artık giriş yapabilirsin.';
                sendUserSecurityNotification($user, 'E-posta adresiniz doğrulandı', 'Not Bul hesabınızın e-posta adresi başarıyla doğrulandı.', [
                    'İşlem' => 'E-posta doğrulama',
                    'Zaman' => date('d.m.Y H:i:s'),
                ], [
                    'Giriş Yap' => userNotificationUrl('login.php'),
                ]);
            } else {
                $status = 'warning';
                $message = 'Hesap zaten doğrulanmış olabilir. Giriş yapmayı deneyebilirsin.';
            }
        }
    }
}

$pageTitle = 'Not Bul | E-posta Doğrulama';
$pageKey = 'verify-email';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="panel-card mt-5 text-center">
                    <h1 class="h3 mb-4">E-posta Doğrulama</h1>
                    <div class="alert alert-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <a href="login.php" class="btn btn-primary mt-2">Giriş Sayfasına Git</a>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
