<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const REGISTRATION_TURNSTILE_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function registrationClientIp(): string
{
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        $defaultIp = $remoteAddr;
    } else {
        $defaultIp = 'unknown';
    }

    $trustProxyHeaders = (string)(envValue('TRUST_PROXY_HEADERS', '0') ?? '0') === '1';
    if (!$trustProxyHeaders) {
        return $defaultIp;
    }

    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $forwardedIp) {
            $candidates[] = trim($forwardedIp);
        }
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return $defaultIp;
}

function registrationIdentifierHash(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    return hash('sha256', mb_strtolower($value, 'UTF-8'));
}

function registrationCsrfToken(): string
{
    @session_start();

    $token = (string)($_SESSION['csrf_token_register'] ?? '');
    if ($token === '') {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = hash('sha256', session_id() . 'register' . (string)microtime(true));
        }
        $_SESSION['csrf_token_register'] = $token;
    }

    return $token;
}

function registrationValidateCsrfToken(string $requestToken): bool
{
    @session_start();

    $sessionToken = (string)($_SESSION['csrf_token_register'] ?? '');

    return $sessionToken !== '' && $requestToken !== '' && hash_equals($sessionToken, $requestToken);
}

function registrationIssueFormChallenge(): array
{
    @session_start();
    registrationPruneFormChallenges();

    try {
        $nonce = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $nonce = hash('sha256', session_id() . 'register_form' . (string)microtime(true));
    }

    $_SESSION['registration_form_challenges'][$nonce] = time();

    return [
        'nonce' => $nonce,
    ];
}

function registrationPruneFormChallenges(): void
{
    $challenges = $_SESSION['registration_form_challenges'] ?? [];
    if (!is_array($challenges)) {
        $_SESSION['registration_form_challenges'] = [];
        return;
    }

    $now = time();
    foreach ($challenges as $nonce => $issuedAt) {
        if (!is_string($nonce) || !is_numeric($issuedAt) || $now - (int)$issuedAt > 21600) {
            unset($challenges[$nonce]);
        }
    }

    if (count($challenges) > 10) {
        asort($challenges);
        $challenges = array_slice($challenges, -10, 10, true);
    }

    $_SESSION['registration_form_challenges'] = $challenges;
}

function registrationValidateFormChallenge(string $nonce): array
{
    @session_start();

    $nonce = trim($nonce);
    $challenges = $_SESSION['registration_form_challenges'] ?? [];
    if ($nonce === '' || !is_array($challenges) || !isset($challenges[$nonce]) || !is_numeric($challenges[$nonce])) {
        return [
            'ok' => false,
            'reason' => 'missing_or_invalid_nonce',
        ];
    }

    $issuedAt = (int)$challenges[$nonce];
    unset($challenges[$nonce]);
    $_SESSION['registration_form_challenges'] = $challenges;

    $elapsedSeconds = time() - $issuedAt;
    $minimumSeconds = max(0, (int)(envValue('REGISTRATION_MIN_FORM_SECONDS', '3') ?? '3'));

    if ($elapsedSeconds < $minimumSeconds) {
        return [
            'ok' => false,
            'reason' => 'submitted_too_quickly',
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
    ];
}

function registrationTurnstileSiteKey(): string
{
    return trim((string)(envValue('TURNSTILE_SITE_KEY') ?? ''));
}

function registrationValidateTurnstile(string $token, string $remoteIp): array
{
    $secret = trim((string)(envValue('TURNSTILE_SECRET_KEY') ?? ''));
    if ($secret === '') {
        error_log('turnstile validation failed: missing TURNSTILE_SECRET_KEY');
        return [
            'success' => false,
            'reason' => 'missing_secret',
        ];
    }

    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) {
        error_log('turnstile validation failed: missing or oversized token');
        return [
            'success' => false,
            'reason' => 'invalid_token',
        ];
    }

    $payload = [
        'secret' => $secret,
        'response' => $token,
    ];

    if ($remoteIp !== '' && $remoteIp !== 'unknown') {
        $payload['remoteip'] = $remoteIp;
    }

    $ch = curl_init(REGISTRATION_TURNSTILE_ENDPOINT);
    if ($ch === false) {
        error_log('turnstile validation failed: curl init failed');
        return [
            'success' => false,
            'reason' => 'curl_init_failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('turnstile validation failed: http=' . $httpCode . ' error=' . $curlError);
        return [
            'success' => false,
            'reason' => 'siteverify_request_failed',
        ];
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        error_log('turnstile validation failed: invalid JSON response');
        return [
            'success' => false,
            'reason' => 'invalid_json',
        ];
    }

    if (($decoded['success'] ?? false) !== true) {
        $errorCodes = $decoded['error-codes'] ?? [];
        error_log('turnstile validation failed: ' . json_encode($errorCodes, JSON_UNESCAPED_SLASHES));
        return [
            'success' => false,
            'reason' => 'challenge_failed',
        ];
    }

    $action = (string)($decoded['action'] ?? '');
    if ($action !== '' && $action !== 'register') {
        error_log('turnstile validation failed: action mismatch ' . $action);
        return [
            'success' => false,
            'reason' => 'action_mismatch',
        ];
    }

    $expectedHostname = trim((string)(envValue('TURNSTILE_EXPECTED_HOSTNAME') ?? ''));
    if ($expectedHostname !== '') {
        $hostname = (string)($decoded['hostname'] ?? '');
        if (!hash_equals($expectedHostname, $hostname)) {
            error_log('turnstile validation failed: hostname mismatch ' . $hostname);
            return [
                'success' => false,
                'reason' => 'hostname_mismatch',
            ];
        }
    }

    return [
        'success' => true,
        'reason' => 'ok',
    ];
}

function registrationCleanupAttempts(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM registration_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Throwable $e) {
        error_log('registration attempts cleanup error: ' . $e->getMessage());
    }
}

