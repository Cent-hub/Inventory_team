<?php
/*
 * Lightweight SMTP mailer helper for Forgot Password OTP emails.
 * Uses Gmail SMTP (STARTTLS on port 587) via PHP's built-in OpenSSL stream sockets
 * so no external packages are required.
 */

class Mailer {
    public static function isSmtpConfigured(): bool {
        $user = trim((string)getenv('SMTP_USERNAME'));
        $pass = trim((string)getenv('SMTP_PASSWORD'));
        return $user !== '' && $pass !== '';
    }

    public static function sendPasswordResetOtp(string $toEmail, string $toName, string $code): bool {
        $overrideTo = trim((string)getenv('SMTP_RESET_OVERRIDE_TO'));
        $actualTo   = ($overrideTo !== '' && filter_var($overrideTo, FILTER_VALIDATE_EMAIL))
            ? $overrideTo
            : $toEmail;

        $safeName   = trim($toName) !== '' ? trim($toName) : 'User';
        $subject    = 'InventoryTeam - Password Reset Verification Code';

        $accountNote = ($actualTo !== $toEmail)
            ? "\n(Requested for account: {$toEmail})\n"
            : '';

        $plainBody = "Hello {$safeName},\n\n"
            . "Your 6-digit password reset verification code is: {$code}\n"
            . $accountNote . "\n"
            . "This code will expire in 15 minutes.\n"
            . "If you did not request this password reset, you can safely ignore this email.\n\n"
            . "— InventoryTeam";

        $escName  = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');
        $escCode  = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $escEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
        $accountHtml = ($actualTo !== $toEmail)
            ? "<p style=\"margin:0 0 14px;font-size:12.5px;color:#64748B;\">Requested for account: <strong>{$escEmail}</strong></p>"
            : '';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px;background:#F8FAFC;font-family:Arial,Helvetica,sans-serif;color:#0F172A;">
  <div style="max-width:480px;margin:0 auto;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;">
    <div style="background:#5B1A18;color:#FFFFFF;padding:18px 24px;">
      <h2 style="margin:0;font-size:18px;font-weight:700;">InventoryTeam</h2>
      <p style="margin:4px 0 0;font-size:12.5px;opacity:0.9;">Password Reset Verification</p>
    </div>
    <div style="padding:24px;">
      <p style="margin:0 0 12px;font-size:14px;">Hello <strong>{$escName}</strong>,</p>
      <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#334155;">
        Use the 6-digit verification code below to reset your password:
      </p>
      {$accountHtml}
      <div style="margin:18px 0;padding:16px;background:#F1F5F9;border:1px dashed #94A3B8;border-radius:8px;text-align:center;">
        <span style="font-family:monospace;font-size:28px;font-weight:700;letter-spacing:6px;color:#0F172A;">{$escCode}</span>
      </div>
      <p style="margin:0 0 8px;font-size:12.5px;color:#64748B;">
        This code expires in <strong>15 minutes</strong>.
      </p>
      <p style="margin:0;font-size:12.5px;color:#64748B;">
        If you did not request a password reset, please ignore this email.
      </p>
    </div>
  </div>
</body>
</html>
HTML;

        return self::sendSmtpEmail($actualTo, $safeName, $subject, $plainBody, $htmlBody);
    }

    public static function sendSmtpEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $plainBody,
        ?string $htmlBody = null
    ): bool {
        if (!self::isSmtpConfigured()) {
            error_log('Mailer: SMTP_USERNAME or SMTP_PASSWORD is not configured in .env.local.');
            return false;
        }

        $host     = trim((string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com'));
        $port     = (int)(getenv('SMTP_PORT') ?: 587);
        $username = trim((string)getenv('SMTP_USERNAME'));
        // Strip spaces in case a 16-char Gmail App Password was pasted with spaces
        $password = preg_replace('/\s+/', '', trim((string)getenv('SMTP_PASSWORD')));
        $fromEmail = trim((string)(getenv('SMTP_FROM_EMAIL') ?: $username));
        $fromName  = trim((string)(getenv('SMTP_FROM_NAME') ?: 'InventoryTeam'));

        $toEmail   = self::sanitizeHeader($toEmail);
        $toName    = self::sanitizeHeader($toName);
        $fromEmail = self::sanitizeHeader($fromEmail);
        $fromName  = self::sanitizeHeader($fromName);
        $subject   = self::sanitizeHeader($subject);

        $scheme  = ($port === 465) ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$scheme}{$host}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            error_log("Mailer: Failed to connect to {$host}:{$port} — [{$errno}] {$errstr}");
            return false;
        }

        stream_set_timeout($socket, 15);

        try {
            self::expectCode($socket, 220);
            self::sendCmd($socket, 'EHLO localhost', 250);

            if ($port !== 465) {
                self::sendCmd($socket, 'STARTTLS', 220);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if ($crypto !== true) {
                    throw new RuntimeException('Failed to enable STARTTLS encryption.');
                }
                self::sendCmd($socket, 'EHLO localhost', 250);
            }

            self::sendCmd($socket, 'AUTH LOGIN', 334);
            self::sendCmd($socket, base64_encode($username), 334);
            self::sendCmd($socket, base64_encode($password), 235);

            self::sendCmd($socket, "MAIL FROM:<{$fromEmail}>", 250);
            self::sendCmd($socket, "RCPT TO:<{$toEmail}>", [250, 251]);
            self::sendCmd($socket, 'DATA', 354);

            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers  = [
                'Date: ' . date('r'),
                'From: ' . self::formatAddress($fromEmail, $fromName),
                'To: ' . self::formatAddress($toEmail, $toName),
                'Reply-To: ' . self::formatAddress($fromEmail, $fromName),
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
            ];

            if ($htmlBody !== null && $htmlBody !== '') {
                $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
                $message = implode("\r\n", $headers) . "\r\n\r\n"
                    . "--{$boundary}\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($plainBody)) . "\r\n"
                    . "--{$boundary}\r\n"
                    . "Content-Type: text/html; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($htmlBody)) . "\r\n"
                    . "--{$boundary}--\r\n";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $message = implode("\r\n", $headers) . "\r\n\r\n"
                    . chunk_split(base64_encode($plainBody)) . "\r\n";
            }

            // Dot-stuffing per RFC 5321
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($socket, $message . "\r\n.\r\n");
            self::expectCode($socket, 250);

            @fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            if (is_resource($socket)) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }
            return false;
        }
    }

    private static function sendCmd($socket, string $cmd, int|array $expectedCodes): string {
        fwrite($socket, $cmd . "\r\n");
        return self::expectCode($socket, $expectedCodes);
    }

    private static function expectCode($socket, int|array $expectedCodes): string {
        $codes = is_array($expectedCodes) ? $expectedCodes : [$expectedCodes];
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr(trim($response), 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("Unexpected SMTP response ({$code}): " . trim($response));
        }
        return $response;
    }

    private static function sanitizeHeader(string $value): string {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private static function formatAddress(string $email, string $name): string {
        if ($name === '') {
            return "<{$email}>";
        }
        return '=?UTF-8?B?' . base64_encode($name) . "?= <{$email}>";
    }
}
