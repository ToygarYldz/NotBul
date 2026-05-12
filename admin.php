<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/ratings.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $redirectSection = match ($action) {
        'delete_note' => 'notes',
        'delete_comment' => 'comments',
        default => 'users',
    };

    if (!adminValidateCsrfToken('admin_panel', $requestToken)) {
        adminSetFlash('danger', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        adminRedirect($redirectSection);
    }

    try {
        if ($action === 'update_user_role') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $nextRole = (string)($_POST['role'] ?? '');

            if ($targetUserId <= 0 || !in_array($nextRole, ['user', 'admin'], true)) {
                adminSetFlash('danger', 'Geçersiz kullanıcı rolü isteği.');
                adminRedirect('users');
            }

            $targetStmt = $pdo->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = :id LIMIT 1");
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

            $noteStmt = $pdo->prepare("SELECT * FROM notes WHERE id = :id LIMIT 1");
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
            adminRedirect('notes');
        }

        if ($action === 'delete_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);

            if ($commentId <= 0) {
                adminSetFlash('danger', 'Geçersiz yorum silme isteği.');
                adminRedirect('comments');
            }

            $deleteStmt = $pdo->prepare("DELETE FROM note_comments WHERE id = :id LIMIT 1");
            $deleteStmt->execute(['id' => $commentId]);

            if ($deleteStmt->rowCount() < 1) {
                adminSetFlash('danger', 'Silinecek yorum bulunamadı.');
                adminRedirect('comments');
            }

            adminSetFlash('success', 'Yorum kalıcı olarak silindi.');
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
                <span class="text-secondary small"><?= adminFormatNumber(count($users)) ?> kullanıcı</span>
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
                            ?>
                            <tr>
                                <td><?= (int)$user['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars(trim((string)$user['first_name'] . ' ' . (string)$user['last_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ((int)$user['id'] === (int)$adminUser['id']): ?>
                                        <span class="badge text-bg-light ms-1">Sen</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></td>
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
                            <?php $status = adminNoteStatus($note); ?>
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
                                    <?= htmlspecialchars(trim((string)$note['first_name'] . ' ' . (string)$note['last_name']), ENT_QUOTES, 'UTF-8') ?>
                                    <div class="text-secondary small"><?= htmlspecialchars((string)$note['email'], ENT_QUOTES, 'UTF-8') ?></div>
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
                                        <?= htmlspecialchars(trim((string)$comment['first_name'] . ' ' . (string)$comment['last_name']), ENT_QUOTES, 'UTF-8') ?>
                                        <div class="text-secondary small">
                                            Kullanıcı #<?= (int)$comment['user_id'] ?> /
                                            <?= htmlspecialchars((string)$comment['email'], ENT_QUOTES, 'UTF-8') ?>
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
