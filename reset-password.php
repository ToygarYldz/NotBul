<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/user_notifications.php';

$error = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = '';
$resetUserId = null;
$tokenStatus = 'invalid';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, password_reset_token_expires_at
         FROM users
         WHERE password_reset_token = :token
         LIMIT 1"
    );
    $stmt->execute(['token' => $tokenHash]);
    $user = $stmt->fetch();

    if ($user) {
        $expiresAtRaw = (string) ($user['password_reset_token_expires_at'] ?? '');
        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiresAtRaw);

        if ($expiresAt instanceof DateTimeImmutable && $expiresAt >= new DateTimeImmutable('now')) {
            $tokenStatus = 'valid';
            $resetUserId = (int) $user['id'];
        } else {
            $tokenStatus = 'expired';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($tokenStatus !== 'valid' || $resetUserId === null) {
        $error = 'Şifre sıfırlama bağlantısı geçersiz veya süresi dolmuş.';
    } elseif ($password === '' || $passwordConfirm === '') {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler uyuşmuyor.';
    } elseif (strlen($password) < 8) {
        $error = 'Şifre en az 8 karakter olmalıdır.';
    } else {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET password = :password,
                     password_reset_token = NULL,
                     password_reset_token_expires_at = NULL
                 WHERE id = :id
                   AND password_reset_token = :token"
            );
            $updateStmt->execute([
                'password' => $hashedPassword,
                'id' => $resetUserId,
                'token' => $tokenHash,
            ]);

            if ($updateStmt->rowCount() === 1) {
                sendUserSecurityNotification($user, 'Şifreniz sıfırlandı', 'Not Bul hesabınızın şifresi sıfırlama bağlantısı kullanılarak güncellendi.', [
                    'İşlem' => 'Şifre sıfırlama',
                    'Zaman' => date('d.m.Y H:i:s'),
                ], [
                    'Giriş Yap' => userNotificationUrl('login.php'),
                ]);

                header('Location: login.php?reset=success');
                exit;
            }

            $error = 'Şifre güncellenemedi. Lütfen tekrar sıfırlama talebi oluştur.';
        } catch (Throwable $exception) {
            $error = 'Şifre güncellenirken bir hata oluştu. Lütfen tekrar dene.';
        }
    }
}

$pageTitle = 'Not Bul | Yeni Şifre Belirle';
$pageKey = 'reset-password';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="panel-card mt-5">
                    <h1 class="h3 mb-3 text-center">Yeni Şifre Belirle</h1>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <?php if ($tokenStatus === 'expired'): ?>
                        <div class="alert alert-warning" role="alert">Bu şifre sıfırlama bağlantısının süresi dolmuş.</div>
                    <?php elseif ($tokenStatus === 'invalid'): ?>
                        <div class="alert alert-warning" role="alert">Geçersiz şifre sıfırlama bağlantısı.</div>
                    <?php endif; ?>

                    <?php if ($tokenStatus === 'valid'): ?>
                        <form action="reset-password.php" method="POST" novalidate>
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mb-3">
                                <label for="password" class="form-label">Yeni Şifre</label>
                                <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="8" required placeholder="En az 8 karakter">
                            </div>
                            <div class="mb-4">
                                <label for="passwordConfirm" class="form-label">Yeni Şifre (Tekrar)</label>
                                <input type="password" class="form-control" id="passwordConfirm" name="password_confirm" autocomplete="new-password" minlength="8" required placeholder="Şifreni tekrar gir">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Şifreyi Güncelle</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="d-grid gap-2">
                            <a href="forgot-password.php" class="btn btn-primary">Yeni Sıfırlama Bağlantısı İste</a>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 text-center">
                        <a href="login.php" class="text-decoration-none">Giriş sayfasına dön</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
