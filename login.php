<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
@session_start(); // ensure session is started if not done in header yet

$error = '';
$success = '';

if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = 'Şifren başarıyla güncellendi. Yeni şifrenle giriş yapabilirsin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, password, verified, role FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ((int) $user['verified'] !== 1) {
                $error = 'Hesabın henüz doğrulanmamış. Lütfen e-posta adresine gönderilen doğrulama bağlantısını kullan.';
            } else {
                // Şifre doğru, oturum aç
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['role'] = $user['role'] ?? 'user';

                header('Location: index.php');
                exit;
            }
        } else {
            $error = 'E-posta veya şifre hatalı.';
        }
    }
}

$pageTitle = 'Not Bul | Giriş Yap';
$pageKey = 'login';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="panel-card mt-5">
                    <h1 class="h3 mb-4 text-center">Giriş Yap</h1>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta Adresi</label>
                            <input type="email" class="form-control" id="email" name="email" required placeholder="ornek@email.com">
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Şifre</label>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="********">
                            <div class="text-end mt-2">
                                <a href="forgot-password.php" class="small text-decoration-none">Şifremi Unuttum</a>
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Giriş Yap</button>
                        </div>
                        <div class="mt-3 text-center">
                            <span class="text-secondary">Hesabınız yok mu?</span> <a href="register.php" class="text-decoration-none">Kayıt Ol</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
