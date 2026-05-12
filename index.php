<?php
declare(strict_types=1);

@session_start();

require_once __DIR__ . '/includes/ratings.php';

$latestNotes = [];
$popularNotes = [];
$notesPayload = [];
$dbUnavailable = false;

try {
    require_once __DIR__ . '/includes/db.php';
} catch (Throwable $e) {
    $dbUnavailable = true;
}

if (!$dbUnavailable) {
    try {
        $stmt = $pdo->query("
            SELECT
                n.id,
                n.title,
                n.description,
                n.university_id,
                n.department_type,
                n.department_id,
                n.class_id,
                n.course,
                n.topic,
                n.tags,
                n.original_filename,
                n.mime_type,
                n.download_count AS download_count,
                n.created_at,
                rs.rating_average,
                COALESCE(rs.rating_count, 0) AS rating_count,
                u.first_name,
                u.last_name
            FROM notes n
            JOIN users u ON n.user_id = u.id
            LEFT JOIN (
                SELECT note_id, AVG(rating) AS rating_average, COUNT(*) AS rating_count
                FROM note_comments
                GROUP BY note_id
            ) rs ON rs.note_id = n.id
            WHERE n.upload_status = 'ready'
              AND n.scan_status = 'clean'
              AND n.deleted_at IS NULL
            ORDER BY n.created_at DESC
        ");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? '')))));
            $ext = strtolower(pathinfo((string)($row['original_filename'] ?? ''), PATHINFO_EXTENSION));
            $fileType = in_array($ext, ['pdf', 'docx', 'pptx'], true)
                ? $ext
                : (str_starts_with((string)($row['mime_type'] ?? ''), 'image/') ? 'image' : 'other');

            $notesPayload[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'description' => (string)($row['description'] ?? ''),
                'uploader' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'universityId' => (string)($row['university_id'] ?? ''),
                'departmentType' => (string)($row['department_type'] ?? ''),
                'departmentId' => (string)($row['department_id'] ?? ''),
                'classId' => (string)($row['class_id'] ?? ''),
                'course' => (string)($row['course'] ?? ''),
                'topic' => (string)($row['topic'] ?? ''),
                'tags' => $tags,
                'views' => 0,
                'downloads' => (int)($row['download_count'] ?? 0),
                'ratingAverage' => ratingAverageValue($row['rating_average'] ?? null, (int)($row['rating_count'] ?? 0)),
                'ratingCount' => (int)($row['rating_count'] ?? 0),
                'fileType' => $fileType,
                'createdAt' => (string)$row['created_at']
            ];
        }

        $latestNotes = array_slice($rows, 0, 6);

        $popularNotes = $rows;
        usort($popularNotes, static function (array $left, array $right): int {
            $downloadDiff = ((int)($right['download_count'] ?? 0)) <=> ((int)($left['download_count'] ?? 0));
            if ($downloadDiff !== 0) {
                return $downloadDiff;
            }

            return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
        });
        $popularNotes = array_slice($popularNotes, 0, 6);
    } catch (Throwable $e) {
        $latestNotes = [];
        $popularNotes = [];
        $notesPayload = [];
    }
}

function buildNoteExcerpt(?string $description, int $maxLength = 80): string
{
    $text = trim((string)$description);
    if ($text === '') {
        return 'Açıklama eklenmemiş.';
    }

    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }

    return mb_substr($text, 0, $maxLength) . '...';
}

function getNoteTags(?string $rawTags, int $limit = 2): array
{
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)$rawTags))));
    return $limit > 0 ? array_slice($tags, 0, $limit) : $tags;
}

$pageTitle = 'Not Bul | Anasayfa';
$pageKey = 'home';
require __DIR__ . '/includes/header.php';

$errorMsg = isset($_GET['error']) && $_GET['error'] === 'not_found' ? 'Üzgünüz, aradığınız not veritabanında bulunamadı (ID: ' . (int)$_GET['id'] . ').' : '';
$successMsg = isset($_GET['note_deleted']) && $_GET['note_deleted'] === '1'
    ? 'Not başarıyla arşive alındı.'
    : '';
