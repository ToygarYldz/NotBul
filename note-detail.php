<?php
declare(strict_types=1);

@session_start();

function resolveDepartmentName(string $departmentId): string
{
    $normalizedId = trim($departmentId);
    if ($normalizedId === '') {
        return '-';
    }

    static $departmentsById = null;

    if ($departmentsById === null) {
        $departmentsById = [];
        $dataPath = __DIR__ . '/assets/data/bolumler.json';

        $json = @file_get_contents($dataPath);
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach (['lisans', 'onlisans'] as $type) {
                    $departments = $decoded[$type] ?? [];
                    if (!is_array($departments)) {
                        continue;
                    }

                    foreach ($departments as $department) {
                        if (!is_array($department)) {
                            continue;
                        }

                        $id = trim((string)($department['id'] ?? ''));
                        $name = trim((string)($department['name'] ?? ''));

                        if ($id !== '' && $name !== '') {
                            $departmentsById[$id] = $name;
                        }
                    }
                }
            }
        }
    }

    return $departmentsById[$normalizedId] ?? $normalizedId;
}

function resolveUniversityName(string $universityId): string
{
    $normalizedId = trim($universityId);
    if ($normalizedId === '') {
        return '-';
    }

    static $universitiesById = null;

    if ($universitiesById === null) {
        $universitiesById = [];
        $dataPath = __DIR__ . '/assets/data/universiteler.json';

        $json = @file_get_contents($dataPath);
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $university) {
                    if (!is_array($university)) {
                        continue;
                    }

                    $id = trim((string)($university['id'] ?? ''));
                    $name = trim((string)($university['name'] ?? ''));

                    if ($id !== '' && $name !== '') {
                        $universitiesById[$id] = $name;
                    }
                }
            }
        }
    }

    return $universitiesById[$normalizedId] ?? $normalizedId;
}

function resolveDepartmentTypeLabel(string $departmentType): string
{
    return match (trim($departmentType)) {
        'lisans' => 'Lisans',
        'onlisans' => 'Önlisans',
        '' => '-',
        default => ucfirst($departmentType),
    };
}

function resolveClassLabel(string $classId): string
{
    $normalizedId = trim($classId);
    if ($normalizedId === '') {
        return '-';
    }

    if (ctype_digit($normalizedId)) {
        return $normalizedId . '. Sınıf';
    }

    return $normalizedId;
}

function formatFileSizeHuman(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
    }

    return number_format($bytes / (1024 * 1024 * 1024), 2, ',', '.') . ' GB';
}

