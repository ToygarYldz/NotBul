<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * @throws RuntimeException
 */
function sendVerificationEmail(string $recipientEmail, string $recipientName, string $verificationUrl): void
{
    $subject = 'Not Bul hesap doğrulama';
    $htmlContent = buildBrandedEmailHtml(
        $recipientName,
        $verificationUrl,
        'Hesap Doğrulama',
        'Not Bul hesabın neredeyse hazır.',
        'Ders notlarını keşfetmeye ve paylaşmaya başlaman için e-posta adresini doğrulaman gerekiyor.',
        'Hesabımı Doğrula',
        'Bağlantı 24 saat geçerlidir.',
        'Bu kaydı sen oluşturmadıysan e-postayı güvenle yok sayabilirsin.'
    );

    sendBrevoEmail($recipientEmail, $recipientName, $subject, $htmlContent, 'doğrulama e-postası');
}

/**
 * @throws RuntimeException
 */
function sendPasswordResetEmail(string $recipientEmail, string $recipientName, string $resetUrl): void
{
    $subject = 'Not Bul şifre sıfırlama';
    $htmlContent = buildBrandedEmailHtml(
        $recipientName,
        $resetUrl,
        'Şifre Sıfırlama',
        'Şifreni güvenle yenileyebilirsin.',
        'Not Bul hesabına yeniden erişmek için aşağıdaki bağlantıyı kullan.',
        'Şifremi Sıfırla',
        'Bağlantı 1 saat geçerlidir.',
        'Bu talebi sen oluşturmadıysan e-postayı güvenle yok sayabilirsin.'
    );

    sendBrevoEmail($recipientEmail, $recipientName, $subject, $htmlContent, 'şifre sıfırlama e-postası');
}

