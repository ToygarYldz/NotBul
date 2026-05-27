<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/ratings.php';
require_once __DIR__ . '/includes/admin_notifications.php';
require_once __DIR__ . '/includes/user_notifications.php';
require_once __DIR__ . '/includes/admin_suggested_actions.php';

$adminUser = requireAdminUser($pdo);
$csrfToken = adminCsrfToken('admin_panel');

function adminRedirect(string $section = ''): void
{
    $location = 'admin.php';
    if ($section !== '') {
        $location .= '#' . $section;
    }

    header('Location: ' . $location);
    exit;
}

function adminFormatNumber(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function adminFormatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.') . ' GB';
}

function adminDate(?string $dateValue): string
{
    if ($dateValue === null || trim($dateValue) === '') {
        return '-';
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return '-';
    }

    return date('d.m.Y H:i', $timestamp);
}

function adminNoteStatus(array $note): array
{
    if (!empty($note['deleted_at'])) {
        return ['label' => 'Arşivde', 'class' => 'bg-secondary'];
    }

    if (($note['upload_status'] ?? '') === 'ready' && ($note['scan_status'] ?? '') === 'clean') {
        return ['label' => 'Yayında', 'class' => 'bg-success'];
    }

    if (($note['upload_status'] ?? '') === 'rejected') {
        return ['label' => 'Reddedildi', 'class' => 'bg-danger'];
    }

    if (($note['scan_status'] ?? '') === 'infected') {
        return ['label' => 'Enfekte', 'class' => 'bg-danger'];
    }

    return ['label' => 'Beklemede', 'class' => 'bg-warning text-dark'];
}

function adminFullName(array $user): string
{
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));

    return $name !== '' ? $name : '-';
}

function adminCopyButton(string $value, string $label, bool $iconOnly = true): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $class = $iconOnly
        ? 'btn btn-sm btn-outline-secondary admin-copy-btn admin-copy-btn-icon'
        : 'btn btn-sm btn-outline-secondary admin-copy-btn';
    $content = '<i class="fa-regular fa-copy" aria-hidden="true"></i>';

    if ($iconOnly) {
        $content .= '<span class="visually-hidden">' . $safeLabel . '</span>';
    } else {
        $content .= '<span>' . $safeLabel . '</span>';
    }

    return '<button type="button" class="' . $class . '" data-copy-value="' . $safeValue . '" data-copy-label="' . $safeLabel . '" title="' . $safeLabel . '" aria-label="' . $safeLabel . '">' . $content . '</button>';
}

