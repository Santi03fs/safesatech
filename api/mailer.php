<?php
/**
 * Envío de correos por SMTP (si está configurado) o PHP mail().
 */

function send_lead_email(string $nombre, string $replyEmail, string $tipoProyecto, string $mensaje): bool
{
    $to = defined('CONTACT_RECIPIENT') ? CONTACT_RECIPIENT : 'safesatech@gmail.com';
    $from = defined('CONTACT_FROM') ? CONTACT_FROM : 'noreply@safesatech.es';
    $subject = 'Nueva consulta web (' . $tipoProyecto . ') — ' . $nombre;

    $safeNombre = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($replyEmail, ENT_QUOTES, 'UTF-8');
    $safeTipo = htmlspecialchars($tipoProyecto, ENT_QUOTES, 'UTF-8');
    $safeMensaje = nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'));
    $fecha = date('d/m/Y H:i');

    $htmlBody = "
    <html>
      <body style='font-family: Arial, sans-serif; background:#0D1015; color:#FFFFFF; padding:24px;'>
        <div style='max-width:600px; margin:0 auto; background:#141923; border:1px solid #263042; border-radius:14px; padding:24px;'>
          <h2 style='margin-top:0; color:#00D2D3;'>Nueva consulta en SafesaTech.es</h2>
          <p><strong>Nombre:</strong> {$safeNombre}</p>
          <p><strong>Email:</strong> <a href='mailto:{$safeEmail}' style='color:#1DD1A1;'>{$safeEmail}</a></p>
          <p><strong>Tipo de proyecto:</strong> {$safeTipo}</p>
          <p><strong>Fecha:</strong> {$fecha}</p>
          <hr style='border:0; border-top:1px solid #263042; margin:18px 0;'>
          <p style='margin-bottom:6px;'><strong>Mensaje:</strong></p>
          <p style='line-height:1.6; color:#D5DCE6;'>{$safeMensaje}</p>
        </div>
      </body>
    </html>";

    if (
        defined('SMTP_HOST') && SMTP_HOST !== '' &&
        defined('SMTP_USER') && SMTP_USER !== '' &&
        defined('SMTP_PASS') && SMTP_PASS !== ''
    ) {
        $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
        $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls';
        if (smtp_send_raw(SMTP_HOST, $port, $secure, SMTP_USER, SMTP_PASS, $from, $to, $replyEmail, $subject, $htmlBody)) {
            return true;
        }
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: SafesaTech Web <' . $from . '>',
        'Reply-To: ' . $nombre . ' <' . $replyEmail . '>',
        'X-Mailer: PHP/' . phpversion(),
    ];

    return @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers), '-f ' . $from);
}

function smtp_send_raw(
    string $host,
    int $port,
    string $secure,
    string $user,
    string $pass,
    string $from,
    string $to,
    string $replyTo,
    string $subject,
    string $htmlBody
): bool {
    $transportHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $socket = @stream_socket_client("{$transportHost}:{$port}", $errno, $errstr, 12);
    if (!$socket) {
        return false;
    }

    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $cmd = function (string $command, array $expectedCodes) use ($socket, $read): bool {
        fwrite($socket, $command . "\r\n");
        $resp = $read();
        $code = (int) substr($resp, 0, 3);
        return in_array($code, $expectedCodes, true);
    };

    $greeting = $read();
    if ((int) substr($greeting, 0, 3) !== 220) {
        fclose($socket);
        return false;
    }

    $domain = 'safesatech.es';
    if (!$cmd("EHLO {$domain}", [250])) {
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220])) {
            fclose($socket);
            return false;
        }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        if (!$cmd("EHLO {$domain}", [250])) {
            fclose($socket);
            return false;
        }
    }

    if (
        !$cmd('AUTH LOGIN', [334]) ||
        !$cmd(base64_encode($user), [334]) ||
        !$cmd(base64_encode($pass), [235])
    ) {
        fclose($socket);
        return false;
    }

    $sender = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : $from;
    if (
        !$cmd("MAIL FROM:<{$sender}>", [250]) ||
        !$cmd("RCPT TO:<{$to}>", [250, 251]) ||
        !$cmd('DATA', [354])
    ) {
        fclose($socket);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        "Date: " . date('r'),
        "From: SafesaTech Web <{$sender}>",
        "To: <{$to}>",
        "Reply-To: <{$replyTo}>",
        "Subject: {$encodedSubject}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
    $message = str_replace("\n.", "\n..", $message);

    fwrite($socket, $message . "\r\n.\r\n");
    $dataResp = $read();
    $ok = ((int) substr($dataResp, 0, 3)) === 250;

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return $ok;
}
