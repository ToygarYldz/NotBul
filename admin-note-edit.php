<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_notifications.php';
require_once __DIR__ . '/includes/user_notifications.php';

$adminUser = requireAdminUser($pdo);
$csrfToken = adminCsrfToken('admin_note_edit');

function adminEditRedirectToList(): void
{
    header('Location: admin.php#notes');
    exit;
}

function adminEditRedirectToNote(int $noteId): void
{
    header('Location: admin-note-edit.php?id=' . $noteId);
    exit;
}

function adminEditDate(?string $dateValue): string
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

function adminEditNullableString(string $value): ?string
{
    $value = trim($value);

    return $value === '' ? null : $value;
}

function adminEditLoadJson(string $path): array
{
    $json = @file_get_contents($path);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function adminEditOptionExists(array $items, string $value): bool
{
    foreach ($items as $item) {
        if (is_array($item) && (string)($item['id'] ?? '') === $value) {
            return true;
        }
    }

    return false;
}

function adminEditDepartmentExists(array $departmentsByType, string $value): bool
{
    foreach ($departmentsByType as $items) {
        if (is_array($items) && adminEditOptionExists($items, $value)) {
            return true;
        }
    }

    return false;
}

$noteId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (int)($_POST['id'] ?? 0)
    : (int)($_GET['id'] ?? 0);

if ($noteId <= 0) {
    adminSetFlash('danger', 'Düzenlenecek not bulunamadı.');
    adminEditRedirectToList();
}

$localFlash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    $errors = [];

    if (!adminValidateCsrfToken('admin_note_edit', $requestToken)) {
        $errors[] = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $description = adminEditNullableString((string)($_POST['description'] ?? ''));
    $universityId = adminEditNullableString((string)($_POST['university_id'] ?? ''));
    $departmentType = adminEditNullableString((string)($_POST['department_type'] ?? ''));
    $departmentId = adminEditNullableString((string)($_POST['department_id'] ?? ''));
    $classId = adminEditNullableString((string)($_POST['class_id'] ?? ''));
    $course = adminEditNullableString((string)($_POST['course'] ?? ''));
    $topic = adminEditNullableString((string)($_POST['topic'] ?? ''));
    $tags = adminEditNullableString((string)($_POST['tags'] ?? ''));
    $originalFilename = trim((string)($_POST['original_filename'] ?? ''));
    $mimeType = trim((string)($_POST['mime_type'] ?? ''));
    $fileSizeRaw = trim((string)($_POST['file_size'] ?? ''));
    $downloadCountRaw = trim((string)($_POST['download_count'] ?? ''));
    $uploadStatus = (string)($_POST['upload_status'] ?? '');
    $scanStatus = (string)($_POST['scan_status'] ?? '');

    if ($title === '' || mb_strlen($title) > 160) {
        $errors[] = 'Başlık zorunludur ve 160 karakteri geçemez.';
    }

    if ($originalFilename === '' || mb_strlen($originalFilename) > 255) {
        $errors[] = 'Orijinal dosya adı zorunludur ve 255 karakteri geçemez.';
    }

    if ($mimeType === '' || mb_strlen($mimeType) > 100) {
        $errors[] = 'MIME type zorunludur ve 100 karakteri geçemez.';
    }

    if ($universityId !== null && mb_strlen($universityId) > 50) {
        $errors[] = 'Üniversite ID değeri 50 karakteri geçemez.';
    }

    if ($departmentType !== null && !in_array($departmentType, ['lisans', 'onlisans'], true)) {
        $errors[] = 'Program türü geçersiz.';
    }

    if ($departmentId !== null && mb_strlen($departmentId) > 50) {
        $errors[] = 'Bölüm ID değeri 50 karakteri geçemez.';
    }

    if ($classId !== null && mb_strlen($classId) > 50) {
        $errors[] = 'Sınıf değeri 50 karakteri geçemez.';
    }

    if ($course !== null && mb_strlen($course) > 150) {
        $errors[] = 'Ders adı 150 karakteri geçemez.';
    }

    if ($topic !== null && mb_strlen($topic) > 150) {
        $errors[] = 'Konu adı 150 karakteri geçemez.';
    }

    if ($tags !== null && mb_strlen($tags) > 255) {
        $errors[] = 'Etiketler 255 karakteri geçemez.';
    }

    if ($fileSizeRaw === '' || !ctype_digit($fileSizeRaw)) {
        $errors[] = 'Dosya boyutu sıfır veya pozitif bir tam sayı olmalıdır.';
        $fileSize = 0;
    } else {
        $fileSize = (int)$fileSizeRaw;
    }

    if ($downloadCountRaw === '' || !ctype_digit($downloadCountRaw)) {
        $errors[] = 'İndirme sayısı sıfır veya pozitif bir tam sayı olmalıdır.';
        $downloadCount = 0;
    } else {
        $downloadCount = (int)$downloadCountRaw;
    }

    if (!in_array($uploadStatus, ['pending', 'ready', 'rejected'], true)) {
        $errors[] = 'Yükleme durumu geçersiz.';
    }

    if (!in_array($scanStatus, ['pending', 'clean', 'infected'], true)) {
        $errors[] = 'Tarama durumu geçersiz.';
    }

    if (empty($errors)) {
        try {
            $beforeStmt = $pdo->prepare("
                SELECT n.*, u.first_name, u.last_name, u.email
                FROM notes n
                JOIN users u ON u.id = n.user_id
                WHERE n.id = :id
                LIMIT 1
            ");
            $beforeStmt->execute(['id' => $noteId]);
            $noteBefore = $beforeStmt->fetch();

            if (!$noteBefore) {
                adminSetFlash('danger', 'Düzenlenecek not bulunamadı.');
                adminEditRedirectToList();
            }

            $updateStmt = $pdo->prepare("
                UPDATE notes
                SET title = :title,
                    description = :description,
                    university_id = :university_id,
                    department_type = :department_type,
                    department_id = :department_id,
                    class_id = :class_id,
                    course = :course,
                    topic = :topic,
                    tags = :tags,
                    original_filename = :original_filename,
                    file_size = :file_size,
                    mime_type = :mime_type,
                    upload_status = :upload_status,
                    scan_status = :scan_status,
                    download_count = :download_count
                WHERE id = :id
                LIMIT 1
            ");
            $updateStmt->execute([
                'title' => $title,
                'description' => $description,
                'university_id' => $universityId,
                'department_type' => $departmentType,
                'department_id' => $departmentId,
                'class_id' => $classId,
                'course' => $course,
                'topic' => $topic,
                'tags' => $tags,
                'original_filename' => $originalFilename,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'upload_status' => $uploadStatus,
                'scan_status' => $scanStatus,
                'download_count' => $downloadCount,
                'id' => $noteId,
            ]);

            sendAdminNotification($pdo, 'Not düzenlendi', 'Admin panelinden bir notun detayları güncellendi.', [
                'İşlem yapan admin' => adminNotificationAdminLabel($adminUser),
                'Not' => (string)$noteBefore['title'] . ' (#' . (int)$noteBefore['id'] . ')',
                'Yükleyen' => adminNotificationUserLabel($noteBefore) . ' (#' . (int)$noteBefore['user_id'] . ')',
                'Başlık değişimi' => (string)$noteBefore['title'] . "\n=> " . $title,
                'Ders değişimi' => (string)($noteBefore['course'] ?? '-') . "\n=> " . ($course ?? '-'),
                'Konu değişimi' => (string)($noteBefore['topic'] ?? '-') . "\n=> " . ($topic ?? '-'),
                'Durum değişimi' => (string)$noteBefore['upload_status'] . ' / ' . (string)$noteBefore['scan_status'] . "\n=> " . $uploadStatus . ' / ' . $scanStatus,
                'İndirme sayısı' => (int)$noteBefore['download_count'] . "\n=> " . $downloadCount,
            ], [
                'Notu Düzenle' => adminNotificationUrl('admin-note-edit.php?id=' . $noteId),
                'Not Yönetimi' => adminNotificationUrl('admin.php#notes'),
            ]);
            sendAdminActionUserNotification($adminUser, $noteBefore, 'Notunuz admin tarafından güncellendi', 'Yüklediğiniz bir notun bilgileri admin tarafından güncellendi.', [
                'Başlık değişimi' => (string)$noteBefore['title'] . "\n=> " . $title,
                'Ders değişimi' => (string)($noteBefore['course'] ?? '-') . "\n=> " . ($course ?? '-'),
                'Konu değişimi' => (string)($noteBefore['topic'] ?? '-') . "\n=> " . ($topic ?? '-'),
                'Durum değişimi' => (string)$noteBefore['upload_status'] . ' / ' . (string)$noteBefore['scan_status'] . "\n=> " . $uploadStatus . ' / ' . $scanStatus,
            ], [
                'Profilim' => userNotificationUrl('profile.php'),
            ]);

            adminSetFlash('success', 'Not detayları güncellendi.');
            adminEditRedirectToNote($noteId);
        } catch (Throwable $e) {
            error_log('admin note edit update error: ' . $e->getMessage());
            $localFlash = [
                'type' => 'danger',
                'message' => 'Not güncellenirken beklenmeyen bir hata oluştu.',
            ];
        }
    } else {
        $localFlash = [
            'type' => 'danger',
            'message' => implode(' ', $errors),
        ];
    }
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
    adminSetFlash('danger', 'Düzenlenecek not bulunamadı.');
    adminEditRedirectToList();
}

$universities = adminEditLoadJson(__DIR__ . '/assets/data/universiteler.json');
$departmentsRaw = adminEditLoadJson(__DIR__ . '/assets/data/bolumler.json');
$departmentsByType = [
    'onlisans' => is_array($departmentsRaw['onlisans'] ?? null) ? $departmentsRaw['onlisans'] : [],
    'lisans' => is_array($departmentsRaw['lisans'] ?? null) ? $departmentsRaw['lisans'] : [],
];

$flash = $localFlash ?? adminGetFlash();
$currentUniversityId = (string)($note['university_id'] ?? '');
$currentDepartmentType = (string)($note['department_type'] ?? '');
$currentDepartmentId = (string)($note['department_id'] ?? '');
$currentClassId = (string)($note['class_id'] ?? '');

$pageTitle = 'Not Bul | Not Düzenle';
$pageKey = 'admin';
require __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="container section-block">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h1 class="section-title mb-1">Not Düzenle</h1>
                <p class="mb-0 text-secondary">#<?= (int)$note['id'] ?> / <?= htmlspecialchars((string)$note['title'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="admin.php#notes"><i class="fa-solid fa-arrow-left me-1"></i>Admin Paneli</a>
                <?php if (empty($note['deleted_at']) && (string)$note['upload_status'] === 'ready' && (string)$note['scan_status'] === 'clean'): ?>
                    <a class="btn btn-sm btn-outline-primary" href="note-detail.php?id=<?= (int)$note['id'] ?>">Public Görünüm</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="alert">
                <?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="admin-note-edit.php?id=<?= (int)$note['id'] ?>">
            <input type="hidden" name="id" value="<?= (int)$note['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="row g-4 align-items-start">
                <div class="col-lg-8">
                    <div class="panel-card">
                        <h2 class="h4 mb-3">Not Bilgileri</h2>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="editTitle">Başlık</label>
                                <input class="form-control" id="editTitle" name="title" maxlength="160" required value="<?= htmlspecialchars((string)$note['title'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="editDescription">Açıklama</label>
                                <textarea class="form-control" id="editDescription" name="description" rows="5"><?= htmlspecialchars((string)($note['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editUniversity">Üniversite</label>
                                <select class="form-select" id="editUniversity" name="university_id">
                                    <option value="">Seçili değil</option>
                                    <?php if ($currentUniversityId !== '' && !adminEditOptionExists($universities, $currentUniversityId)): ?>
                                        <option value="<?= htmlspecialchars($currentUniversityId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($currentUniversityId, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($universities as $university): ?>
                                        <?php
                                            if (!is_array($university)) {
                                                continue;
                                            }
                                            $id = (string)($university['id'] ?? '');
                                            $name = (string)($university['name'] ?? $id);
                                            if ($id === '') {
                                                continue;
                                            }
                                        ?>
                                        <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" <?= $currentUniversityId === $id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editDepartmentType">Program Türü</label>
                                <select class="form-select" id="editDepartmentType" name="department_type">
                                    <option value="">Seçili değil</option>
                                    <option value="onlisans" <?= $currentDepartmentType === 'onlisans' ? 'selected' : '' ?>>Önlisans</option>
                                    <option value="lisans" <?= $currentDepartmentType === 'lisans' ? 'selected' : '' ?>>Lisans</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editDepartment">Bölüm</label>
                                <select class="form-select" id="editDepartment" name="department_id">
                                    <option value="">Seçili değil</option>
                                    <?php if ($currentDepartmentId !== '' && !adminEditDepartmentExists($departmentsByType, $currentDepartmentId)): ?>
                                        <option value="<?= htmlspecialchars($currentDepartmentId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($currentDepartmentId, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endif; ?>
                                    <?php foreach (['onlisans' => 'Önlisans', 'lisans' => 'Lisans'] as $type => $label): ?>
                                        <optgroup label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                                            <?php foreach ($departmentsByType[$type] as $department): ?>
                                                <?php
                                                    if (!is_array($department)) {
                                                        continue;
                                                    }
                                                    $id = (string)($department['id'] ?? '');
                                                    $name = (string)($department['name'] ?? $id);
                                                    if ($id === '') {
                                                        continue;
                                                    }
                                                ?>
                                                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" <?= $currentDepartmentId === $id ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editClass">Sınıf</label>
                                <select class="form-select" id="editClass" name="class_id">
                                    <option value="">Seçili değil</option>
                                    <?php for ($class = 1; $class <= 4; $class += 1): ?>
                                        <?php $classValue = (string)$class; ?>
                                        <option value="<?= $classValue ?>" <?= $currentClassId === $classValue ? 'selected' : '' ?>><?= $class ?>. Sınıf</option>
                                    <?php endfor; ?>
                                    <?php if ($currentClassId !== '' && !in_array($currentClassId, ['1', '2', '3', '4'], true)): ?>
                                        <option value="<?= htmlspecialchars($currentClassId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($currentClassId, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editCourse">Ders</label>
                                <input class="form-control" id="editCourse" name="course" maxlength="150" value="<?= htmlspecialchars((string)($note['course'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="editTopic">Konu</label>
                                <input class="form-control" id="editTopic" name="topic" maxlength="150" value="<?= htmlspecialchars((string)($note['topic'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="editTags">Etiketler</label>
                                <input class="form-control" id="editTags" name="tags" maxlength="255" value="<?= htmlspecialchars((string)($note['tags'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="virgülle ayırın">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="panel-card">
                        <h2 class="h4 mb-3">Dosya ve Durum</h2>

                        <div class="mb-3">
                            <label class="form-label" for="editOriginalFilename">Orijinal Dosya Adı</label>
                            <input class="form-control" id="editOriginalFilename" name="original_filename" maxlength="255" required value="<?= htmlspecialchars((string)$note['original_filename'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="editMimeType">MIME Type</label>
                            <input class="form-control" id="editMimeType" name="mime_type" maxlength="100" required value="<?= htmlspecialchars((string)$note['mime_type'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label" for="editFileSize">Dosya Boyutu</label>
                                <input class="form-control" id="editFileSize" name="file_size" type="number" min="0" step="1" required value="<?= (int)$note['file_size'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="editDownloadCount">İndirme</label>
                                <input class="form-control" id="editDownloadCount" name="download_count" type="number" min="0" step="1" required value="<?= (int)$note['download_count'] ?>">
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-6">
                                <label class="form-label" for="editUploadStatus">Yükleme</label>
                                <select class="form-select" id="editUploadStatus" name="upload_status">
                                    <?php foreach (['pending' => 'Pending', 'ready' => 'Ready', 'rejected' => 'Rejected'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= (string)$note['upload_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="editScanStatus">Tarama</label>
                                <select class="form-select" id="editScanStatus" name="scan_status">
                                    <?php foreach (['pending' => 'Pending', 'clean' => 'Clean', 'infected' => 'Infected'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= (string)$note['scan_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <hr>

                        <dl class="admin-meta-list">
                            <dt>Yükleyen</dt>
                            <dd><?= htmlspecialchars(trim((string)$note['first_name'] . ' ' . (string)$note['last_name']), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)$note['email'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Storage Disk</dt>
                            <dd><?= htmlspecialchars((string)$note['storage_disk'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Storage Path</dt>
                            <dd><?= htmlspecialchars((string)$note['storage_path'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Stored Filename</dt>
                            <dd><?= htmlspecialchars((string)($note['stored_filename'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>SHA-256</dt>
                            <dd class="admin-hash"><?= htmlspecialchars((string)$note['sha256'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Oluşturulma</dt>
                            <dd><?= htmlspecialchars(adminEditDate((string)$note['created_at']), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Güncellenme</dt>
                            <dd><?= htmlspecialchars(adminEditDate((string)$note['updated_at']), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Arşivlenme</dt>
                            <dd><?= htmlspecialchars(adminEditDate($note['deleted_at'] !== null ? (string)$note['deleted_at'] : null), ENT_QUOTES, 'UTF-8') ?></dd>
                        </dl>

                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-save me-1"></i>Değişiklikleri Kaydet</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