try {
    require_once __DIR__ . '/includes/db.php';
} catch (Throwable $e) {
    error_log('note-detail DB connection error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Şu anda veritabanına bağlanılamıyor. Lütfen daha sonra tekrar deneyin.';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo "HATA: Geçersiz ID ($id)"; 
    exit;
}

$deleteError = '';
$commentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_note') {
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token_note_delete'] ?? '');

    if ($currentUserId <= 0) {
        $deleteError = 'Not silmek için önce giriş yapmalısınız.';
    } elseif ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $deleteError = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } else {
        try {
            $ownerStmt = $pdo->prepare("SELECT id, user_id, deleted_at FROM notes WHERE id = :id");
            $ownerStmt->execute(['id' => $id]);
            $noteToDelete = $ownerStmt->fetch();

            if (!$noteToDelete) {
                header('Location: index.php?error=not_found&id=' . $id);
                exit;
            }

            if ((int)$noteToDelete['user_id'] !== $currentUserId) {
                $deleteError = 'Sadece kendi yüklediğiniz notları silebilirsiniz.';
            } elseif ($noteToDelete['deleted_at'] !== null) {
                header('Location: profile.php?note_deleted=1');
                exit;
            } else {
                $deleteStmt = $pdo->prepare("
                    UPDATE notes
                    SET deleted_at = NOW(),
                        deleted_by = :deleted_by
                    WHERE id = :id
                      AND user_id = :user_id
                      AND deleted_at IS NULL
                    LIMIT 1
                ");
                $deleteStmt->execute([
                    'id' => $id,
                    'deleted_by' => $currentUserId,
                    'user_id' => $currentUserId
                ]);

                if ($deleteStmt->rowCount() < 1) {
                    $deleteError = 'Not arşive alınamadı. Not zaten silinmiş olabilir.';
                } else {
                    header('Location: profile.php?note_deleted=1');
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log('note-detail delete error: ' . $e->getMessage());
            $deleteError = 'Not silinirken beklenmeyen bir hata oluştu.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_comment') {
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $rating = (int)($_POST['rating'] ?? 5);
    $commentText = trim((string)($_POST['comment'] ?? ''));
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token_note_comment'] ?? '');

    if ($currentUserId <= 0) {
        $commentError = 'Yorum yapmak için giriş yapmalısınız.';
    } elseif ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        $commentError = 'Güvenlik doğrulaması başarısız oldu. Lütfen tekrar deneyin.';
    } elseif ($rating < 1 || $rating > 5) {
        $commentError = 'Geçersiz puanlama.';
    } elseif ($commentText === '') {
        $commentError = 'Yorum boş olamaz.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO note_comments (note_id, user_id, rating, comment) VALUES (:note_id, :user_id, :rating, :comment)");
            $stmt->execute([
                'note_id' => $id,
                'user_id' => $currentUserId,
                'rating' => $rating,
                'comment' => $commentText
            ]);
            header("Location: note-detail.php?id=$id&comment_added=1");
            exit;
        } catch (Throwable $e) {
            error_log('note-detail comment error: ' . $e->getMessage());
            $commentError = 'Yorum kaydedilirken bir hata oluştu.';
        }
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT n.*, u.first_name, u.last_name 
        FROM notes n
        JOIN users u ON n.user_id = u.id
        WHERE n.id = :id
          AND n.upload_status = 'ready'
          AND n.scan_status = 'clean'
          AND n.deleted_at IS NULL
    ");
    $stmt->execute(['id' => $id]);
    $note = $stmt->fetch();
} catch (Throwable $e) {
    error_log('note-detail query error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Not bilgisi getirilirken bir sorun oluştu. Lütfen daha sonra tekrar deneyin.';
    exit;
}

if (!$note) {
    header('Location: index.php?error=not_found&id=' . $id);
    exit;
}

$deleteToken = (string)($_SESSION['csrf_token_note_delete'] ?? '');
if ($deleteToken === '') {
    try {
        $deleteToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $deleteToken = hash('sha256', session_id() . (string)microtime(true));
    }
    $_SESSION['csrf_token_note_delete'] = $deleteToken;
}

$commentToken = (string)($_SESSION['csrf_token_note_comment'] ?? '');
if ($commentToken === '') {
    try {
        $commentToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $commentToken = hash('sha256', session_id() . (string)microtime(true));
    }
    $_SESSION['csrf_token_note_comment'] = $commentToken;
}

$comments = [];
try {
    $stmt = $pdo->prepare("
        SELECT nc.*, u.first_name, u.last_name
        FROM note_comments nc
        JOIN users u ON nc.user_id = u.id
        WHERE nc.note_id = :note_id
        ORDER BY nc.created_at DESC
    ");
    $stmt->execute(['note_id' => $id]);
    $comments = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('note-detail fetch comments error: ' . $e->getMessage());
}

$isOwner = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$note['user_id'];
$departmentName = resolveDepartmentName((string)($note['department_id'] ?? ''));
$universityName = resolveUniversityName((string)($note['university_id'] ?? ''));
$departmentTypeLabel = resolveDepartmentTypeLabel((string)($note['department_type'] ?? ''));
$classLabel = resolveClassLabel((string)($note['class_id'] ?? ''));
$courseName = trim((string)($note['course'] ?? ''));
$topicName = trim((string)($note['topic'] ?? ''));
$originalFilename = trim((string)($note['original_filename'] ?? '-'));
$fileExtension = strtoupper(pathinfo($originalFilename, PATHINFO_EXTENSION));
$fileExtension = $fileExtension !== '' ? $fileExtension : '-';
$downloadCount = (int)($note['download_count'] ?? 0);
$fileSizeBytes = (int)($note['file_size'] ?? 0);
$formattedCreatedAt = date('d.m.Y H:i', strtotime((string)$note['created_at']));

$pageTitle = 'Not Bul | ' . htmlspecialchars($note['title']);
$pageKey = 'detail';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <?php if ($deleteError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($deleteError) ?></div>
        <?php endif; ?>
        <p class="text-secondary small mb-3">
            Anasayfa > <?= htmlspecialchars($note['course']) ?> > <?= htmlspecialchars($note['title']) ?>
        </p>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="preview-shell">
                    <div class="preview-toolbar d-flex justify-content-between align-items-center">
                        <strong>Dosya Önizleme</strong>
                        <span class="badge text-bg-info"><?= strtoupper(pathinfo($note['original_filename'], PATHINFO_EXTENSION)) ?> Önizleme</span>
                    </div>
                    <div class="preview-canvas p-0" style="min-height: 500px; background: #f8f9fa;">
                        <?php 
                        $mime = $note['mime_type'];
                        if (strpos($mime, 'pdf') !== false): 
                        ?>
                            <iframe src="view.php?id=<?= $note['id'] ?>#toolbar=0" width="100%" height="600px" style="border: none;"></iframe>
                        <?php elseif (strpos($mime, 'image/') === 0): ?>
                            <img src="view.php?id=<?= $note['id'] ?>" class="img-fluid" alt="<?= htmlspecialchars($note['title']) ?>">
                        <?php else: ?>
                            <div class="p-4 text-center">
                                <p class="mb-2 text-secondary">Bu dosya formatı (<?= strtoupper(pathinfo($note['original_filename'], PATHINFO_EXTENSION)) ?>) tarayıcıda önizleme desteklemiyor.</p>
                                <p class="mb-0 text-secondary">'İndir' butonu ile dosyayı indirebilirsiniz.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <article class="panel-card h-100">
                    <h1 class="section-title mb-2"><?= htmlspecialchars($note['title']) ?></h1>
                    <p class="text-secondary"><?= nl2br(htmlspecialchars($note['description'] ?? 'Açıklama belirtilmedi.')) ?></p>

                    <div class="note-meta-grid">
                        <div><span>Yükleyen</span><strong><?= htmlspecialchars($note['first_name'] . ' ' . $note['last_name']) ?></strong></div>
                        <div><span>Üniversite</span><strong><?= htmlspecialchars($universityName) ?></strong></div>
                        <div><span>Program Türü</span><strong><?= htmlspecialchars($departmentTypeLabel) ?></strong></div>
                        <div><span>Bölüm</span><strong><?= htmlspecialchars($departmentName) ?></strong></div>
                        <div><span>Sınıf</span><strong><?= htmlspecialchars($classLabel) ?></strong></div>
                        <div><span>Ders</span><strong><?= htmlspecialchars($courseName !== '' ? $courseName : '-') ?></strong></div>
                        <div><span>Konu</span><strong><?= htmlspecialchars($topicName !== '' ? $topicName : '-') ?></strong></div>
                        <div><span>İndirme</span><strong><?= number_format($downloadCount, 0, ',', '.') ?></strong></div>
                        <div><span>Dosya Türü</span><strong><?= htmlspecialchars($fileExtension) ?></strong></div>
                        <div><span>Dosya Adı</span><strong><?= htmlspecialchars($originalFilename) ?></strong></div>
                        <div><span>Boyut</span><strong><?= htmlspecialchars(formatFileSizeHuman($fileSizeBytes)) ?></strong></div>
                        <div><span>Yüklenme</span><strong><?= htmlspecialchars($formattedCreatedAt) ?></strong></div>
                    </div>

                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <?php 
                        $tagStr = (string)$note['tags'];
                        $tags = explode(',', $tagStr);
                        foreach ($tags as $tag): 
                            $tag = trim($tag);
                            if ($tag !== ''):
                        ?>
                            <span class="badge rounded-pill text-bg-light">#<?= htmlspecialchars($tag) ?></span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>

                    <div class="mt-4 d-grid gap-2 d-md-flex">
                        <a class="btn btn-primary btn-lg px-4" href="view.php?id=<?= $note['id'] ?>&amp;download=1" download="<?= htmlspecialchars($note['original_filename']) ?>">İndir</a>
                        <a class="btn btn-outline-primary btn-lg" href="search.php?similar_to=<?= (int)$note['id'] ?>">Benzer Notlar</a>
                    </div>
                    <?php if ($isOwner): ?>
                        <form method="POST" action="note-detail.php?id=<?= (int)$note['id'] ?>" class="mt-3">
                            <input type="hidden" name="action" value="delete_note">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($deleteToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button
                                type="submit"
                                class="btn btn-outline-danger"
                                onclick="return confirm('Bu notu arşive alıp yayından kaldırmak istediğinize emin misiniz?');"
                            >
                                Notu Arşivle
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="panel-card">
                    <h2 class="section-title h4 mb-4">Yorumlar</h2>
                    
                    <?php if ($commentError): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($commentError) ?></div>
                    <?php endif; ?>
                    <?php if (isset($_GET['comment_added'])): ?>
                        <div class="alert alert-success">Yorumunuz başarıyla eklendi.</div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <form method="POST" action="note-detail.php?id=<?= $note['id'] ?>" class="mb-4">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($commentToken, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="rating" class="form-label">Değerlendirme</label>
                                    <select name="rating" id="rating" class="form-select" required>
                                        <option value="5">5 - Harika</option>
                                        <option value="4">4 - İyi</option>
                                        <option value="3">3 - Orta</option>
                                        <option value="2">2 - Kötü</option>
                                        <option value="1">1 - Çok Kötü</option>
                                    </select>
                                </div>
                                <div class="col-md-9">
                                    <label for="comment" class="form-label">Yorumunuz</label>
                                    <textarea name="comment" id="comment" rows="3" class="form-control" placeholder="Not hakkında düşünceleriniz..." required></textarea>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary">Yorum Gönder</button>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-info">Yorum yapabilmek için <a href="login.php">giriş yapmalısınız</a>.</div>
                    <?php endif; ?>

                    <div class="comments-list">
                        <?php if (empty($comments)): ?>
                            <p class="text-secondary">Henüz yorum yapılmamış. İlk yorumu siz yapın!</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <article class="comment-item p-3 border rounded mb-3 bg-light">
                                    <header class="d-flex justify-content-between align-items-center mb-2">
                                        <strong><?= htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']) ?></strong>
                                        <span class="text-secondary small">
                                            <?= htmlspecialchars((string)$comment['rating']) ?>/5 | 
                                            <?= date('d.m.Y H:i', strtotime((string)$comment['created_at'])) ?>
                                        </span>
                                    </header>
                                    <p class="mb-0 text-break"><?= nl2br(htmlspecialchars($comment['comment'])) ?></p>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