function buildBrandedEmailHtml(
    string $recipientName,
    string $actionUrl,
    string $eyebrow,
    string $title,
    string $lead,
    string $buttonText,
    string $expiryText,
    string $securityText
): string {
    $displayName = trim($recipientName) !== '' ? trim($recipientName) : 'Not Bul Kullanıcısı';
    $safeRecipientName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEyebrow = htmlspecialchars($eyebrow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLead = htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeButtonText = htmlspecialchars($buttonText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeExpiryText = htmlspecialchars($expiryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSecurityText = htmlspecialchars($securityText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePreheader = htmlspecialchars($title . ' ' . $expiryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Not Bul</title>
</head>
<body style="margin:0; padding:0; background:#eef3f8; color:#1c2634; font-family:'Plus Jakarta Sans', Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%; text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; line-height:1px;">
        {$safePreheader}
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; margin:0; padding:0; border-collapse:collapse; background:#eef3f8; background-image:linear-gradient(180deg, #edf3f9 0%, #eef3f8 100%);">
        <tr>
            <td align="center" style="padding:34px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; max-width:640px; border-collapse:separate; border-spacing:0;">
                    <tr>
                        <td style="padding:0 0 14px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td align="center" width="44" height="44" style="width:44px; height:44px; border-radius:12px; background:#3a7de1; background-image:linear-gradient(180deg, #4f92f0 0%, #3478da 100%); color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:16px; font-weight:800;">
                                                    NB
                                                </td>
                                                <td style="padding-left:12px;">
                                                    <div style="font-family:'Sora', Arial, Helvetica, sans-serif; font-size:18px; line-height:1.2; font-weight:800; color:#223247;">Not Bul</div>
                                                    <div style="font-size:13px; line-height:1.4; color:#667085;">Ders notu paylaşım platformu</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" style="vertical-align:middle; font-size:13px; line-height:1.4; color:#667085;">
                                        notbul.site
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #d9e3f0; border-radius:14px; overflow:hidden; background:#ffffff; box-shadow:0 6px 18px rgba(43, 83, 143, 0.08);">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">
                                <tr>
                                    <td style="padding:30px 30px 26px; background:#ffffff;">
                                        <span style="display:inline-block; padding:7px 12px; border:1px solid #cbe0ff; border-radius:999px; background:#eef5ff; color:#2f69be; font-size:12px; line-height:1.2; font-weight:800; letter-spacing:0.04em; text-transform:uppercase;">
                                            {$safeEyebrow}
                                        </span>
                                        <h1 style="margin:18px 0 10px; font-family:'Sora', Arial, Helvetica, sans-serif; font-size:28px; line-height:1.2; font-weight:800; color:#1d2a3d;">
                                            {$safeTitle}
                                        </h1>
                                        <p style="margin:0 0 18px; font-size:16px; line-height:1.65; color:#4b5f7a;">
                                            Merhaba {$safeRecipientName},<br>
                                            {$safeLead}
                                        </p>
                                        <table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse; margin:0 0 22px;">
                                            <tr>
                                                <td style="border-radius:9px; background:#3478da; background-image:linear-gradient(180deg, #4f92f0 0%, #3478da 100%); box-shadow:0 8px 18px rgba(58, 125, 225, 0.22);">
                                                    <a href="{$safeUrl}" style="display:inline-block; padding:13px 20px; border-radius:9px; color:#ffffff; font-size:15px; line-height:1.2; font-weight:800; text-decoration:none;">
                                                        {$safeButtonText}
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 20px;">
                                            <tr>
                                                <td style="padding:13px 15px; border:1px solid #d3def0; border-radius:12px; background:#f8fbff; color:#425977; font-size:14px; line-height:1.55;">
                                                    <strong style="color:#263f5d;">{$safeExpiryText}</strong><br>
                                                    {$safeSecurityText}
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:0; font-size:13px; line-height:1.6; color:#667085;">
                                            Buton çalışmazsa bağlantıyı tarayıcına yapıştırabilirsin:<br>
                                            <a href="{$safeUrl}" style="color:#2f69be; text-decoration:none; word-break:break-all;">{$safeUrl}</a>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:22px 30px; background:#0f264a; color:#b5c6df;">
                                        <p style="margin:0 0 7px; font-family:'Sora', Arial, Helvetica, sans-serif; font-size:15px; line-height:1.35; font-weight:700; color:#eff5ff;">Not Bul</p>
                                        <p style="margin:0; font-size:13px; line-height:1.55; color:#b5c6df;">
                                            Üniversite, bölüm, ders ve konu filtreleriyle ihtiyacın olan nota hızlıca ulaş.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 10px 0; color:#71849d; font-size:12px; line-height:1.55;">
                            Bu e-posta Not Bul hesabınla ilgili otomatik bir işlem için gönderildi.
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

function buildAppBaseUrl(): string
{
    $configuredUrl = envValue('APP_BASE_URL');
    if ($configuredUrl !== null && $configuredUrl !== '') {
        return rtrim($configuredUrl, '/');
    }

    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

/**
 * @throws RuntimeException
 */
function sendBrevoEmail(
    string $recipientEmail,
    string $recipientName,
    string $subject,
    string $htmlContent,
    string $emailTypeLabel
): void {
    $apiKey = envValue('BREVO_API_KEY');
    if ($apiKey === null || $apiKey === '') {
        throw new RuntimeException('BREVO_API_KEY bulunamadı.');
    }

    $senderEmail = envValue('BREVO_SENDER_EMAIL', 'no-reply@notbul.site');
    $senderName = envValue('BREVO_SENDER_NAME', 'Not Bul');

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [
            [
                'email' => $recipientEmail,
                'name' => $recipientName,
            ],
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        throw new RuntimeException('Brevo isteği başlatılamadı.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Brevo isteği başarısız: ' . $curlError);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $decoded = json_decode($response, true);
        $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'Bilinmeyen hata';
        throw new RuntimeException('Brevo ' . $emailTypeLabel . ' gönderemedi: ' . $message);
    }
}
