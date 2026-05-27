<?php
declare(strict_types=1);

require_once __DIR__ . '/brevo.php';

function sendAdminNotification(PDO $pdo, string $eventTitle, string $lead, array $details = [], array $links = []): void
{
    try {
        $recipients = adminNotificationRecipients($pdo);
        if (empty($recipients)) {
            error_log('admin notification skipped: recipient list is empty');
            return;
        }

        $subject = '[Not Bul] ' . $eventTitle;
        $htmlContent = buildAdminNotificationHtml($eventTitle, $lead, $details, $links);
        $senderEmail = envValue('ADMIN_NOTIFY_SENDER_EMAIL', 'system-notify@notbul.site');
        $senderName = envValue('ADMIN_NOTIFY_SENDER_NAME', 'Not Bul Sistem Bildirimi');

        foreach ($recipients as $recipient) {
            try {
                sendBrevoEmail(
                    (string)$recipient['email'],
                    (string)$recipient['name'],
                    $subject,
                    $htmlContent,
                    'admin bildirimi',
                    $senderEmail,
                    $senderName
                );
            } catch (Throwable $e) {
                error_log('admin notification send error for ' . (string)$recipient['email'] . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('admin notification error: ' . $e->getMessage());
    }
}

function sendAdminSuggestedActionNotification(PDO $pdo, string $eventTitle, string $lead, array $details = [], array $links = []): void
{
    try {
        $recipients = adminSuggestedActionNotificationRecipients($pdo);
        if (empty($recipients)) {
            error_log('admin suggested action notification skipped: recipient list is empty');
            return;
        }

        $subject = '[Not Bul] ' . $eventTitle;
        $htmlContent = buildAdminNotificationHtml($eventTitle, $lead, $details, $links);
        $senderEmail = envValue('ADMIN_NOTIFY_SENDER_EMAIL', 'system-notify@notbul.site');
        $senderName = envValue('ADMIN_NOTIFY_SENDER_NAME', 'Not Bul Sistem Bildirimi');

        foreach ($recipients as $recipient) {
            try {
                sendBrevoEmail(
                    (string)$recipient['email'],
                    (string)$recipient['name'],
                    $subject,
                    $htmlContent,
                    'admin öneri bildirimi',
                    $senderEmail,
                    $senderName
                );
            } catch (Throwable $e) {
                error_log('admin suggested action notification send error for ' . (string)$recipient['email'] . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('admin suggested action notification error: ' . $e->getMessage());
    }
}

function adminNotificationRecipients(PDO $pdo): array
{
    $recipients = [];
    $seen = [];

    adminNotificationAddRecipient(
        $recipients,
        $seen,
        envValue('ADMIN_NOTIFY_PRIMARY_EMAIL', 'admin@notbul.site'),
        envValue('ADMIN_NOTIFY_PRIMARY_NAME', 'Not Bul Admin')
    );

    try {
        $stmt = $pdo->query("
            SELECT first_name, last_name, email
            FROM users
            WHERE role = 'admin'
              AND admin_email_notifications = 1
            ORDER BY id ASC
        ");

        foreach ($stmt->fetchAll() as $admin) {
            $name = trim((string)($admin['first_name'] ?? '') . ' ' . (string)($admin['last_name'] ?? ''));
            adminNotificationAddRecipient($recipients, $seen, (string)($admin['email'] ?? ''), $name);
        }
    } catch (Throwable $e) {
        error_log('admin notification recipient query error: ' . $e->getMessage());
    }

    return $recipients;
}

function adminSuggestedActionNotificationRecipients(PDO $pdo): array
{
    $recipients = [];
    $seen = [];

    try {
        $stmt = $pdo->query("
            SELECT first_name, last_name, email
            FROM users
            WHERE role = 'admin'
              AND admin_suggested_action_notifications = 1
            ORDER BY id ASC
        ");

        foreach ($stmt->fetchAll() as $admin) {
            $name = trim((string)($admin['first_name'] ?? '') . ' ' . (string)($admin['last_name'] ?? ''));
            adminNotificationAddRecipient($recipients, $seen, (string)($admin['email'] ?? ''), $name);
        }
    } catch (Throwable $e) {
        error_log('admin suggested action recipient query error: ' . $e->getMessage());
    }

    return $recipients;
}

function adminNotificationAddRecipient(array &$recipients, array &$seen, ?string $email, ?string $name): void
{
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $key = mb_strtolower($email, 'UTF-8');
    if (isset($seen[$key])) {
        return;
    }

    $name = trim((string)$name);
    $recipients[] = [
        'email' => $email,
        'name' => $name !== '' ? $name : $email,
    ];
    $seen[$key] = true;
}

function buildAdminNotificationHtml(string $eventTitle, string $lead, array $details, array $links): string
{
    $safeTitle = htmlspecialchars($eventTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLead = nl2br(htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $safePreheader = htmlspecialchars($eventTitle . ' - ' . $lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeFaviconUrl = htmlspecialchars(buildEmailAssetUrl('assets/icons/favicon.svg'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $detailRows = buildAdminNotificationDetailRows($details);
    $linkButtons = buildAdminNotificationLinkButtons($links);
    $sentAt = htmlspecialchars(date('d.m.Y H:i:s'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Not Bul Admin Bildirimi</title>
</head>
<body style="margin:0; padding:0; background:#eef3f8; color:#1c2634; font-family:'Plus Jakarta Sans', Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%; text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; line-height:1px;">
        {$safePreheader}
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; margin:0; padding:0; border-collapse:collapse; background:#eef3f8;">
        <tr>
            <td align="center" style="padding:34px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; max-width:680px; border-collapse:separate; border-spacing:0;">
                    <tr>
                        <td style="padding:0 0 14px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td>
                                        <table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td align="center" width="44" height="44" style="width:44px; height:44px; border:1px solid #cbe0ff; border-radius:12px; background:#eef5ff;">
                                                    <img src="{$safeFaviconUrl}" width="26" height="26" alt="Not Bul" style="display:block; width:26px; height:26px; border:0;">
                                                </td>
                                                <td style="padding-left:12px;">
                                                    <div style="font-family:'Sora', Arial, Helvetica, sans-serif; font-size:18px; line-height:1.2; font-weight:800; color:#223247;">Not Bul</div>
                                                    <div style="font-size:13px; line-height:1.4; color:#667085;">Admin sistem bildirimi</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" style="vertical-align:middle; font-size:13px; line-height:1.4; color:#667085;">{$sentAt}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #d9e3f0; border-radius:14px; overflow:hidden; background:#ffffff; box-shadow:0 6px 18px rgba(43, 83, 143, 0.08);">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td style="padding:30px;">
                                        <span style="display:inline-block; padding:7px 12px; border:1px solid #cbe0ff; border-radius:999px; background:#eef5ff; color:#2f69be; font-size:12px; line-height:1.2; font-weight:800; letter-spacing:0.04em; text-transform:uppercase;">Admin Bildirimi</span>
                                        <h1 style="margin:18px 0 10px; font-family:'Sora', Arial, Helvetica, sans-serif; font-size:26px; line-height:1.2; font-weight:800; color:#1d2a3d;">{$safeTitle}</h1>
                                        <p style="margin:0 0 20px; font-size:15px; line-height:1.65; color:#4b5f7a;">{$safeLead}</p>
                                        {$detailRows}
                                        {$linkButtons}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:18px 30px; background:#0f264a; color:#b5c6df; font-size:13px; line-height:1.55;">
                                        Bu e-posta Not Bul admin bilgilendirme sistemi tarafından otomatik gönderildi.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

function buildAdminNotificationDetailRows(array $details): string
{
    if (empty($details)) {
        return '';
    }

    $rows = '';
    foreach ($details as $label => $value) {
        if (is_int($label) && is_array($value)) {
            $label = (string)($value['label'] ?? '');
            $value = $value['value'] ?? '';
        }

        $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeValue = nl2br(htmlspecialchars(adminNotificationStringValue($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $rows .= <<<HTML
<tr>
    <td style="padding:11px 13px; width:34%; border-bottom:1px solid #e5edf7; color:#61728c; font-size:13px; line-height:1.5; font-weight:700; vertical-align:top;">{$safeLabel}</td>
    <td style="padding:11px 13px; border-bottom:1px solid #e5edf7; color:#25364e; font-size:14px; line-height:1.55; vertical-align:top; word-break:break-word;">{$safeValue}</td>
</tr>
HTML;
    }

    return <<<HTML
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border:1px solid #d8e3f2; border-radius:12px; border-collapse:separate; border-spacing:0; overflow:hidden; margin:0 0 20px; background:#fbfdff;">
    {$rows}
</table>
HTML;
}

function buildAdminNotificationLinkButtons(array $links): string
{
    if (empty($links)) {
        return '';
    }

    $buttons = '';
    foreach ($links as $label => $url) {
        if (is_int($label) && is_array($url)) {
            $label = (string)($url['label'] ?? '');
            $url = (string)($url['url'] ?? '');
        }

        $label = trim((string)$label);
        $url = trim((string)$url);
        if ($label === '' || $url === '') {
            continue;
        }

        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buttons .= <<<HTML
<td style="padding:0 8px 8px 0;">
    <a href="{$safeUrl}" style="display:inline-block; padding:11px 15px; border-radius:9px; background:#3478da; color:#ffffff; font-size:14px; line-height:1.2; font-weight:800; text-decoration:none;">{$safeLabel}</a>
</td>
HTML;
    }

    if ($buttons === '') {
        return '';
    }

    return <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse; margin:0;">
    <tr>{$buttons}</tr>
</table>
HTML;
}

function adminNotificationStringValue($value): string
{
    if ($value === null) {
        return '-';
    }

    if (is_bool($value)) {
        return $value ? 'Evet' : 'Hayır';
    }

    if (is_scalar($value)) {
        $stringValue = trim((string)$value);
        return $stringValue !== '' ? $stringValue : '-';
    }

    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            $items[] = adminNotificationStringValue($item);
        }

        return implode(', ', $items);
    }

    return '-';
}

function adminNotificationUserLabel(array $user): string
{
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));

    if ($name !== '' && $email !== '') {
        return $name . ' <' . $email . '>';
    }

    return $name !== '' ? $name : ($email !== '' ? $email : '-');
}

function adminNotificationAdminLabel(array $admin): string
{
    return adminNotificationUserLabel($admin) . ' (#' . (int)($admin['id'] ?? 0) . ')';
}

function adminNotificationUrl(string $path): string
{
    return buildAppBaseUrl() . '/' . ltrim($path, '/');
}