function registrationCheckRateLimit(PDO $pdo, string $ip, string $email): array
{
    registrationCleanupAttempts($pdo);

    $ipHash = registrationIdentifierHash($ip);
    if ($ipHash === null) {
        return [
            'allowed' => true,
            'reason' => 'no_ip',
        ];
    }

    try {
        $tenMinuteStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM registration_attempts
            WHERE ip_hash = :ip_hash
              AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $tenMinuteStmt->execute(['ip_hash' => $ipHash]);
        $tenMinuteCount = (int)$tenMinuteStmt->fetchColumn();

        if ($tenMinuteCount >= 3) {
            return [
                'allowed' => false,
                'reason' => 'ip_10m_limit',
                'message' => 'Çok fazla kayıt denemesi yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.',
            ];
        }

        $dailyStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM registration_attempts
            WHERE ip_hash = :ip_hash
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $dailyStmt->execute(['ip_hash' => $ipHash]);
        $dailyCount = (int)$dailyStmt->fetchColumn();

        if ($dailyCount >= 10) {
            return [
                'allowed' => false,
                'reason' => 'ip_24h_limit',
                'message' => 'Bugün bu bağlantıdan çok fazla kayıt denemesi yapıldı. Lütfen daha sonra tekrar deneyin.',
            ];
        }
    } catch (Throwable $e) {
        error_log('registration rate limit error: ' . $e->getMessage());
    }

    return [
        'allowed' => true,
        'reason' => 'ok',
    ];
}

function registrationCheckVerificationEmailLimit(PDO $pdo, string $email): array
{
    $emailHash = registrationIdentifierHash($email);
    if ($emailHash === null) {
        return [
            'allowed' => true,
            'reason' => 'no_email',
        ];
    }

    try {
        $cooldownStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM registration_attempts
            WHERE email_hash = :email_hash
              AND result = 'verification_email_sent'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $cooldownStmt->execute(['email_hash' => $emailHash]);
        if ((int)$cooldownStmt->fetchColumn() > 0) {
            return [
                'allowed' => false,
                'reason' => 'email_cooldown',
                'message' => 'Bu e-posta adresi için doğrulama bağlantısı kısa süre önce gönderildi. Lütfen 15 dakika sonra tekrar deneyin.',
            ];
        }

        $dailyStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM registration_attempts
            WHERE email_hash = :email_hash
              AND result = 'verification_email_sent'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $dailyStmt->execute(['email_hash' => $emailHash]);
        if ((int)$dailyStmt->fetchColumn() >= 3) {
            return [
                'allowed' => false,
                'reason' => 'email_24h_limit',
                'message' => 'Bu e-posta adresi için bugün çok fazla doğrulama bağlantısı istendi. Lütfen daha sonra tekrar deneyin.',
            ];
        }
    } catch (Throwable $e) {
        error_log('registration verification email limit error: ' . $e->getMessage());
    }

    return [
        'allowed' => true,
        'reason' => 'ok',
    ];
}

function registrationRecordAttempt(PDO $pdo, string $ip, string $email, string $result): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO registration_attempts (ip_hash, email_hash, result)
            VALUES (:ip_hash, :email_hash, :result)
        ");
        $stmt->execute([
            'ip_hash' => registrationIdentifierHash($ip) ?? hash('sha256', 'unknown'),
            'email_hash' => registrationIdentifierHash($email),
            'result' => mb_substr($result, 0, 64, 'UTF-8'),
        ]);
    } catch (Throwable $e) {
        error_log('registration attempt record error: ' . $e->getMessage());
    }
}
