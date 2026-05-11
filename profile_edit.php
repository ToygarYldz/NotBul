<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
@session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

$profileEditToken = (string)($_SESSION['csrf_token_profile_edit'] ?? '');
if ($profileEditToken === '') {
    try {
        $profileEditToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $profileEditToken = hash('sha256', session_id() . (string)microtime(true));
    }
    $_SESSION['csrf_token_profile_edit'] = $profileEditToken;
}

$stmt = $pdo->prepare("SELECT id, first_name, last_name, email, password FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_profile');
    $requestToken = (string)($_POST['csrf_token'] ?? '');

    if ($requestToken === '' || !hash_equals($profileEditToken, $requestToken)) {
        $error = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        if (empty($firstName) || empty($lastName)) {
            $error = 'Ad ve Soyad alanları boş bırakılamaz.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET first_name = :fname, last_name = :lname WHERE id = :uid");
                $stmt->execute([
                    'fname' => $firstName,
                    'lname' => $lastName,
                    'uid' => $userId
                ]);

                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $user['first_name'] = $firstName;
                $user['last_name'] = $lastName;
                $success = 'Profil bilgileriniz başarıyla güncellendi.';
            } catch (PDOException $e) {
                error_log('profile_edit update profile error: ' . $e->getMessage());
                $error = 'Güncelleme sırasında beklenmeyen bir hata oluştu.';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $passwordConfirm === '') {
            $error = 'Şifre değiştirmek için tüm alanları doldurun.';
        } elseif (!password_verify($currentPassword, (string)$user['password'])) {
            $error = 'Mevcut şifreniz hatalı.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Yeni şifre en az 8 karakter olmalıdır.';
        } elseif ($newPassword !== $passwordConfirm) {
            $error = 'Yeni şifre ve şifre tekrarı eşleşmiyor.';
        } else {
            try {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET password = :password,
                        password_reset_token = NULL,
                        password_reset_token_expires_at = NULL
                    WHERE id = :uid
                    LIMIT 1
                ");
                $stmt->execute([
                    'password' => $passwordHash,
                    'uid' => $userId
                ]);

                $user['password'] = $passwordHash;
                $success = 'Şifreniz başarıyla güncellendi.';
            } catch (PDOException $e) {
                error_log('profile_edit change password error: ' . $e->getMessage());
                $error = 'Şifre güncellenirken beklenmeyen bir hata oluştu.';
            }
        }
    } else {
        $error = 'Geçersiz profil işlemi.';
    }
}

$pageTitle = 'Not Bul | Profili Düzenle';
$pageKey = 'profile_edit';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="panel-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0">Profili Düzenle</h1>
                        <a href="profile.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Geri Dön</a>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form action="profile_edit.php" method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileEditToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="first_name" class="form-label">Ad</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars((string)$user['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="last_name" class="form-label">Soyad</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars((string)$user['last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-4">
                            <label for="email" class="form-label">E-posta</label>
                            <input type="email" class="form-control text-muted" id="email" value="<?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?>" readonly disabled>
                            <div class="form-text">E-posta adresi değiştirilemez.</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Değişiklikleri Kaydet</button>
                        </div>
                    </form>
                </div>

                <div class="panel-card mt-4">
                    <h2 class="h4 mb-3">Şifre Değiştir</h2>
                    <form action="profile_edit.php" method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileEditToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Mevcut Şifre</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Yeni Şifre</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
                            <div class="form-text">En az 8 karakter olmalıdır.</div>
                        </div>
                        <div class="mb-4">
                            <label for="password_confirm" class="form-label">Yeni Şifre Tekrar</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-outline-primary"><i class="fa-solid fa-key"></i> Şifreyi Güncelle</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
