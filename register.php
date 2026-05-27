<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/brevo.php';
require_once __DIR__ . '/includes/admin_notifications.php';
require_once __DIR__ . '/includes/registration_security.php';

@session_start();

$error = '';
$success = '';
$turnstileSiteKey = registrationTurnstileSiteKey();
$clientIp = registrationClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $honeypot = trim((string)($_POST['company_website'] ?? ''));
    $formNonce = (string)($_POST['form_nonce'] ?? '');
    $turnstileToken = (string)($_POST['cf-turnstile-response'] ?? '');

    if (!registrationValidateCsrfToken($csrfToken)) {
        $error = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'csrf_failed');
    } elseif ($honeypot !== '') {
        $error = 'Kayıt isteği doğrulanamadı. Lütfen tekrar deneyin.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'honeypot_failed');
    } else {
        $formChallenge = registrationValidateFormChallenge($formNonce);
        if (($formChallenge['ok'] ?? false) !== true) {
            $error = 'Kayıt isteği doğrulanamadı. Lütfen tekrar deneyin.';
            registrationRecordAttempt($pdo, $clientIp, $email, 'timing_failed');
        }
    }

    if ($error === '') {
        $rateLimit = registrationCheckRateLimit($pdo, $clientIp, $email);
        if (($rateLimit['allowed'] ?? false) !== true) {
            $error = (string)($rateLimit['message'] ?? 'Çok fazla kayıt denemesi yapıldı. Lütfen daha sonra tekrar deneyin.');
            registrationRecordAttempt($pdo, $clientIp, $email, 'rate_limited');
        }
    }

    if ($error === '') {
        $turnstileResult = registrationValidateTurnstile($turnstileToken, $clientIp);
        if (($turnstileResult['success'] ?? false) !== true) {
            $error = 'Güvenlik doğrulaması tamamlanamadı. Lütfen tekrar deneyin.';
            registrationRecordAttempt($pdo, $clientIp, $email, 'turnstile_failed');
        }
    }

    if ($error === '' && (empty($firstName) || empty($lastName) || empty($email) || empty($password) || empty($passwordConfirm))) {
        $error = 'Lütfen tüm alanları doldurun.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'validation_failed');
    } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Lütfen geçerli bir e-posta adresi girin.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'validation_failed');
    } elseif ($error === '' && $password !== $passwordConfirm) {
        $error = 'Şifreler uyuşmuyor.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'validation_failed');
    } elseif ($error === '' && strlen($password) < 8) {
        $error = 'Şifre en az 8 karakter olmalıdır.';
        registrationRecordAttempt($pdo, $clientIp, $email, 'validation_failed');
    } elseif ($error === '') {
        try {
            $stmt = $pdo->prepare("SELECT id, verified FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $existingUser = $stmt->fetch();

            if ($existingUser && (int) $existingUser['verified'] === 1) {
                $error = 'Bu e-posta adresi zaten kullanımda.';
                registrationRecordAttempt($pdo, $clientIp, $email, 'duplicate_verified_email');
            } else {
                if ($existingUser) {
                    $emailLimit = registrationCheckVerificationEmailLimit($pdo, $email);
                    if (($emailLimit['allowed'] ?? false) !== true) {
                        $error = (string)($emailLimit['message'] ?? 'Bu e-posta adresi için çok fazla doğrulama bağlantısı istendi.');
                        registrationRecordAttempt($pdo, $clientIp, $email, 'verification_email_limited');
                    }
                }
            }

            if ($error === '') {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $plainToken);
                $tokenExpiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
                $fullName = trim($firstName . ' ' . $lastName);
                $verificationUrl = buildAppBaseUrl() . '/verify-email.php?token=' . urlencode($plainToken);
                $wasExistingRegistration = (bool)$existingUser;
                $registeredUserId = 0;

                $pdo->beginTransaction();

                if ($existingUser) {
                    $registeredUserId = (int)$existingUser['id'];
                    $updateStmt = $pdo->prepare(
                        "UPDATE users
                         SET first_name = :first_name,
                             last_name = :last_name,
                             password = :password,
                             verified = 0,
                             email_verification_token = :token,
                             email_verification_token_expires_at = :expires_at,
                             verified_at = NULL
                         WHERE id = :id"
                    );
                    $updateStmt->execute([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'password' => $hashedPassword,
                        'token' => $tokenHash,
                        'expires_at' => $tokenExpiresAt,
                        'id' => $existingUser['id'],
                    ]);
                } else {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO users
                            (first_name, last_name, email, password, verified, email_verification_token, email_verification_token_expires_at)
                         VALUES
                            (:first_name, :last_name, :email, :password, 0, :token, :expires_at)"
                    );
                    $insertStmt->execute([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'password' => $hashedPassword,
                        'token' => $tokenHash,
                        'expires_at' => $tokenExpiresAt,
                    ]);
                    $registeredUserId = (int)$pdo->lastInsertId();
                }

                sendVerificationEmail($email, $fullName, $verificationUrl);
                $pdo->commit();

                sendAdminNotification($pdo, $wasExistingRegistration ? 'Kayıt yenilendi' : 'Yeni kullanıcı kaydı', $wasExistingRegistration ? 'Doğrulanmamış mevcut bir kullanıcı kaydı yeni bilgilerle yenilendi.' : 'Yeni bir kullanıcı hesabı oluşturuldu ve doğrulama e-postası gönderildi.', [
                    'Kullanıcı' => $fullName . ' <' . $email . '>',
                    'Kullanıcı ID' => $registeredUserId,
                    'Durum' => 'E-posta doğrulaması bekleniyor',
                    'Doğrulama linki geçerlilik süresi' => $tokenExpiresAt,
                ], [
                    'Kullanıcı Yönetimi' => adminNotificationUrl('admin.php#users'),
                ]);

                $success = 'Kayıt alındı. Hesabını aktifleştirmek için e-posta kutuna gönderdiğimiz doğrulama bağlantısını kullan.';
                registrationRecordAttempt($pdo, $clientIp, $email, 'verification_email_sent');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($error === '') {
                $error = 'Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.';
                registrationRecordAttempt($pdo, $clientIp, $email, 'registration_error');
            }

            error_log('registration error: ' . $exception->getMessage());
        }
    }
}

