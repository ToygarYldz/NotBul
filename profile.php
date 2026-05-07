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

// Get note count
$stmtNotes = $pdo->prepare("SELECT COUNT(*) as note_count FROM notes WHERE user_id = :uid");
$stmtNotes->execute(['uid' => $userId]);
$noteCount = (int) $stmtNotes->fetch()['note_count'];

$stmtMyNotes = $pdo->prepare("
    SELECT id, title, course, topic, original_filename, file_size, download_count, upload_status, scan_status, created_at
    FROM notes
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 12
");
$stmtMyNotes->execute(['uid' => $userId]);
$myNotes = $stmtMyNotes->fetchAll();

$pageTitle = 'Not Bul | Profilim';
$pageKey = 'profile';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block mt-5">
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
                                    <?= $noteCount ?> adet not yüklendi.
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
                                            <div class="small text-secondary my-note-file text-truncate" title="<?= htmlspecialchars((string)$note['original_filename']) ?>">
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
                                            <?php if ($isVisible): ?>
                                                <a href="note-detail.php?id=<?= (int)$note['id'] ?>" class="btn btn-sm btn-primary">Detay</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Henüz Yayında Değil</button>
                                            <?php endif; ?>
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
