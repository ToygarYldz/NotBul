<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_notifications.php';
require_once __DIR__ . '/includes/user_notifications.php';

$adminUser = requireAdminUser($pdo);
$csrfToken = adminCsrfToken('admin_comment_edit');

function adminCommentRedirectToList(): void
{
    header('Location: admin.php#comments');
    exit;
}

function adminCommentRedirectToComment(int $commentId): void
{
    header('Location: admin-comment-edit.php?id=' . $commentId);
    exit;
}

function adminCommentDate(?string $dateValue): string
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

$commentId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (int)($_POST['id'] ?? 0)
    : (int)($_GET['id'] ?? 0);

if ($commentId <= 0) {
    adminSetFlash('danger', 'Düzenlenecek yorum bulunamadı.');
    adminCommentRedirectToList();
}

$localFlash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_comment');
    $requestToken = (string)($_POST['csrf_token'] ?? '');

    if (!adminValidateCsrfToken('admin_comment_edit', $requestToken)) {
        $localFlash = [
            'type' => 'danger',
            'message' => 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.',
        ];
    } elseif ($action === 'update_comment') {
        $rating = (int)($_POST['rating'] ?? 0);
        $commentText = trim((string)($_POST['comment'] ?? ''));
        $errors = [];

        if ($rating < 1 || $rating > 5) {
            $errors[] = 'Puan 1 ile 5 arasında olmalıdır.';
        }

        if ($commentText === '') {
            $errors[] = 'Yorum metni boş olamaz.';
        } elseif (mb_strlen($commentText) > 5000) {
            $errors[] = 'Yorum metni 5000 karakteri geçemez.';
        }

        if (empty($errors)) {
            try {
                $beforeStmt = $pdo->prepare("
                    SELECT
                        nc.id,
                        nc.note_id,
                        nc.user_id,
                        nc.rating,
                        nc.comment,
                        nc.created_at,
                        n.title AS note_title,
                        n.course AS note_course,
                        n.topic AS note_topic,
                        n.deleted_at AS note_deleted_at,
                        u.first_name,
                        u.last_name,
                        u.email
                    FROM note_comments nc
                    JOIN notes n ON n.id = nc.note_id
                    JOIN users u ON u.id = nc.user_id
                    WHERE nc.id = :id
                    LIMIT 1
                ");
                $beforeStmt->execute(['id' => $commentId]);
                $commentBefore = $beforeStmt->fetch();

                if (!$commentBefore) {
                    adminSetFlash('danger', 'Düzenlenecek yorum bulunamadı.');
                    adminCommentRedirectToList();
                }

                $updateStmt = $pdo->prepare("
                    UPDATE note_comments
                    SET rating = :rating,
                        comment = :comment
                    WHERE id = :id
                    LIMIT 1
                ");
                $updateStmt->execute([
                    'rating' => $rating,
                    'comment' => $commentText,
                    'id' => $commentId,
                ]);

                if ($updateStmt->rowCount() < 1) {
                    adminSetFlash('warning', 'Yorumda değişiklik yapılmadı veya yorum bulunamadı.');
                } else {
                    sendAdminNotification($pdo, 'Yorum düzenlendi', 'Admin panelinden bir yorum güncellendi.', [
                        'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                        'Yorum' => '#' . (int)$commentBefore['id'],
                        'Not' => (string)$commentBefore['note_title'] . ' (#' . (int)$commentBefore['note_id'] . ')',
                        'Yazan' => adminNotificationUserLabel($commentBefore) . ' (#' . (int)$commentBefore['user_id'] . ')',
                        'Puan değişimi' => (int)$commentBefore['rating'] . '/5' . "\n=> " . $rating . '/5',
                        'Yorum değişimi' => (string)$commentBefore['comment'] . "\n=> " . $commentText,
                    ], [
                        'Yorumu Düzenle' => adminNotificationUrl('admin-comment-edit.php?id=' . $commentId),
                        'Yorum Yönetimi' => adminNotificationUrl('admin.php#comments'),
                    ]);
                    sendAdminActionUserNotification($adminUser, $commentBefore, 'Yorumunuz admin tarafından güncellendi', 'Bir nota yaptığınız yorum admin tarafından güncellendi.', [
                        'Not' => (string)$commentBefore['note_title'],
                        'Puan değişimi' => (int)$commentBefore['rating'] . '/5' . "\n=> " . $rating . '/5',
                        'Yorum değişimi' => (string)$commentBefore['comment'] . "\n=> " . $commentText,
                    ], [
                        'Profilim' => userNotificationUrl('profile.php#comments'),
                    ]);
                    adminSetFlash('success', 'Yorum güncellendi.');
                }
                adminCommentRedirectToComment($commentId);
            } catch (Throwable $e) {
                error_log('admin comment edit update error: ' . $e->getMessage());
                $localFlash = [
                    'type' => 'danger',
                    'message' => 'Yorum güncellenirken beklenmeyen bir hata oluştu.',
                ];
            }
        } else {
            $localFlash = [
                'type' => 'danger',
                'message' => implode(' ', $errors),
            ];
        }
    } elseif ($action === 'delete_comment') {
        try {
            $beforeStmt = $pdo->prepare("
                SELECT
                    nc.id,
                    nc.note_id,
                    nc.user_id,
                    nc.rating,
                    nc.comment,
                    nc.created_at,
                    n.title AS note_title,
                    n.course AS note_course,
                    n.topic AS note_topic,
                    n.deleted_at AS note_deleted_at,
                    u.first_name,
                    u.last_name,
                    u.email
                FROM note_comments nc
                JOIN notes n ON n.id = nc.note_id
                JOIN users u ON u.id = nc.user_id
                WHERE nc.id = :id
                LIMIT 1
            ");
            $beforeStmt->execute(['id' => $commentId]);
            $commentBefore = $beforeStmt->fetch();

            if (!$commentBefore) {
                adminSetFlash('danger', 'Silinecek yorum bulunamadı.');
                adminCommentRedirectToList();
            }

            $deleteStmt = $pdo->prepare("DELETE FROM note_comments WHERE id = :id LIMIT 1");
            $deleteStmt->execute(['id' => $commentId]);

            if ($deleteStmt->rowCount() < 1) {
                adminSetFlash('danger', 'Silinecek yorum bulunamadı.');
            } else {
                sendAdminNotification($pdo, 'Yorum silindi', 'Admin yorum düzenleme sayfasından bir yorum kalıcı olarak silindi.', [
                    'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                    'Yorum' => '#' . (int)$commentBefore['id'],
                    'Not' => (string)$commentBefore['note_title'] . ' (#' . (int)$commentBefore['note_id'] . ')',
                    'Yazan' => adminNotificationUserLabel($commentBefore) . ' (#' . (int)$commentBefore['user_id'] . ')',
                    'Puan' => (int)$commentBefore['rating'] . '/5',
                    'Yorum metni' => (string)$commentBefore['comment'],
                ], [
                    'Yorum Yönetimi' => adminNotificationUrl('admin.php#comments'),
                ]);
                sendAdminActionUserNotification($adminUser, $commentBefore, 'Yorumunuz silindi', 'Bir nota yaptığınız yorum admin tarafından kalıcı olarak silindi.', [
                    'Not' => (string)$commentBefore['note_title'],
                    'Puan' => (int)$commentBefore['rating'] . '/5',
                    'Yorum metni' => (string)$commentBefore['comment'],
                ], [
                    'Profilim' => userNotificationUrl('profile.php#comments'),
                ]);
                adminSetFlash('success', 'Yorum kalıcı olarak silindi.');
            }
            adminCommentRedirectToList();
        } catch (Throwable $e) {
            error_log('admin comment edit delete error: ' . $e->getMessage());
            $localFlash = [
                'type' => 'danger',
                'message' => 'Yorum silinirken beklenmeyen bir hata oluştu.',
            ];
        }
    } else {
        $localFlash = [
            'type' => 'danger',
            'message' => 'Geçersiz yorum işlemi.',
        ];
    }
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
        n.topic AS note_topic,
        n.deleted_at AS note_deleted_at,
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
    adminSetFlash('danger', 'Düzenlenecek yorum bulunamadı.');
    adminCommentRedirectToList();
}

$flash = $localFlash ?? adminGetFlash();

$pageTitle = 'Not Bul | Yorum Düzenle';
$pageKey = 'admin';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h1 class="section-title mb-1">Yorum Düzenle</h1>
                <p class="mb-0 text-secondary">Yorum #<?= (int)$comment['id'] ?> / Not #<?= (int)$comment['note_id'] ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="admin.php#comments"><i class="fa-solid fa-arrow-left me-1"></i>Yorum Yönetimi</a>
                <?php if (empty($comment['note_deleted_at'])): ?>
                    <a class="btn btn-sm btn-outline-primary" href="note-detail.php?id=<?= (int)$comment['note_id'] ?>">Notu Gör</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="alert">
                <?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <div class="panel-card">
                    <h2 class="h4 mb-3">Yorum İçeriği</h2>
                    <form method="POST" action="admin-comment-edit.php?id=<?= (int)$comment['id'] ?>">
                        <input type="hidden" name="action" value="update_comment">
                        <input type="hidden" name="id" value="<?= (int)$comment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="mb-3">
                            <label class="form-label" for="commentRating">Puan</label>
                            <select class="form-select" id="commentRating" name="rating" required>
                                <?php for ($rating = 1; $rating <= 5; $rating += 1): ?>
                                    <option value="<?= $rating ?>" <?= (int)$comment['rating'] === $rating ? 'selected' : '' ?>>
                                        <?= $rating ?>/5
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="commentText">Yorum</label>
                            <textarea class="form-control" id="commentText" name="comment" rows="8" maxlength="5000" required><?= htmlspecialchars((string)$comment['comment'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="d-grid gap-2 d-md-flex">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-save me-1"></i>Yorumu Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="panel-card">
                    <h2 class="h4 mb-3">Detaylar</h2>
                    <dl class="admin-meta-list">
                        <dt>Yorum ID</dt>
                        <dd>#<?= (int)$comment['id'] ?></dd>
                        <dt>Not</dt>
                        <dd>
                            #<?= (int)$comment['note_id'] ?> /
                            <?= htmlspecialchars((string)$comment['note_title'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($comment['note_deleted_at'])): ?>
                                <span class="badge bg-secondary ms-1">Arşivde</span>
                            <?php endif; ?>
                        </dd>
                        <dt>Ders / Konu</dt>
                        <dd>
                            <?= htmlspecialchars((string)($comment['note_course'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($comment['note_topic'])): ?>
                                / <?= htmlspecialchars((string)$comment['note_topic'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </dd>
                        <dt>Yazan</dt>
                        <dd>
                            #<?= (int)$comment['user_id'] ?> /
                            <?= htmlspecialchars(trim((string)$comment['first_name'] . ' ' . (string)$comment['last_name']), ENT_QUOTES, 'UTF-8') ?>
                        </dd>
                        <dt>E-posta</dt>
                        <dd><?= htmlspecialchars((string)$comment['email'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt>Oluşturulma</dt>
                        <dd><?= htmlspecialchars(adminCommentDate((string)$comment['created_at']), ENT_QUOTES, 'UTF-8') ?></dd>
                    </dl>

                    <hr>

                    <form
                        method="POST"
                        action="admin-comment-edit.php?id=<?= (int)$comment['id'] ?>"
                        onsubmit="return confirm('Bu yorum kalıcı olarak silinecek. Devam edilsin mi?');"
                    >
                        <input type="hidden" name="action" value="delete_comment">
                        <input type="hidden" name="id" value="<?= (int)$comment['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button class="btn btn-outline-danger w-100" type="submit">
                            <i class="fa-solid fa-trash me-1"></i>Yorumu Sil
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