function adminEmailList(array $users, ?string $role = null): string
{
    $emails = [];
    $seen = [];

    foreach ($users as $user) {
        if ($role !== null && (string)($user['role'] ?? '') !== $role) {
            continue;
        }

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $key = mb_strtolower($email, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }

        $emails[] = $email;
        $seen[$key] = true;
    }

    return implode(', ', $emails);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $redirectSection = match ($action) {
        'update_admin_notifications' => 'settings',
        'delete_stale_unverified_users', 'dismiss_suggested_action' => 'suggested-actions',
        'delete_note' => 'notes',
        'delete_comment' => 'comments',
        default => 'users',
    };

    if (!adminValidateCsrfToken('admin_panel', $requestToken)) {
        adminSetFlash('danger', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        adminRedirect($redirectSection);
    }

    try {
        if ($action === 'update_admin_notifications') {
            $enabled = (string)($_POST['admin_email_notifications'] ?? '0') === '1' ? 1 : 0;
            $adminActionUserNotifications = (string)($_POST['admin_action_user_notifications'] ?? '0') === '1' ? 1 : 0;
            $adminSuggestedActionNotifications = (string)($_POST['admin_suggested_action_notifications'] ?? '0') === '1' ? 1 : 0;

            $updateStmt = $pdo->prepare("
                UPDATE users
                SET admin_email_notifications = :enabled,
                    admin_action_user_notifications = :admin_action_user_notifications,
                    admin_suggested_action_notifications = :admin_suggested_action_notifications
                WHERE id = :id
                  AND role = 'admin'
                LIMIT 1
            ");
            $updateStmt->execute([
                'enabled' => $enabled,
                'admin_action_user_notifications' => $adminActionUserNotifications,
                'admin_suggested_action_notifications' => $adminSuggestedActionNotifications,
                'id' => (int)$adminUser['id'],
            ]);

            $adminUser['admin_email_notifications'] = $enabled;
            $adminUser['admin_action_user_notifications'] = $adminActionUserNotifications;
            $adminUser['admin_suggested_action_notifications'] = $adminSuggestedActionNotifications;
            adminSetFlash('success', 'Admin bildirim ayarları güncellendi.');
            adminRedirect('settings');
        }

        if ($action === 'dismiss_suggested_action') {
            $actionKey = (string)($_POST['action_key'] ?? '');
            adminDismissSuggestedAction($pdo, $actionKey);
            adminSetFlash('success', 'Önerilen eylem 7 gün ertelendi.');
            adminRedirect('suggested-actions');
        }

        if ($action === 'delete_stale_unverified_users') {
            $candidateCount = adminStaleUnverifiedUserCount($pdo);
            $deletedCount = adminDeleteStaleUnverifiedUsers($pdo);

            if ($deletedCount > 0) {
                sendAdminNotification($pdo, 'Önerilen eylem uygulandı', 'Doğrulanmamış eski ve içeriksiz kullanıcı hesapları admin onayıyla silindi.', [
                    'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                    'İşlem öncesi aday sayısı' => $candidateCount,
                    'Silinen hesap sayısı' => $deletedCount,
                ], [
                    'Kullanıcı Yönetimi' => adminNotificationUrl('admin.php#users'),
                ]);
                adminSetFlash('success', adminFormatNumber($deletedCount) . ' doğrulanmamış eski hesap kaldırıldı.');
            } else {
                adminSetFlash('warning', 'Silinecek uygun hesap bulunamadı. Öneri güncellenmiş olabilir.');
            }

            adminRedirect('suggested-actions');
        }

        if ($action === 'update_user_role') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $nextRole = (string)($_POST['role'] ?? '');

            if ($targetUserId <= 0 || !in_array($nextRole, ['user', 'admin'], true)) {
                adminSetFlash('danger', 'Geçersiz kullanıcı rolü isteği.');
                adminRedirect('users');
            }

            $targetStmt = $pdo->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE id = :id LIMIT 1");
            $targetStmt->execute(['id' => $targetUserId]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser) {
                adminSetFlash('danger', 'Kullanıcı bulunamadı.');
                adminRedirect('users');
            }

            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if (($targetUser['role'] ?? 'user') === 'admin' && $nextRole === 'user' && $adminCount <= 1) {
                adminSetFlash('warning', 'Sistemdeki son admin panelden kullanıcıya çevrilemez. Gerekirse veritabanından manuel değişiklik yapabilirsiniz.');
                adminRedirect('users');
            }

            $updateStmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id LIMIT 1");
            $updateStmt->execute([
                'role' => $nextRole,
                'id' => $targetUserId,
            ]);

            if ((string)($targetUser['role'] ?? 'user') !== $nextRole) {
                sendAdminNotification($pdo, 'Kullanıcı rolü değişti', 'Admin panelinden bir kullanıcının rolü güncellendi.', [
                    'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                    'Kullanıcı' => adminNotificationUserLabel($targetUser) . ' (#' . (int)$targetUser['id'] . ')',
                    'Önceki rol' => (string)($targetUser['role'] ?? 'user'),
                    'Yeni rol' => $nextRole,
                ], [
                    'Kullanıcı Yönetimi' => adminNotificationUrl('admin.php#users'),
                ]);
                sendAdminActionUserNotification($adminUser, $targetUser, 'Hesap rolünüz güncellendi', 'Not Bul hesabınızın rolü bir admin tarafından güncellendi.', [
                    'Önceki rol' => (string)($targetUser['role'] ?? 'user'),
                    'Yeni rol' => $nextRole,
                ], [
                    'Profilim' => userNotificationUrl('profile.php'),
                ]);
            }

            if ($targetUserId === (int)$adminUser['id']) {
                $_SESSION['role'] = $nextRole;
            }

            adminSetFlash('success', 'Kullanıcı rolü güncellendi.');
            adminRedirect('users');
        }

        if ($action === 'update_user_verified') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $verified = (int)($_POST['verified'] ?? -1);

            if ($targetUserId <= 0 || !in_array($verified, [0, 1], true)) {
                adminSetFlash('danger', 'Geçersiz doğrulama isteği.');
                adminRedirect('users');
            }

            $targetStmt = $pdo->prepare("SELECT id, first_name, last_name, email, verified FROM users WHERE id = :id LIMIT 1");
            $targetStmt->execute(['id' => $targetUserId]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser) {
                adminSetFlash('danger', 'Kullanıcı bulunamadı.');
                adminRedirect('users');
            }

            if ($verified === 1) {
                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET verified = 1,
                        verified_at = COALESCE(verified_at, NOW()),
                        email_verification_token = NULL,
                        email_verification_token_expires_at = NULL
                    WHERE id = :id
                    LIMIT 1
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET verified = 0,
                        verified_at = NULL
                    WHERE id = :id
                    LIMIT 1
                ");
            }

            $updateStmt->execute(['id' => $targetUserId]);

            if ((int)($targetUser['verified'] ?? 0) !== $verified) {
                sendAdminNotification($pdo, 'Kullanıcı doğrulama durumu değişti', 'Admin panelinden bir kullanıcının e-posta doğrulama durumu güncellendi.', [
                    'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                    'Kullanıcı' => adminNotificationUserLabel($targetUser) . ' (#' . (int)$targetUser['id'] . ')',
                    'Önceki durum' => (int)$targetUser['verified'] === 1 ? 'Doğrulanmış' : 'Doğrulanmamış',
                    'Yeni durum' => $verified === 1 ? 'Doğrulanmış' : 'Doğrulanmamış',
                ], [
                    'Kullanıcı Yönetimi' => adminNotificationUrl('admin.php#users'),
                ]);
                sendAdminActionUserNotification($adminUser, $targetUser, 'Hesap doğrulama durumunuz güncellendi', 'Not Bul hesabınızın doğrulama durumu bir admin tarafından güncellendi.', [
                    'Önceki durum' => (int)$targetUser['verified'] === 1 ? 'Doğrulanmış' : 'Doğrulanmamış',
                    'Yeni durum' => $verified === 1 ? 'Doğrulanmış' : 'Doğrulanmamış',
                ], [
                    'Profilim' => userNotificationUrl('profile.php'),
                ]);
            }

            adminSetFlash('success', 'Kullanıcı doğrulama durumu güncellendi.');
            adminRedirect('users');
        }

        if ($action === 'delete_user') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);

            if ($targetUserId <= 0) {
                adminSetFlash('danger', 'Geçersiz kullanıcı silme isteği.');
                adminRedirect('users');
            }

            $targetStmt = $pdo->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE id = :id LIMIT 1");
            $targetStmt->execute(['id' => $targetUserId]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser) {
                adminSetFlash('danger', 'Silinecek kullanıcı bulunamadı.');
                adminRedirect('users');
            }

            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if (($targetUser['role'] ?? 'user') === 'admin' && $adminCount <= 1) {
                adminSetFlash('warning', 'Sistemdeki son admin panelden silinemez. Gerekirse veritabanından manuel değişiklik yapabilirsiniz.');
                adminRedirect('users');
            }

            $notesStmt = $pdo->prepare("SELECT * FROM notes WHERE user_id = :uid");
            $notesStmt->execute(['uid' => $targetUserId]);
            $notesToDelete = $notesStmt->fetchAll();

            $commentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM note_comments WHERE user_id = :uid");
            $commentCountStmt->execute(['uid' => $targetUserId]);
            $commentCount = (int)$commentCountStmt->fetchColumn();

            $pdo->beginTransaction();
            $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = :id LIMIT 1");
            $deleteStmt->execute(['id' => $targetUserId]);

            if ($deleteStmt->rowCount() < 1) {
                throw new RuntimeException('Admin user delete affected no rows.');
            }

            $pdo->commit();

            $fileWarnings = deleteNotesStorageFiles($notesToDelete);
            foreach ($fileWarnings as $warning) {
                error_log('admin delete user file warning: ' . $warning);
            }

            sendAdminNotification($pdo, 'Kullanıcı silindi', 'Admin panelinden bir kullanıcı ve ilişkili kayıtları kalıcı olarak silindi.', [
                'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                'Silinen kullanıcı' => adminNotificationUserLabel($targetUser) . ' (#' . (int)$targetUser['id'] . ')',
                'Rol' => (string)($targetUser['role'] ?? 'user'),
                'Silinen not sayısı' => count($notesToDelete),
                'Kullanıcının yorum sayısı' => $commentCount,
                'Dosya uyarıları' => empty($fileWarnings) ? 'Yok' : implode("\n", $fileWarnings),
            ], [
                'Kullanıcı Yönetimi' => adminNotificationUrl('admin.php#users'),
            ]);
            sendAdminActionUserNotification($adminUser, $targetUser, 'Hesabınız silindi', 'Not Bul hesabınız bir admin tarafından kalıcı olarak silindi.', [
                'İşlem' => 'Admin hesap silme',
                'Silinen not sayısı' => count($notesToDelete),
                'Kullanıcının yorum sayısı' => $commentCount,
            ]);

            if ($targetUserId === (int)$adminUser['id']) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();

                header('Location: login.php?account_deleted=1');
                exit;
            }

            if (!empty($fileWarnings)) {
                adminSetFlash('warning', 'Kullanıcı silindi; bazı not dosyaları kaldırılamadı. Sunucu loglarını kontrol edin.');
            } else {
                adminSetFlash('success', 'Kullanıcı ve ilişkili kayıtları kalıcı olarak silindi.');
            }
            adminRedirect('users');
        }

        if ($action === 'delete_note') {
            $noteId = (int)($_POST['note_id'] ?? 0);

            if ($noteId <= 0) {
                adminSetFlash('danger', 'Geçersiz not silme isteği.');
                adminRedirect('notes');
            }

            $noteStmt = $pdo->prepare("
                SELECT n.*, u.first_name, u.last_name, u.email
                FROM notes n
                JOIN users u ON u.id = n.user_id
                WHERE n.id = :id
                LIMIT 1
            ");
            $noteStmt->execute(['id' => $noteId]);
            $note = $noteStmt->fetch();

            if (!$note) {
                adminSetFlash('danger', 'Silinecek not bulunamadı.');
                adminRedirect('notes');
            }

            $deleteStmt = $pdo->prepare("DELETE FROM notes WHERE id = :id LIMIT 1");
            $deleteStmt->execute(['id' => $noteId]);

            $fileWarning = deleteNoteStorageFile($note);
            if ($fileWarning !== null) {
                adminSetFlash('warning', $fileWarning);
            } else {
                adminSetFlash('success', 'Not kalıcı olarak silindi.');
            }

            sendAdminNotification($pdo, 'Not silindi', 'Admin panelinden bir not kalıcı olarak silindi.', [
                'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                'Not' => (string)$note['title'] . ' (#' . (int)$note['id'] . ')',
                'Yükleyen' => adminNotificationUserLabel($note) . ' (#' . (int)$note['user_id'] . ')',
                'Ders' => (string)($note['course'] ?? '-'),
                'Konu' => (string)($note['topic'] ?? '-'),
                'Dosya' => (string)$note['original_filename'],
                'Dosya uyarısı' => $fileWarning ?? 'Yok',
            ], [
                'Not Yönetimi' => adminNotificationUrl('admin.php#notes'),
            ]);
            sendAdminActionUserNotification($adminUser, $note, 'Notunuz silindi', 'Yüklediğiniz bir not admin tarafından kalıcı olarak silindi.', [
                'Not' => (string)$note['title'],
                'Ders' => (string)($note['course'] ?? '-'),
                'Konu' => (string)($note['topic'] ?? '-'),
                'Dosya' => (string)$note['original_filename'],
            ], [
                'Profilim' => userNotificationUrl('profile.php'),
            ]);
            adminRedirect('notes');
        }

        if ($action === 'delete_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);

            if ($commentId <= 0) {
                adminSetFlash('danger', 'Geçersiz yorum silme isteği.');
                adminRedirect('comments');
            }

            $commentStmt = $pdo->prepare("
                SELECT
                    nc.id,
                    nc.note_id,
                    nc.user_id,
                    nc.rating,
                    nc.comment,
                    nc.created_at,
                    n.title AS note_title,
                    n.course AS note_course,
                    u.first_name,
                    u.last_name,
                    u.email
                FROM note_comments nc
                JOIN notes n ON n.id = nc.note_id
                JOIN users u ON u.id = nc.user_id
                WHERE nc.id = :id
                LIMIT 1
            ");
            $commentStmt->execute(['id' => $commentId]);
            $comment = $commentStmt->fetch();

            if (!$comment) {
                adminSetFlash('danger', 'Silinecek yorum bulunamadı.');
                adminRedirect('comments');
            }

            $deleteStmt = $pdo->prepare("DELETE FROM note_comments WHERE id = :id LIMIT 1");
            $deleteStmt->execute(['id' => $commentId]);

            if ($deleteStmt->rowCount() < 1) {
                adminSetFlash('danger', 'Silinecek yorum bulunamadı.');
                adminRedirect('comments');
            }

            adminSetFlash('success', 'Yorum kalıcı olarak silindi.');
            sendAdminNotification($pdo, 'Yorum silindi', 'Admin panelinden bir yorum kalıcı olarak silindi.', [
                'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                'Yorum' => '#' . (int)$comment['id'],
                'Not' => (string)$comment['note_title'] . ' (#' . (int)$comment['note_id'] . ')',
                'Yazan' => adminNotificationUserLabel($comment) . ' (#' . (int)$comment['user_id'] . ')',
                'Puan' => (int)$comment['rating'] . '/5',
                'Yorum metni' => (string)$comment['comment'],
            ], [
                'Yorum Yönetimi' => adminNotificationUrl('admin.php#comments'),
            ]);
            sendAdminActionUserNotification($adminUser, $comment, 'Yorumunuz silindi', 'Bir nota yaptığınız yorum admin tarafından kalıcı olarak silindi.', [
                'Not' => (string)$comment['note_title'],
                'Puan' => (int)$comment['rating'] . '/5',
                'Yorum' => (string)$comment['comment'],
            ], [
                'Profilim' => userNotificationUrl('profile.php#comments'),
            ]);
            adminRedirect('comments');
        }

        adminSetFlash('danger', 'Bilinmeyen admin işlemi.');
        adminRedirect();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('admin action error: ' . $e->getMessage());
        adminSetFlash('danger', 'İşlem sırasında beklenmeyen bir hata oluştu.');
        adminRedirect($redirectSection);
    }
}

$userStats = $pdo->query("
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END), 0) AS verified_users,
        COALESCE(SUM(CASE WHEN verified = 0 THEN 1 ELSE 0 END), 0) AS unverified_users,
        COALESCE(SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END), 0) AS admin_users
    FROM users
")->fetch();

$noteStats = $pdo->query("
    SELECT
        COUNT(*) AS total_notes,
        COALESCE(SUM(CASE WHEN deleted_at IS NULL AND upload_status = 'ready' AND scan_status = 'clean' THEN 1 ELSE 0 END), 0) AS live_notes,
        COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS archived_notes,
        COALESCE(SUM(CASE WHEN deleted_at IS NULL AND (upload_status = 'pending' OR scan_status = 'pending') THEN 1 ELSE 0 END), 0) AS pending_notes,
        COALESCE(SUM(CASE WHEN deleted_at IS NULL AND upload_status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected_notes,
        COALESCE(SUM(download_count), 0) AS total_downloads,
        COALESCE(SUM(file_size), 0) AS total_file_size,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS uploads_7d
    FROM notes
")->fetch();

$commentStats = $pdo->query("
    SELECT COUNT(*) AS total_comments
    FROM note_comments
")->fetch();

$adminCount = (int)($userStats['admin_users'] ?? 0);

$usersStmt = $pdo->query("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.verified,
        u.role,
        u.admin_email_notifications,
        u.created_at,
        u.verified_at,
        COALESCE(ns.note_count, 0) AS note_count,
        COALESCE(ns.active_note_count, 0) AS active_note_count,
        COALESCE(ns.download_count, 0) AS download_count
    FROM users u
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS note_count,
            COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS active_note_count,
            COALESCE(SUM(download_count), 0) AS download_count
        FROM notes
        GROUP BY user_id
    ) ns ON ns.user_id = u.id
    ORDER BY u.created_at DESC, u.id DESC
");
$users = $usersStmt->fetchAll();
$userEmailCopyValue = adminEmailList($users);
$adminEmailCopyValue = adminEmailList($users, 'admin');
$isAdminNotificationsEnabled = (int)($adminUser['admin_email_notifications'] ?? 0) === 1;
$isAdminActionUserNotificationsEnabled = (int)($adminUser['admin_action_user_notifications'] ?? 0) === 1;
$isAdminSuggestedActionNotificationsEnabled = adminSuggestedActionNotificationPreference($pdo, (int)$adminUser['id']);
$suggestedAction = adminSyncSuggestedActions($pdo);

$notesStmt = $pdo->query("
    SELECT
        n.id,
        n.user_id,
        n.title,
        n.course,
        n.topic,
        n.original_filename,
        n.file_size,
        n.download_count,
        n.upload_status,
        n.scan_status,
        n.created_at,
        n.deleted_at,
        u.first_name,
        u.last_name,
        u.email
    FROM notes n
    JOIN users u ON u.id = n.user_id
    ORDER BY n.created_at DESC, n.id DESC
");
$notes = $notesStmt->fetchAll();

$topNotesStmt = $pdo->query("
    SELECT id, title, course, download_count, created_at
    FROM notes
    WHERE deleted_at IS NULL
    ORDER BY download_count DESC, created_at DESC
    LIMIT 5
");
$topNotes = $topNotesStmt->fetchAll();

$commentsStmt = $pdo->query("
    SELECT
        nc.id,
        nc.note_id,
        nc.user_id,
        nc.rating,
        nc.comment,
        nc.created_at,
        n.title AS note_title,
        n.course AS note_course,
        n.deleted_at AS note_deleted_at,
        u.first_name,
        u.last_name,
        u.email
    FROM note_comments nc
    JOIN notes n ON n.id = nc.note_id
    JOIN users u ON u.id = nc.user_id
    ORDER BY nc.created_at DESC, nc.id DESC
");
$comments = $commentsStmt->fetchAll();

$flash = adminGetFlash();

$pageTitle = 'Not Bul | Admin Paneli';
$pageKey = 'admin';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h1 class="section-title mb-1">Admin Paneli</h1>
                <p class="mb-0 text-secondary">Hoş geldin, <?= htmlspecialchars((string)$adminUser['first_name'], ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-primary" href="#settings"><i class="fa-solid fa-sliders me-1"></i>Admin Ayarları</a>
                <a class="btn btn-sm btn-outline-primary" href="#suggested-actions"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Önerilen Eylemler</a>
                <a class="btn btn-sm btn-outline-primary" href="#users"><i class="fa-solid fa-users me-1"></i>Kullanıcılar</a>
                <a class="btn btn-sm btn-outline-primary" href="#notes"><i class="fa-solid fa-file-lines me-1"></i>Notlar</a>
                <a class="btn btn-sm btn-outline-primary" href="#comments"><i class="fa-solid fa-comments me-1"></i>Yorumlar</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="alert">
                <?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="admin-stat-grid">
            <div class="admin-stat-card">
                <span>Kullanıcı</span>
                <strong><?= adminFormatNumber((int)($userStats['total_users'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Doğrulanmış</span>
                <strong><?= adminFormatNumber((int)($userStats['verified_users'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Doğrulanmamış</span>
                <strong><?= adminFormatNumber((int)($userStats['unverified_users'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Admin</span>
                <strong><?= adminFormatNumber($adminCount) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Toplam Not</span>
                <strong><?= adminFormatNumber((int)($noteStats['total_notes'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Toplam Yorum</span>
                <strong><?= adminFormatNumber((int)($commentStats['total_comments'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Yayında</span>
                <strong><?= adminFormatNumber((int)($noteStats['live_notes'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Arşivde</span>
                <strong><?= adminFormatNumber((int)($noteStats['archived_notes'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Beklemede</span>
                <strong><?= adminFormatNumber((int)($noteStats['pending_notes'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Reddedildi</span>
                <strong><?= adminFormatNumber((int)($noteStats['rejected_notes'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Toplam İndirme</span>
                <strong><?= adminFormatNumber((int)($noteStats['total_downloads'] ?? 0)) ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Dosya Boyutu</span>
                <strong><?= htmlspecialchars(adminFormatFileSize((int)($noteStats['total_file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div class="admin-stat-card">
                <span>Son 7 Gün</span>
                <strong><?= adminFormatNumber((int)($noteStats['uploads_7d'] ?? 0)) ?></strong>
            </div>
        </div>

        <div id="settings" class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h2 class="h4 mb-1">Admin Ayarları</h2>
                    <p class="mb-0 text-secondary">Kişisel admin bildirimleri şu hesaba gönderilir: <?= htmlspecialchars((string)$adminUser['email'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <form method="POST" action="admin.php#settings" class="admin-settings-form">
                    <input type="hidden" name="action" value="update_admin_notifications">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="admin_email_notifications" value="0">
                    <input type="hidden" name="admin_action_user_notifications" value="0">
                    <input type="hidden" name="admin_suggested_action_notifications" value="0">
                    <div class="form-check form-switch admin-notify-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="adminEmailNotifications"
                            name="admin_email_notifications"
                            value="1"
                            <?= $isAdminNotificationsEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="adminEmailNotifications">
                            Kişisel mail bildirimi
                        </label>
                    </div>
                    <div class="form-check form-switch admin-notify-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="adminActionUserNotifications"
                            name="admin_action_user_notifications"
                            value="1"
                            <?= $isAdminActionUserNotificationsEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="adminActionUserNotifications">
                            Kullanıcı ile ilgili yaptığım değişiklikler kullanıcıya bildirilsin
                        </label>
                    </div>
                    <div class="form-check form-switch admin-notify-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="adminSuggestedActionNotifications"
                            name="admin_suggested_action_notifications"
                            value="1"
                            <?= $isAdminSuggestedActionNotificationsEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="adminSuggestedActionNotifications">
                            Önerilen eylem bildirimleri
                        </label>
                    </div>
                    <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                </form>
            </div>
        </div>

        <div id="suggested-actions" class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <h2 class="h4 mb-1">Önerilen Eylemler</h2>
                    <p class="mb-0 text-secondary">Admin onayı gerektiren bakım önerileri.</p>
                </div>
                <?php if ($suggestedAction): ?>
                    <span class="badge bg-warning text-dark"><?= adminFormatNumber((int)$suggestedAction['candidate_count']) ?> aday</span>
                <?php endif; ?>
            </div>

            <?php if (!$suggestedAction): ?>
                <div class="empty-state">Şu anda önerilen eylem bulunmuyor.</div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                    <div>
                        <h3 class="h5 mb-1">Doğrulanmamış eski hesapları temizle</h3>
                        <p class="mb-0 text-secondary">
                            <?= (int)$suggestedAction['threshold_hours'] ?>+ saattir doğrulanmamış, normal kullanıcı rolünde olan ve not/yorum içermeyen hesaplar kaldırılabilir.
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <form
                            method="POST"
                            action="admin.php#suggested-actions"
                            onsubmit="return confirm('<?= adminFormatNumber((int)$suggestedAction['candidate_count']) ?> uygun hesap kalıcı olarak silinecek. Devam edilsin mi?') && confirm('İkinci onay: Bu işlem geri alınamaz. Uygun hesapları silmek istediğinizden emin misiniz?');"
                        >
                            <input type="hidden" name="action" value="delete_stale_unverified_users">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Hesapları kaldır</button>
                        </form>
                        <form method="POST" action="admin.php#suggested-actions">
                            <input type="hidden" name="action" value="dismiss_suggested_action">
                            <input type="hidden" name="action_key" value="<?= htmlspecialchars(ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">7 gün ertele</button>
                        </form>
                    </div>
                </div>

                <?php if (!empty($suggestedAction['candidates'])): ?>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Kullanıcı</th>
                                    <th>E-posta</th>
                                    <th>Kayıt</th>
                                    <th>Zaman</th>
                                    <th>Doğrulama linki</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suggestedAction['candidates'] as $candidate): ?>
                                    <?php $candidateName = adminFullName($candidate); ?>
                                    <tr>
                                        <td><?= (int)$candidate['id'] ?></td>
                                        <td>
                                            <span class="admin-copy-line">
                                                <strong><?= htmlspecialchars($candidateName, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?= adminCopyButton($candidateName, 'Aday kullanıcı adını kopyala') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="admin-copy-line">
                                                <span><?= htmlspecialchars((string)$candidate['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?= adminCopyButton((string)$candidate['email'], 'Aday e-postasını kopyala') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(adminDate((string)$candidate['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= adminFormatNumber((int)$candidate['age_hours']) ?> saat</td>
                                        <td><?= htmlspecialchars(adminDate((string)($candidate['email_verification_token_expires_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ((int)$suggestedAction['candidate_count'] > count($suggestedAction['candidates'])): ?>
                        <p class="small text-secondary mt-2 mb-0">
                            İlk <?= adminFormatNumber(count($suggestedAction['candidates'])) ?> aday gösteriliyor; işlem tüm uygun adaylara uygulanır.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h4 mb-0">En Çok İndirilen Notlar</h2>
                <span class="text-secondary small">Aktif ve arşivlenmemiş notlar</span>
            </div>
            <?php if (empty($topNotes)): ?>
                <div class="empty-state">Henüz indirilen not bulunmuyor.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Not</th>
                                <th>Ders</th>
                                <th>İndirme</th>
                                <th>Yüklenme</th>
                                <th class="text-end">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topNotes as $note): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$note['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($note['course'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= adminFormatNumber((int)$note['download_count']) ?></td>
                                    <td><?= htmlspecialchars(adminDate((string)$note['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="admin-note-edit.php?id=<?= (int)$note['id'] ?>">Düzenle</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="users" class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h4 mb-0">Kullanıcı Yönetimi</h2>
                <div class="d-flex align-items-center justify-content-end flex-wrap gap-2">
                    <?= adminCopyButton($userEmailCopyValue, 'Tüm kullanıcı e-postalarını kopyala', false) ?>
                    <?= adminCopyButton($adminEmailCopyValue, 'Admin e-postalarını kopyala', false) ?>
                    <span class="text-secondary small"><?= adminFormatNumber(count($users)) ?> kullanıcı</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kullanıcı</th>
                            <th>E-posta</th>
                            <th>Durum</th>
                            <th>Not</th>
                            <th>İndirme</th>
                            <th>Kayıt</th>
                            <th>Rol</th>
                            <th class="text-end">Doğrulama</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $isVerified = (int)$user['verified'] === 1;
                                $isLastAdmin = (string)$user['role'] === 'admin' && $adminCount <= 1;
                                $userFullName = adminFullName($user);
                            ?>
                            <tr>
                                <td><?= (int)$user['id'] ?></td>
                                <td>
                                    <span class="admin-copy-line">
                                        <strong><?= htmlspecialchars($userFullName, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?= adminCopyButton($userFullName, 'Kullanıcı adını kopyala') ?>
                                    </span>
                                    <?php if ((int)$user['id'] === (int)$adminUser['id']): ?>
                                        <span class="badge text-bg-light ms-1">Sen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-copy-line">
                                        <span><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?= adminCopyButton((string)$user['email'], 'E-postayı kopyala') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isVerified): ?>
                                        <span class="badge bg-success">Doğrulanmış</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Doğrulanmamış</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= adminFormatNumber((int)$user['note_count']) ?>
                                    <span class="text-secondary small">(<?= adminFormatNumber((int)$user['active_note_count']) ?> aktif)</span>
                                </td>
                                <td><?= adminFormatNumber((int)$user['download_count']) ?></td>
                                <td><?= htmlspecialchars(adminDate((string)$user['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <form method="POST" action="admin.php#users" class="admin-inline-form">
                                        <input type="hidden" name="action" value="update_user_role">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <select class="form-select form-select-sm admin-role-select" name="role" <?= $isLastAdmin ? 'disabled' : '' ?>>
                                            <option value="user" <?= (string)$user['role'] === 'user' ? 'selected' : '' ?>>Kullanıcı</option>
                                            <option value="admin" <?= (string)$user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary" type="submit" <?= $isLastAdmin ? 'disabled' : '' ?>>Kaydet</button>
                                        <?php if ($isLastAdmin): ?>
                                            <span class="badge text-bg-light">Son admin</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                        <form method="POST" action="admin.php#users" class="d-inline-block">
                                            <input type="hidden" name="action" value="update_user_verified">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <input type="hidden" name="verified" value="<?= $isVerified ? 0 : 1 ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="btn btn-sm <?= $isVerified ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit">
                                                <?= $isVerified ? 'Doğrulamayı Kaldır' : 'Doğrula' ?>
                                            </button>
                                        </form>
                                        <form
                                            method="POST"
                                            action="admin.php#users"
                                            class="d-inline-block"
                                            onsubmit="return confirm('Bu kullanıcı kalıcı olarak silinecek. Yüklediği tüm notlar, bu notlara yapılan yorumlar ve yaptığı yorumlar da silinir. Devam edilsin mi?') && confirm('İkinci onay: Bu işlemin geri dönüşü yok. Kullanıcıyı silmek istediğinizden emin misiniz?');"
                                        >
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" <?= $isLastAdmin ? 'disabled' : '' ?>>
                                                Sil
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="notes" class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h4 mb-0">Not Yönetimi</h2>
                <span class="text-secondary small"><?= adminFormatNumber(count($notes)) ?> not</span>
            </div>
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Not</th>
                            <th>Kullanıcı</th>
                            <th>Durum</th>
                            <th>Dosya</th>
                            <th>İndirme</th>
                            <th>Yüklenme</th>
                            <th class="text-end">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notes as $note): ?>
                            <?php
                                $status = adminNoteStatus($note);
                                $noteUploaderName = adminFullName($note);
                            ?>
                            <tr>
                                <td><?= (int)$note['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$note['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="text-secondary small">
                                        <?= htmlspecialchars((string)($note['course'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($note['topic'])): ?>
                                            / <?= htmlspecialchars((string)$note['topic'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="admin-copy-line">
                                        <span><?= htmlspecialchars($noteUploaderName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?= adminCopyButton($noteUploaderName, 'Yükleyen adını kopyala') ?>
                                    </span>
                                    <div class="text-secondary small admin-copy-line">
                                        <span><?= htmlspecialchars((string)$note['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?= adminCopyButton((string)$note['email'], 'Yükleyen e-postasını kopyala') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($status['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <div class="text-secondary small">
                                        <?= htmlspecialchars((string)$note['upload_status'], ENT_QUOTES, 'UTF-8') ?> /
                                        <?= htmlspecialchars((string)$note['scan_status'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td>
                                    <span title="<?= htmlspecialchars((string)$note['original_filename'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(adminFormatFileSize((int)$note['file_size']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><?= adminFormatNumber((int)$note['download_count']) ?></td>
                                <td><?= htmlspecialchars(adminDate((string)$note['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                        <a class="btn btn-sm btn-outline-primary" href="admin-note-edit.php?id=<?= (int)$note['id'] ?>">Düzenle</a>
                                        <?php if (empty($note['deleted_at']) && (string)$note['upload_status'] === 'ready' && (string)$note['scan_status'] === 'clean'): ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="note-detail.php?id=<?= (int)$note['id'] ?>">Görüntüle</a>
                                        <?php endif; ?>
                                        <form method="POST" action="admin.php#notes" class="d-inline-block">
                                            <input type="hidden" name="action" value="delete_note">
                                            <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <button
                                                class="btn btn-sm btn-outline-danger"
                                                type="submit"
                                                onclick="return confirm('Bu not kalıcı olarak silinecek. Devam edilsin mi?');"
                                            >
                                                Sil
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="comments" class="panel-card mt-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h4 mb-0">Yorum Yönetimi</h2>
                <span class="text-secondary small"><?= adminFormatNumber(count($comments)) ?> yorum</span>
            </div>
            <?php if (empty($comments)): ?>
                <div class="empty-state">Henüz yorum bulunmuyor.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Not</th>
                                <th>Yazan</th>
                                <th>Puan</th>
                                <th>Yorum</th>
                                <th>Tarih</th>
                                <th class="text-end">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comments as $comment): ?>
                                <?php $commentAuthorName = adminFullName($comment); ?>
                                <tr>
                                    <td><?= (int)$comment['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$comment['note_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="text-secondary small">
                                            Not #<?= (int)$comment['note_id'] ?>
                                            <?php if (!empty($comment['note_course'])): ?>
                                                / <?= htmlspecialchars((string)$comment['note_course'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                            <?php if (!empty($comment['note_deleted_at'])): ?>
                                                / Arşivde
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="admin-copy-line">
                                            <span><?= htmlspecialchars($commentAuthorName, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?= adminCopyButton($commentAuthorName, 'Yazan adını kopyala') ?>
                                        </span>
                                        <div class="text-secondary small">
                                            Kullanıcı #<?= (int)$comment['user_id'] ?> /
                                            <span class="admin-copy-line admin-copy-line-inline">
                                                <span><?= htmlspecialchars((string)$comment['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?= adminCopyButton((string)$comment['email'], 'Yazan e-postasını kopyala') ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= renderRatingStars((int)$comment['rating']) ?></td>
                                    <td class="admin-comment-cell"><?= nl2br(htmlspecialchars((string)$comment['comment'], ENT_QUOTES, 'UTF-8')) ?></td>
                                    <td><?= htmlspecialchars(adminDate((string)$comment['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-sm btn-outline-primary" href="admin-comment-edit.php?id=<?= (int)$comment['id'] ?>">Düzenle</a>
                                            <?php if (empty($comment['note_deleted_at'])): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="note-detail.php?id=<?= (int)$comment['note_id'] ?>#comments">Notu Gör</a>
                                            <?php endif; ?>
                                            <form method="POST" action="admin.php#comments" class="d-inline-block">
                                                <input type="hidden" name="action" value="delete_comment">
                                                <input type="hidden" name="comment_id" value="<?= (int)$comment['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <button
                                                    class="btn btn-sm btn-outline-danger"
                                                    type="submit"
                                                    onclick="return confirm('Bu yorum kalıcı olarak silinecek. Devam edilsin mi?');"
                                                >
                                                    Sil
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
