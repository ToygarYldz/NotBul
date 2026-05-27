<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_notifications.php';

const ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS = 'delete_stale_unverified_users';

function adminSuggestedActionThresholdHours(): int
{
    return max(1, (int)(envValue('STALE_UNVERIFIED_USER_HOURS', '48') ?? '48'));
}

function adminSuggestedActionNotificationPreference(PDO $pdo, int $adminId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT admin_suggested_action_notifications
            FROM users
            WHERE id = :id
              AND role = 'admin'
            LIMIT 1
        ");
        $stmt->execute(['id' => $adminId]);
        $value = $stmt->fetchColumn();

        return $value === false ? true : (int)$value === 1;
    } catch (Throwable $e) {
        error_log('admin suggested action preference read error: ' . $e->getMessage());
        return true;
    }
}

function adminStaleUnverifiedUserCount(PDO $pdo): int
{
    $thresholdHours = adminSuggestedActionThresholdHours();

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM users u
            WHERE u.verified = 0
              AND u.role = 'user'
              AND u.created_at <= DATE_SUB(NOW(), INTERVAL {$thresholdHours} HOUR)
              AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.user_id = u.id LIMIT 1)
              AND NOT EXISTS (SELECT 1 FROM note_comments nc WHERE nc.user_id = u.id LIMIT 1)
        ");

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('admin stale unverified user count error: ' . $e->getMessage());
        return 0;
    }
}

function adminStaleUnverifiedUsers(PDO $pdo, int $limit = 12): array
{
    $thresholdHours = adminSuggestedActionThresholdHours();
    $limit = max(1, min(200, $limit));

    try {
        $stmt = $pdo->query("
            SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.created_at,
                u.email_verification_token_expires_at,
                TIMESTAMPDIFF(HOUR, u.created_at, NOW()) AS age_hours
            FROM users u
            WHERE u.verified = 0
              AND u.role = 'user'
              AND u.created_at <= DATE_SUB(NOW(), INTERVAL {$thresholdHours} HOUR)
              AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.user_id = u.id LIMIT 1)
              AND NOT EXISTS (SELECT 1 FROM note_comments nc WHERE nc.user_id = u.id LIMIT 1)
            ORDER BY u.created_at ASC, u.id ASC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('admin stale unverified users error: ' . $e->getMessage());
        return [];
    }
}