?>
<main class="page-shell">
    <section class="hero-section container">
        <?php if ($successMsg): ?>
            <div class="alert alert-success mt-3"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="alert alert-warning mt-3"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>
        <?php if ($dbUnavailable): ?>
            <div class="alert alert-warning mt-3">Veritabanı bağlantısı kurulamadığı için not listeleri şu anda gösterilemiyor.</div>
        <?php endif; ?>
        <div class="hero-content">
            <span class="eyebrow">Not Bul • notbul.site</span>
            <h1>Ders Notu Bul, paylaş ve öğren.</h1>
            <p>Not Bul, öğrenciler için Ders Notu Paylaşım Platformu. Üniversite, bölüm, ders ve konu filtreleriyle ihtiyacın olan nota hızlıca ulaş.</p>
        </div>

        <form id="homeFilterForm" class="glass-panel" data-hierarchy-group data-filter-source="public" data-options-scope="notes">
            <div class="row g-3 align-items-end">
                <div class="col-12">
                    <label class="form-label" for="homeQuery">Not Ara</label>
                    <input class="form-control form-control-lg" id="homeQuery" name="query" type="search" placeholder="Örn: Diferansiyel denklemler final notu">
                </div>
                <div class="col-6 col-lg">
                    <label class="form-label" for="homeUniversity">Üniversite</label>
                    <select class="form-select" id="homeUniversity" name="university_id" data-level="university" data-placeholder="Tüm üniversiteler"></select>
                </div>
                <div class="col-6 col-lg">
                    <label class="form-label" for="homeDepartmentType">Program Türü</label>
                    <select class="form-select" id="homeDepartmentType" name="department_type" data-level="department-type" data-placeholder="Program türü seç"></select>
                </div>
                <div class="col-6 col-lg">
                    <label class="form-label" for="homeDepartment">Bölüm</label>
                    <select class="form-select" id="homeDepartment" name="department_id" data-level="department" data-placeholder="Bölüm seç"></select>
                </div>
                <div class="col-6 col-lg">
                    <label class="form-label" for="homeCourse">Ders</label>
                    <select class="form-select" id="homeCourse" name="course" data-level="course" data-placeholder="Ders seç"></select>
                </div>
            </div>
            <p class="mt-3 mb-0 small text-secondary">Filtrelenmiş sonuç sayısı: <strong id="homeResultCount"><?= count($notesPayload) ?></strong></p>
        </form>
    </section>

    <section class="container section-block">
        <div class="panel-card">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <h2 class="section-title mb-0" id="homePrimaryPanelTitle">Popüler Notlar</h2>
                <a href="search.php" class="btn btn-sm btn-outline-primary">Tümünü görüntüle</a>
            </div>
            <div id="popularNotesGrid" class="row g-3">
                <?php if (empty($popularNotes)): ?>
                    <div class="col-12">
                        <div class="empty-state">Henüz popüler not bulunmuyor.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($popularNotes as $note): ?>
                        <article class="col-sm-6 col-xl-4">
                            <div class="note-card card shadow-sm border-0">
                                <div class="card-body">
                                    <h3 class="h6 mb-2 text-truncate"><?= htmlspecialchars((string)$note['title']) ?></h3>
                                    <p class="text-secondary mb-3 small" style="height: 3em; overflow: hidden;">
                                        <?= htmlspecialchars(buildNoteExcerpt((string)($note['description'] ?? ''))) ?>
                                    </p>
                                    <div class="note-tags mb-3">
                                        <?php foreach (getNoteTags((string)($note['tags'] ?? ''), 2) as $tag): ?>
                                            <span class="badge bg-light text-secondary fw-normal">#<?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="note-card-footer d-flex justify-content-between align-items-center gap-3">
                                        <div class="small">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars(trim((string)$note['first_name'] . ' ' . (string)$note['last_name'])) ?></div>
                                            <div class="text-secondary"><?= htmlspecialchars((string)($note['course'] ?? '-')) ?></div>
                                        </div>
                                        <div class="note-card-actions d-flex align-items-center gap-2 ms-auto">
                                            <?php if ((int)($note['rating_count'] ?? 0) > 0): ?>
                                                <?= renderRatingSummary($note['rating_average'] ?? null, (int)$note['rating_count']) ?>
                                            <?php endif; ?>
                                            <a href="note-detail.php?id=<?= (int)$note['id'] ?>" class="btn btn-sm btn-primary">Detay</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="container section-block pb-5">
        <div class="panel-card">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <h2 class="section-title mb-0">Son Yüklenenler</h2>
                <a href="search.php" class="btn btn-sm btn-outline-primary">Tümünü görüntüle</a>
            </div>
            <div id="latestNotesGrid" class="row g-3">
                <?php if (empty($latestNotes)): ?>
                    <div class="col-12">
                        <div class="empty-state">Henüz hiç not yüklenmemiş. İster misin? <a href="upload.php" class="text-decoration-none">Hemen Yükle</a></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($latestNotes as $note): ?>
                        <article class="col-sm-6 col-xl-4">
                            <div class="note-card card shadow-sm border-0">
                                <div class="card-body">
                                    <h3 class="h6 mb-2 text-truncate"><?= htmlspecialchars((string)$note['title']) ?></h3>
                                    <p class="text-secondary mb-3 small" style="height: 3em; overflow: hidden;">
                                        <?= htmlspecialchars(buildNoteExcerpt((string)($note['description'] ?? ''))) ?>
                                    </p>
                                    <div class="note-tags mb-3">
                                        <?php foreach (getNoteTags((string)($note['tags'] ?? ''), 2) as $tag): ?>
                                            <span class="badge bg-light text-secondary fw-normal">#<?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="note-card-footer d-flex justify-content-between align-items-center gap-3">
                                        <div class="small">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars(trim((string)$note['first_name'] . ' ' . (string)$note['last_name'])) ?></div>
                                            <div class="text-secondary"><?= htmlspecialchars((string)($note['course'] ?? '-')) ?></div>
                                        </div>
                                        <div class="note-card-actions d-flex align-items-center gap-2 ms-auto">
                                            <?php if ((int)($note['rating_count'] ?? 0) > 0): ?>
                                                <?= renderRatingSummary($note['rating_average'] ?? null, (int)$note['rating_count']) ?>
                                            <?php endif; ?>
                                            <a href="note-detail.php?id=<?= (int)$note['id'] ?>" class="btn btn-sm btn-primary">Detay</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<script>
window.NOTBUL_NOTES = <?= json_encode($notesPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
