<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
@session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id, first_name, last_name, email, created_at, verified FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// CSRF token for note actions
$noteActionToken = (string)($_SESSION['csrf_token_profile_note_action'] ?? '');
if ($noteActionToken === '') {
    try {
        $noteActionToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $noteActionToken = hash('sha256', session_id() . (string)microtime(true));
    }
    $_SESSION['csrf_token_profile_note_action'] = $noteActionToken;
}

$noteActionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $noteId = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token_profile_note_action'] ?? '');

    if ($noteId <= 0 || !in_array($action, ['soft_delete_note', 'restore_note'], true)) {
        $noteActionError = 'Geçersiz not işlemi.';
    } elseif ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $noteActionError = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } else {
        try {
            if ($action === 'soft_delete_note') {
                $actionStmt = $pdo->prepare("
                    UPDATE notes
                    SET deleted_at = NOW(),
                        deleted_by = :deleted_by
                    WHERE id = :id
                      AND user_id = :user_id
                      AND deleted_at IS NULL
                    LIMIT 1
                ");
                $actionStmt->execute([
                    'deleted_by' => $userId,
                    'id' => $noteId,
                    'user_id' => $userId
                ]);

                if ($actionStmt->rowCount() > 0) {
                    header('Location: profile.php?note_deleted=1');
                    exit;
                }

                $noteActionError = 'Not silinemedi. Not zaten silinmiş olabilir veya size ait olmayabilir.';
            } else {
                $actionStmt = $pdo->prepare("
                    UPDATE notes
                    SET deleted_at = NULL,
                        deleted_by = NULL
                    WHERE id = :id
                      AND user_id = :user_id
                      AND deleted_at IS NOT NULL
                    LIMIT 1
                ");
                $actionStmt->execute([
                    'id' => $noteId,
                    'user_id' => $userId
                ]);

                if ($actionStmt->rowCount() > 0) {
                    header('Location: profile.php?note_restored=1');
                    exit;
                }

                $noteActionError = 'Not geri alınamadı. Not zaten aktif olabilir veya size ait olmayabilir.';
            }
        } catch (Throwable $e) {
            error_log('profile note action error: ' . $e->getMessage());
            $noteActionError = 'Not işlemi sırasında beklenmeyen bir hata oluştu.';
        }
    }
}

// Active note count
$stmtNotes = $pdo->prepare("SELECT COUNT(*) as note_count FROM notes WHERE user_id = :uid AND deleted_at IS NULL");
$stmtNotes->execute(['uid' => $userId]);
$noteCount = (int) $stmtNotes->fetch()['note_count'];

// Deleted note count
$stmtDeletedCount = $pdo->prepare("SELECT COUNT(*) as deleted_count FROM notes WHERE user_id = :uid AND deleted_at IS NOT NULL");
$stmtDeletedCount->execute(['uid' => $userId]);
$deletedNoteCount = (int) $stmtDeletedCount->fetch()['deleted_count'];

$stmtMyNotes = $pdo->prepare("
    SELECT id, title, course, topic, original_filename, file_size, download_count, upload_status, scan_status, created_at
    FROM notes
    WHERE user_id = :uid
      AND deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 12
");
$stmtMyNotes->execute(['uid' => $userId]);
$myNotes = $stmtMyNotes->fetchAll();

$stmtDeletedNotes = $pdo->prepare("
    SELECT id, title, course, topic, original_filename, file_size, download_count, deleted_at, created_at
    FROM notes
    WHERE user_id = :uid
      AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
    LIMIT 12
");
$stmtDeletedNotes->execute(['uid' => $userId]);
$deletedNotes = $stmtDeletedNotes->fetchAll();

$noteActionSuccess = '';
if (isset($_GET['note_deleted']) && $_GET['note_deleted'] === '1') {
    $noteActionSuccess = 'Not başarıyla arşive alındı. Dilerseniz aşağıdaki "Silinen Notlar" bölümünden geri alabilirsiniz.';
} elseif (isset($_GET['note_restored']) && $_GET['note_restored'] === '1') {
    $noteActionSuccess = 'Not başarıyla geri alındı ve tekrar listelere dahil edildi.';
}

$pageTitle = 'Not Bul | Profilim';
$pageKey = 'profile';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block mt-5">
        <?php if ($noteActionSuccess): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars($noteActionSuccess) ?></div>
        <?php endif; ?>
        <?php if ($noteActionError): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars($noteActionError) ?></div>
        <?php endif; ?>
        <div class="row g-4 align-items-start">
            <div class="col-lg-5">
                <div class="panel-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0">Profil Bilgileri</h1>
                        <a href="profile_edit.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i> Düzenle</a>
                    </div>
                    
                    <div class="card shadow-sm border-0 bg-light">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-sm-4 text-secondary">
                                    <i class="fa-solid fa-user me-2"></i>Ad Soyad
                                </div>
                                <div class="col-sm-8 text-dark fw-medium">
                                    <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                </div>
                            </div>
                            <hr class="text-muted">
                            <div class="row mb-3">
                                <div class="col-sm-4 text-secondary">
                                    <i class="fa-solid fa-envelope me-2"></i>E-posta
                                </div>
                                <div class="col-sm-8 text-dark fw-medium">
                                    <?= htmlspecialchars($user['email']) ?>
                                    <?php if ((int)$user['verified'] === 1): ?>
                                        <span class="badge bg-success ms-2"><i class="fa-solid fa-check-circle"></i> Doğrulanmış</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark ms-2"><i class="fa-solid fa-clock"></i> Doğrulanmamış</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <hr class="text-muted">
                            <div class="row mb-3">
                                <div class="col-sm-4 text-secondary">
                                    <i class="fa-solid fa-calendar-days me-2"></i>Kayıt Tarihi
                                </div>
                                <div class="col-sm-8 text-dark fw-medium">
                                    <?= htmlspecialchars(date('d.m.Y H:i', strtotime($user['created_at']))) ?>
                                </div>
                            </div>
                            <hr class="text-muted">
                            <div class="row">
                                <div class="col-sm-4 text-secondary">
                                    <i class="fa-solid fa-file-lines me-2"></i>Yüklenen Notlar
                                </div>
                                <div class="col-sm-8 text-dark fw-medium">
                                    <?= $noteCount ?> aktif not yüklendi.
                                </div>
                            </div>
                            <hr class="text-muted">
                            <div class="row">
                                <div class="col-sm-4 text-secondary">
                                    <i class="fa-solid fa-box-archive me-2"></i>Arşivlenen Notlar
                                </div>
                                <div class="col-sm-8 text-dark fw-medium">
                                    <?= $deletedNoteCount ?> not arşivde.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="panel-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h3 mb-0">Notlarım</h2>
                        <a href="upload.php" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-upload me-1"></i> Yeni Not Yükle
                        </a>
                    </div>

                    <?php if (empty($myNotes)): ?>
                        <div class="empty-state">
                            Henüz not yüklemediniz. <a href="upload.php" class="text-decoration-none">İlk notunu şimdi yükle</a>.
                        </div>
                    <?php else: ?>
                        <div class="search-results">
                            <?php foreach ($myNotes as $note): ?>
                                <?php
                                    $isVisible = (string)$note['upload_status'] === 'ready' && (string)$note['scan_status'] === 'clean';
                                    $statusText = $isVisible ? 'Yayında' : 'İncelemede';
                                    $statusClass = $isVisible ? 'bg-success' : 'bg-warning text-dark';
                                ?>
                                <article class="result-item">
                                    <div class="my-note-item d-flex justify-content-between align-items-start gap-3">
                                        <div class="my-note-main">
                                            <h3 class="h6 mb-1"><?= htmlspecialchars((string)$note['title']) ?></h3>
                                            <p class="mb-2 text-secondary small">
                                                <?= htmlspecialchars((string)($note['course'] ?? '-')) ?>
                                                <?php if (!empty($note['topic'])): ?>
                                                    • <?= htmlspecialchars((string)$note['topic']) ?>
                                                <?php endif; ?>
                                            </p>
                                            <div class="small text-secondary my-note-file" title="<?= htmlspecialchars((string)$note['original_filename']) ?>">
                                                <?= htmlspecialchars((string)$note['original_filename']) ?>
                                                • <?= number_format(((int)$note['file_size']) / 1024, 1, ',', '.') ?> KB
                                            </div>
                                        </div>

                                        <div class="my-note-side text-end">
                                            <span class="badge <?= htmlspecialchars($statusClass) ?> mb-2"><?= htmlspecialchars($statusText) ?></span>
                                            <div class="small text-secondary mb-2">
                                                <?= (int)$note['download_count'] ?> indirme
                                                <br>
                                                <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string)$note['created_at']))) ?>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 justify-content-end">
                                                <?php if ($isVisible): ?>
                                                    <a href="note-detail.php?id=<?= (int)$note['id'] ?>" class="btn btn-sm btn-primary">Detay</a>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Henüz Yayında Değil</button>
                                                <?php endif; ?>
                                                <form method="POST" action="profile.php" class="d-inline-block">
                                                    <input type="hidden" name="action" value="soft_delete_note">
                                                    <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($noteActionToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Bu notu arşive alıp yayından kaldırmak istediğinize emin misiniz?');"
                                                    >
                                                        Sil
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel-card mt-4">
                    <h2 class="h4 mb-3">Silinen Notlar</h2>

                    <?php if (empty($deletedNotes)): ?>
                        <div class="empty-state">
                            Arşivlenen not bulunmuyor.
                        </div>
                    <?php else: ?>
                        <div class="search-results">
                            <?php foreach ($deletedNotes as $deletedNote): ?>
                                <article class="result-item">
                                    <div class="my-note-item d-flex justify-content-between align-items-start gap-3">
                                        <div class="my-note-main">
                                            <h3 class="h6 mb-1"><?= htmlspecialchars((string)$deletedNote['title']) ?></h3>
                                            <p class="mb-2 text-secondary small">
                                                <?= htmlspecialchars((string)($deletedNote['course'] ?? '-')) ?>
                                                <?php if (!empty($deletedNote['topic'])): ?>
                                                    • <?= htmlspecialchars((string)$deletedNote['topic']) ?>
                                                <?php endif; ?>
                                            </p>
                                            <div class="small text-secondary my-note-file" title="<?= htmlspecialchars((string)$deletedNote['original_filename']) ?>">
                                                <?= htmlspecialchars((string)$deletedNote['original_filename']) ?>
                                                • <?= number_format(((int)$deletedNote['file_size']) / 1024, 1, ',', '.') ?> KB
                                            </div>
                                        </div>

                                        <div class="my-note-side text-end">
                                            <span class="badge bg-secondary mb-2">Arşivde</span>
                                            <div class="small text-secondary mb-2">
                                                Silinme: <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string)$deletedNote['deleted_at']))) ?>
                                            </div>
                                            <form method="POST" action="profile.php" class="d-inline-block">
                                                <input type="hidden" name="action" value="restore_note">
                                                <input type="hidden" name="note_id" value="<?= (int)$deletedNote['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($noteActionToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Geri Al</button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
