<?php
declare(strict_types=1);

@session_start();

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
                u.first_name,
                u.last_name
            FROM notes n
            JOIN users u ON n.user_id = u.id
            WHERE n.upload_status = 'ready'
              AND n.scan_status = 'clean'
              AND n.deleted_at IS NULL
            ORDER BY n.created_at DESC
        ");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? '')))));
            $ext = strtolower(pathinfo((string)$row['original_filename'], PATHINFO_EXTENSION));
            $fileType = in_array($ext, ['pdf', 'docx', 'pptx'], true)
                ? $ext
                : (str_starts_with((string)$row['mime_type'], 'image/') ? 'image' : 'other');

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
                'fileType' => $fileType,
                'createdAt' => (string)$row['created_at']
            ];
        }
    } catch (Throwable $e) {
        $notesPayload = [];
    }
}

$pageTitle = 'Not Bul | Ders Notu Bul';
$pageKey = 'search';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <?php if ($dbUnavailable): ?>
            <div class="alert alert-warning">Veritabanı bağlantısı kurulamadığı için sonuçlar şu anda görüntülenemiyor.</div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <h1 class="section-title mb-0">Ders Notu Bul</h1>
            <div class="search-box-inline">
                <input id="searchQuery" class="form-control" type="search" placeholder="Başlık, açıklama veya etiket ara">
            </div>
        </div>
        <div class="row g-4 align-items-start">
            <aside class="col-lg-4 col-xl-3">
                <form id="searchFilterForm" class="panel-card" data-hierarchy-group data-filter-source="public" data-options-scope="notes">
                    <h2 class="h5 mb-3">Detaylı Filtreler</h2>

                    <div class="mb-3">
                        <label class="form-label" for="searchUniversity">Üniversite</label>
                        <select class="form-select" id="searchUniversity" name="university_id" data-level="university" data-placeholder="Tüm üniversiteler"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="searchDepartmentType">Program Türü</label>
                        <select class="form-select" id="searchDepartmentType" name="department_type" data-level="department-type" data-placeholder="Program türü seç"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="searchDepartment">Bölüm</label>
                        <select class="form-select" id="searchDepartment" name="department_id" data-level="department" data-placeholder="Tüm bölümler"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="searchClass">Sınıf</label>
                        <select class="form-select" id="searchClass" name="class_id" data-level="class" data-placeholder="Tüm sınıflar"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="searchCourse">Ders</label>
                        <select class="form-select" id="searchCourse" name="course" data-level="course" data-placeholder="Tüm dersler"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="searchTopic">Konu</label>
                        <select class="form-select" id="searchTopic" name="topic" data-level="topic" data-placeholder="Tüm konular"></select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="searchFileType">Dosya Türü</label>
                        <select class="form-select" id="searchFileType" name="file_type">
                            <option value="">Tüm dosya türleri</option>
                            <option value="pdf">PDF</option>
                            <option value="docx">DOCX</option>
                            <option value="pptx">PPTX</option>
                            <option value="image">Görsel</option>
                        </select>
                    </div>
                </form>
            </aside>

            <div class="col-lg-8 col-xl-9">
                <div class="panel-card">
                    <p class="mb-3">Toplam sonuç: <strong id="searchResultCount">0</strong></p>
                    <div id="searchResults" class="search-results"></div>
                    <nav class="mt-4" aria-label="Sayfalama">
                        <ul id="searchPagination" class="pagination justify-content-center mb-0"></ul>
                    </nav>
                </div>
            </div>
        </div>
    </section>
</main>
<script>
window.NOTBUL_NOTES = <?= json_encode($notesPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
