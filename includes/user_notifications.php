<?php
declare(strict_types=1);

require_once __DIR__ . '/brevo.php';

function sendUserNotificationEmail(
    string $recipientEmail,
    string $recipientName,
    string $eventTitle,
    string $lead,
    array $details = [],
    array $links = [],
    string $emailTypeLabel = 'kullanıcı bildirimi'
): void {
    try {
        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $subject = '[Not Bul] ' . $eventTitle;
        $htmlContent = buildUserNotificationHtml($eventTitle, $lead, $details, $links);
        $senderEmail = envValue('USER_NOTIFY_SENDER_EMAIL', 'no-reply@notbul.site');
        $senderName = envValue('USER_NOTIFY_SENDER_NAME', 'Not Bul');

        sendBrevoEmail(
            $recipientEmail,
            userNotificationRecipientName($recipientName, $recipientEmail),
            $subject,
            $htmlContent,
            $emailTypeLabel,
            $senderEmail,
            $senderName
        );
    } catch (Throwable $e) {
        error_log('user notification send error: ' . $e->getMessage());
    }
}

function sendUserNotificationToUser(
    array $user,
    string $eventTitle,
    string $lead,
    array $details = [],
    array $links = [],
    string $emailTypeLabel = 'kullanıcı bildirimi'
): void {
    sendUserNotificationEmail(
        (string)($user['email'] ?? ''),
        userNotificationUserName($user),
        $eventTitle,
        $lead,
        $details,
        $links,
        $emailTypeLabel
    );
}

function sendUserSecurityNotification(array $user, string $eventTitle, string $lead, array $details = [], array $links = []): void
{
    sendUserNotificationToUser($user, $eventTitle, $lead, $details, $links, 'hesap güvenliği bildirimi');
}

function sendAdminActionUserNotification(array $actingAdmin, array $recipientUser, string $eventTitle, string $lead, array $details = [], array $links = []): void
{
    if ((int)($actingAdmin['admin_action_user_notifications'] ?? 0) !== 1) {
        return;
    }

    sendUserNotificationToUser($recipientUser, $eventTitle, $lead, $details, $links, 'admin işlem kullanıcı bildirimi');
}

function userNotificationUserName(array $user): string
{
    return trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
}

function userNotificationRecipientName(string $name, string $email): string
{
    $name = trim($name);

    return $name !== '' ? $name : $email;
}

function userNotificationUrl(string $path): string
{
    return buildAppBaseUrl() . '/' . ltrim($path, '/');
}

function buildUserNotificationHtml(string $eventTitle, string $lead, array $details, array $links): string
{
    $safeTitle = htmlspecialchars($eventTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLead = nl2br(htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $safePreheader = htmlspecialchars($eventTitle . ' - ' . $lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeFaviconUrl = htmlspecialchars(buildEmailAssetUrl('assets/icons/favicon.svg'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $detailRows = buildUserNotificationDetailRows($details);
    $linkButtons = buildUserNotificationLinkButtons($links);

    return <<<HTML
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Not Bul Bildirimi</title>
</head>
<body style="margin:0; padding:0; background:#eef3f8; color:#1c2634; font-family:'Plus Jakarta Sans', Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%; text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; line-height:1px;">{$safePreheader}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; margin:0; padding:0; border-collapse:collapse; background:#eef3f8;">
        <tr>
            <td align="center" style="padding:34px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; max-width:640px; border-collapse:separate; border-spacing:0;">
                    <tr>
                        <td style="padding:0 0 14px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                                <tr>
                                    <td align="center" width="44" height="44" style="width:44px; height:44px; border:1px solid #cbe0ff; border-radius:12px; background:#eef5ff;">
                                        <img src="{$safeFaviconUrl}" width="26" height="26" alt="Not Bul" style="display:block; width:26px; height:26px; border:0;">
                                    </td>
                                    <td style="padding-left:12px;">
                                        <div style="font-family:'Sora', Arial, Helvetica, sans-serif; font-size:18px; line-height:1.2; font-weight:800; color:#223247;">Not Bul</div>
                                        <div style="font-size:13px; line-height:1.4; color:#667085;">Ders notu paylaşım platformu</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #d9e3f0; border-radius:14px; overflow:hidden; background:#ffffff; box-shadow:0 6px 18px rgba(43, 83, 143, 0.08);">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td style="padding:30px;">
                                        <span style="display:inline-block; padding:7px 12px; border:1px solid #cbe0ff; border-radius:999px; background:#eef5ff; color:#2f69be; font-size:12px; line-height:1.2; font-weight:800; letter-spacing:0.04em; text-transform:uppercase;">Not Bul</span>
                                        <h1 style="margin:18px 0 10px; font-family:'Sora', Arial, Helvetica, sans-serif; font-size:26px; line-height:1.2; font-weight:800; color:#1d2a3d;">{$safeTitle}</h1>
                                        <p style="margin:0 0 20px; font-size:15px; line-height:1.65; color:#4b5f7a;">{$safeLead}</p>
                                        {$detailRows}
                                        {$linkButtons}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:18px 30px; background:#0f264a; color:#b5c6df; font-size:13px; line-height:1.55;">
                                        Bu e-posta Not Bul hesabınla ilgili otomatik bir bildirim için gönderildi.
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

function buildUserNotificationDetailRows(array $details): string
{
    if (empty($details)) {
        return '';
    }

    $rows = '';
    foreach ($details as $label => $value) {
        $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeValue = nl2br(htmlspecialchars(userNotificationStringValue($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

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

function buildUserNotificationLinkButtons(array $links): string
{
    if (empty($links)) {
        return '';
    }

    $buttons = '';
    foreach ($links as $label => $url) {
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

function userNotificationStringValue($value): string
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

    return '-';
}