function adminSyncSuggestedActions(PDO $pdo): ?array
{
    $candidateCount = adminStaleUnverifiedUserCount($pdo);
    $candidates = $candidateCount > 0 ? adminStaleUnverifiedUsers($pdo) : [];

    try {
        $actionStmt = $pdo->prepare("
            SELECT *
            FROM admin_suggested_actions
            WHERE action_key = :action_key
            LIMIT 1
        ");
        $actionStmt->execute(['action_key' => ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS]);
        $existingAction = $actionStmt->fetch();

        if ($candidateCount < 1) {
            if ($existingAction && (string)($existingAction['status'] ?? '') !== 'resolved') {
                $resolveStmt = $pdo->prepare("
                    UPDATE admin_suggested_actions
                    SET status = 'resolved',
                        candidate_count = 0,
                        dismissed_until = NULL,
                        resolved_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $resolveStmt->execute(['id' => (int)$existingAction['id']]);
            }

            return null;
        }

        if ($existingAction) {
            $dismissedUntil = (string)($existingAction['dismissed_until'] ?? '');
            $isDismissed = (string)($existingAction['status'] ?? '') === 'dismissed'
                && $dismissedUntil !== ''
                && strtotime($dismissedUntil) !== false
                && strtotime($dismissedUntil) > time();

            $reactivated = !$isDismissed && (string)($existingAction['status'] ?? '') !== 'active';

            $updateStmt = $pdo->prepare("
                UPDATE admin_suggested_actions
                SET candidate_count = :candidate_count,
                    payload_json = :payload_json,
                    status = :status,
                    last_notified_at = :last_notified_at,
                    resolved_at = NULL
                WHERE id = :id
                LIMIT 1
            ");
            $updateStmt->execute([
                'candidate_count' => $candidateCount,
                'payload_json' => json_encode([
                    'threshold_hours' => adminSuggestedActionThresholdHours(),
                    'sample_user_ids' => array_map(static fn(array $user): int => (int)$user['id'], $candidates),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $isDismissed ? 'dismissed' : 'active',
                'last_notified_at' => $reactivated ? null : ($existingAction['last_notified_at'] ?? null),
                'id' => (int)$existingAction['id'],
            ]);

            if ($isDismissed) {
                return null;
            }
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO admin_suggested_actions
                    (action_key, status, title, candidate_count, payload_json)
                VALUES
                    (:action_key, 'active', :title, :candidate_count, :payload_json)
            ");
            $insertStmt->execute([
                'action_key' => ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS,
                'title' => 'Doğrulanmamış eski hesapları temizle',
                'candidate_count' => $candidateCount,
                'payload_json' => json_encode([
                    'threshold_hours' => adminSuggestedActionThresholdHours(),
                    'sample_user_ids' => array_map(static fn(array $user): int => (int)$user['id'], $candidates),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $actionStmt->execute(['action_key' => ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS]);
        $action = $actionStmt->fetch();

        if ($action && empty($action['last_notified_at'])) {
            sendAdminSuggestedActionNotification($pdo, 'Önerilen admin eylemi', 'Doğrulanmamış eski kullanıcı hesapları için temizlik önerisi oluştu.', [
                'Öneri' => adminSuggestedActionThresholdHours() . ' saatten eski doğrulanmamış ve içeriksiz hesaplar silinebilir.',
                'Aday hesap sayısı' => $candidateCount,
            ], [
                'Önerilen Eylemler' => adminNotificationUrl('admin.php#suggested-actions'),
            ]);

            $notifiedStmt = $pdo->prepare("
                UPDATE admin_suggested_actions
                SET last_notified_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $notifiedStmt->execute(['id' => (int)$action['id']]);
            $action['last_notified_at'] = date('Y-m-d H:i:s');
        }

        return [
            'action' => $action,
            'candidate_count' => $candidateCount,
            'candidates' => $candidates,
            'threshold_hours' => adminSuggestedActionThresholdHours(),
        ];
    } catch (Throwable $e) {
        error_log('admin suggested action sync error: ' . $e->getMessage());
        return null;
    }
}

function adminDismissSuggestedAction(PDO $pdo, string $actionKey): void
{
    if ($actionKey !== ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS) {
        throw new InvalidArgumentException('Unknown suggested action.');
    }

    $stmt = $pdo->prepare("
        UPDATE admin_suggested_actions
        SET status = 'dismissed',
            dismissed_until = DATE_ADD(NOW(), INTERVAL 7 DAY)
        WHERE action_key = :action_key
        LIMIT 1
    ");
    $stmt->execute(['action_key' => $actionKey]);
}

function adminDeleteStaleUnverifiedUsers(PDO $pdo): int
{
    $thresholdHours = adminSuggestedActionThresholdHours();

    $stmt = $pdo->query("
        DELETE u
        FROM users u
        WHERE u.verified = 0
          AND u.role = 'user'
          AND u.created_at <= DATE_SUB(NOW(), INTERVAL {$thresholdHours} HOUR)
          AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.user_id = u.id LIMIT 1)
          AND NOT EXISTS (SELECT 1 FROM note_comments nc WHERE nc.user_id = u.id LIMIT 1)
    ");

    $deletedCount = $stmt->rowCount();

    $resolveStmt = $pdo->prepare("
        UPDATE admin_suggested_actions
        SET status = 'resolved',
            candidate_count = 0,
            dismissed_until = NULL,
            resolved_at = NOW()
        WHERE action_key = :action_key
        LIMIT 1
    ");
    $resolveStmt->execute(['action_key' => ADMIN_SUGGESTED_ACTION_DELETE_STALE_USERS]);

    return $deletedCount;
}
