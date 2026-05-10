<?php
/**
 * Serenity Spaces — lightweight SMTP mailer.
 * Reads configuration from the settings table.
 * Falls back to PHP mail() if no SMTP host is configured.
 */

/**
 * @param array $attachments  Optional array of ['filename'=>'...','mime'=>'...','data'=>'...'] (raw bytes)
 */
function sendEmail(string $toAddr, string $toName, string $subject, string $htmlBody, array $attachments = []): bool {
    require_once __DIR__ . '/../db/connection.php';

    $host    = getSetting('smtp_host', '');
    $fromAddr = getSetting('smtp_from_email', '');
    $fromName = getSetting('smtp_from_name', 'Serenity Spaces');

    if ($host === '' || $fromAddr === '') {
        // No SMTP config — try native mail() as fallback
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . mb_encode_mimeheader($fromName ?: 'Serenity Spaces') . " <noreply@localhost>\r\n";
        return @mail($toAddr, $subject, $htmlBody, $headers);
    }

    try {
        return _smtpSend($host, $toAddr, $toName, $subject, $htmlBody, $fromAddr, $fromName, $attachments);
    } catch (Exception $e) {
        error_log('[SerenitySpaces mailer] ' . $e->getMessage());
        return false;
    }
}

function _smtpSend(
    string $host,
    string $toAddr, string $toName,
    string $subject, string $htmlBody,
    string $fromAddr, string $fromName,
    array  $attachments = []
): bool {
    $port  = (int)getSetting('smtp_port', '587');
    $enc   = getSetting('smtp_encryption', 'tls'); // tls | ssl | none
    $user  = getSetting('smtp_user', '');
    $rawPass = getSetting('smtp_pass', '');

    // Decrypt SMTP password — GCM (enc2:) or legacy CBC (enc:)
    $pass = $rawPass;
    if (defined('SMTP_ENCRYPT_KEY') && SMTP_ENCRYPT_KEY !== '') {
        try {
            if (str_starts_with($rawPass, 'enc2:')) {
                $decoded   = base64_decode(substr($rawPass, 5));
                $key       = hex2bin(SMTP_ENCRYPT_KEY);
                $nonce     = substr($decoded, 0, 12);
                $tag       = substr($decoded, 12, 16);
                $cipher    = substr($decoded, 28);
                $decrypted = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
                if ($decrypted !== false) $pass = $decrypted;
            } elseif (str_starts_with($rawPass, 'enc:')) {
                $decoded   = base64_decode(substr($rawPass, 4));
                $iv        = substr($decoded, 0, 16);
                $cipher    = substr($decoded, 16);
                $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', hex2bin(SMTP_ENCRYPT_KEY), OPENSSL_RAW_DATA, $iv);
                if ($decrypted !== false) $pass = $decrypted;
            }
        } catch (Throwable $e) {
            error_log('[SerenitySpaces mailer] Could not decrypt SMTP password: ' . $e->getMessage());
        }
    }

    $timeout = 15;

    // SSL/TLS stream context — accept mismatched or self-signed certificates so that
    // cPanel-style hosts (e.g. mail.example.com serving a wildcard cert for hosting provider)
    // do not cause connection failures. The SMTP channel itself is encrypted regardless.
    $sslCtx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    // SSL wrapping (port 465 style)
    if ($enc === 'ssl') {
        $remote = "ssl://{$host}:{$port}";
    } else {
        $remote = "tcp://{$host}:{$port}";
    }

    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $sslCtx);
    if (!$sock) throw new Exception("SMTP connect failed: {$errstr} ({$errno})");
    stream_set_timeout($sock, $timeout);

    $read = function() use ($sock) {
        $buf = '';
        while ($line = fgets($sock, 512)) {
            $buf .= $line;
            if ($line[3] === ' ') break; // last line of multi-line response
        }
        return $buf;
    };

    $cmd = function(string $c) use ($sock, $read) {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $read(); // banner

    // EHLO
    $domain = gethostname() ?: 'localhost';
    $ehlo   = $cmd("EHLO {$domain}");

    // STARTTLS if TLS mode — allow mismatched certs (cPanel hosts)
    if ($enc === 'tls') {
        $cmd("STARTTLS");
        stream_context_set_option($sock, 'ssl', 'verify_peer',       false);
        stream_context_set_option($sock, 'ssl', 'verify_peer_name',  false);
        stream_context_set_option($sock, 'ssl', 'allow_self_signed', true);
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("STARTTLS failed");
        }
        $cmd("EHLO {$domain}"); // re-EHLO after TLS
    }

    // AUTH LOGIN
    if ($user !== '') {
        $r = $cmd("AUTH LOGIN");
        if (!str_starts_with($r, '3')) throw new Exception("AUTH LOGIN failed: {$r}");
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (!str_starts_with($r, '2')) throw new Exception("AUTH failed: {$r}");
    }

    // Envelope
    $r = $cmd("MAIL FROM:<{$fromAddr}>");
    if (!str_starts_with($r, '2')) throw new Exception("MAIL FROM rejected: {$r}");

    $r = $cmd("RCPT TO:<{$toAddr}>");
    if (!str_starts_with($r, '2')) throw new Exception("RCPT TO rejected: {$r}");

    $cmd("DATA");

    // Build message
    $altBoundary  = bin2hex(random_bytes(12));
    $mixBoundary  = bin2hex(random_bytes(12));
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $encodedFrom    = mb_encode_mimeheader($fromName, 'UTF-8', 'B') . " <{$fromAddr}>";
    $encodedTo      = ($toName ? mb_encode_mimeheader($toName, 'UTF-8', 'B') . " <{$toAddr}>" : $toAddr);

    $plainText = strip_tags($htmlBody);
    $plainText = preg_replace('/[ \t]+/', ' ', $plainText);
    $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText);

    $msg  = "From: {$encodedFrom}\r\n";
    $msg .= "To: {$encodedTo}\r\n";
    $msg .= "Subject: {$encodedSubject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";

    if (!empty($attachments)) {
        // multipart/mixed outer — wraps alternative + attachments
        $msg .= "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"\r\n\r\n";
        $msg .= "--{$mixBoundary}\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
    } else {
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
    }

    $msg .= "--{$altBoundary}\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($plainText)) . "\r\n";
    $msg .= "--{$altBoundary}\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $msg .= "--{$altBoundary}--\r\n";

    if (!empty($attachments)) {
        foreach ($attachments as $att) {
            $msg .= "\r\n--{$mixBoundary}\r\n";
            $msg .= "Content-Type: " . ($att['mime'] ?? 'application/octet-stream') . "; name=\"" . addslashes($att['filename'] ?? 'attachment') . "\"\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "Content-Disposition: attachment; filename=\"" . addslashes($att['filename'] ?? 'attachment') . "\"\r\n\r\n";
            $msg .= chunk_split(base64_encode($att['data'] ?? '')) . "\r\n";
        }
        $msg .= "--{$mixBoundary}--\r\n";
    }

    $msg .= ".";

    $r = $cmd($msg);
    $cmd("QUIT");
    fclose($sock);

    return str_starts_with($r, '2');
}

/**
 * Build a standard email template wrapper.
 */
function emailTemplate(string $title, string $bodyHtml): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#0d0d0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0d0d0f;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:12px;overflow:hidden;">
      <tr><td style="padding:32px 40px;background:linear-gradient(135deg,#1a1a2e,#0d0d0f);">
        <div style="font-size:20px;font-weight:700;color:#7c6af7;">Serenity Spaces</div>
      </td></tr>
      <tr><td style="padding:32px 40px;color:#e8e8f0;font-size:15px;line-height:1.6;">
        {$bodyHtml}
      </td></tr>
      <tr><td style="padding:20px 40px;border-top:1px solid rgba(255,255,255,0.08);color:rgba(255,255,255,0.4);font-size:12px;">
        This message was sent by Serenity Spaces. You are receiving it because a session was booked using your email address.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}