$registrationCsrfToken = registrationCsrfToken();
$formChallenge = registrationIssueFormChallenge();
$submittedFirstName = $success === '' ? (string)($_POST['first_name'] ?? '') : '';
$submittedLastName = $success === '' ? (string)($_POST['last_name'] ?? '') : '';
$submittedEmail = $success === '' ? (string)($_POST['email'] ?? '') : '';

$pageTitle = 'Not Bul | Kayıt Ol';
$pageKey = 'register';
require __DIR__ . '/includes/header.php';
?>
<?php if ($turnstileSiteKey !== ''): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<main class="page-shell">
    <section class="container section-block">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="panel-card mt-5">
                    <h1 class="h3 mb-4 text-center">Hesap Oluştur</h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <?php if ($turnstileSiteKey === ''): ?>
                        <div class="alert alert-warning" role="alert">Kayıt güvenlik doğrulaması şu anda yapılandırılmamış. Lütfen daha sonra tekrar deneyin.</div>
                    <?php endif; ?>

                    <form action="register.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($registrationCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_nonce" value="<?= htmlspecialchars((string)$formChallenge['nonce'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="registration-trap" aria-hidden="true">
                            <label for="companyWebsite">Web sitesi</label>
                            <input type="text" id="companyWebsite" name="company_website" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="firstName" class="form-label">Ad</label>
                                <input type="text" class="form-control" id="firstName" name="first_name" autocomplete="given-name" required placeholder="Adınız" value="<?= htmlspecialchars($submittedFirstName, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="lastName" class="form-label">Soyad</label>
                                <input type="text" class="form-control" id="lastName" name="last_name" autocomplete="family-name" required placeholder="Soyadınız" value="<?= htmlspecialchars($submittedLastName, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-12">
                                <label for="email" class="form-label">E-posta Adresi</label>
                                <input type="email" class="form-control" id="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required placeholder="ornek@email.com" value="<?= htmlspecialchars($submittedEmail, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="password" class="form-label">Şifre</label>
                                <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="8" required placeholder="En az 8 karakter">
                            </div>
                            <div class="col-md-6">
                                <label for="passwordConfirm" class="form-label">Şifre (Tekrar)</label>
                                <input type="password" class="form-control" id="passwordConfirm" name="password_confirm" autocomplete="new-password" minlength="8" required placeholder="Şifrenizi doğrulayın">
                            </div>
                            <?php if ($turnstileSiteKey !== ''): ?>
                                <div class="col-12">
                                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8') ?>" data-action="register" data-theme="auto"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary" <?= $turnstileSiteKey === '' ? 'disabled' : '' ?>>Kayıt Ol</button>
                        </div>
                        <div class="mt-3 text-center">
                            <span class="text-secondary">Zaten hesabınız var mı?</span> <a href="login.php" class="text-decoration-none">Giriş Yap</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
