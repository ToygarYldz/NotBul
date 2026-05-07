<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/storage.php';
@session_start();

// Bu dosya PDF ve diğer dokümanları güvenli bir şekilde sunucudan tarayıcıya stream etmek için kullanılır.
// Dosyalar doğrudan URL ile değil bu endpoint üzerinden sunulur.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($id <= 0) {
    die('Geçersiz dosya ID.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM notes
    WHERE id = :id
      AND upload_status = 'ready'
      AND scan_status = 'clean'
      AND deleted_at IS NULL
");
$stmt->execute(['id' => $id]);
$note = $stmt->fetch();

if (!$note) {
    die('Not bulunamadı.');
}

$storagePath = resolveNoteStoragePath($note);
if ($storagePath === null) {
    die('Dosya yolu geçersiz.');
}

$filePath = buildNoteAbsolutePath($storagePath);

if (!file_exists($filePath)) {
    die('Dosya sunucuda bulunamadı.');
}

if ($isDownload) {
    try {
        $incrementStmt = $pdo->prepare("UPDATE notes SET download_count = download_count + 1 WHERE id = :id AND deleted_at IS NULL");
        $incrementStmt->execute(['id' => $id]);
    } catch (Throwable $e) {
        error_log('view.php download_count update error: ' . $e->getMessage());
    }
}

$filename = trim((string)($note['original_filename'] ?? ''));
$filename = preg_replace('/[\r\n]+/', '', $filename) ?? '';
if ($filename === '') {
    $filename = 'not-' . $id;
}

// Tarayıcıya dosya tipini bildir
header('Content-Type: ' . $note['mime_type']);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
} else {
    header('Content-Disposition: inline; filename="' . addcslashes($filename, "\\\"") . '"');
}

// Dosyayı oku ve gönder
readfile($filePath);
exit;
