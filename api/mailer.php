<?php
/**
 * Envío de correos por SMTP (si está configurado) o PHP mail(), con diagnóstico detallado.
 */

function send_lead_email(string $nombre, string $replyEmail, string $tipoProyecto, string $mensaje): bool
{
    $res = send_lead_email_with_debug($nombre, $replyEmail, $tipoProyecto, $mensaje);
    return $res['ok'];
}

function send_lead_email_with_debug(string $nombre, string $replyEmail, string $tipoProyecto, string $mensaje): array
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

    $smtpError = '';
    if (
        defined('SMTP_HOST') && SMTP_HOST !== '' &&
        defined('SMTP_USER') && SMTP_USER !== '' &&
        defined('SMTP_PASS') && SMTP_PASS !== ''
    ) {
        $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
        $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : 'tls';
        $pass = (string) SMTP_PASS;
        if (stripos((string) SMTP_HOST, 'gmail.com') !== false) {
            $pass = str_replace(' ', '', $pass);
        }

        $smtpRes = smtp_send_raw(SMTP_HOST, $port, $secure, SMTP_USER, $pass, $from, $to, $replyEmail, $subject, $htmlBody);
        if ($smtpRes['ok']) {
            return ['ok' => true, 'method' => 'SMTP (' . SMTP_HOST . ')', 'error' => ''];
        }
        $smtpError = $smtpRes['error'];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: SafesaTech Web <' . $from . '>',
        'Reply-To: ' . $nombre . ' <' . $replyEmail . '>',
        'X-Mailer: PHP/' . phpversion(),
    ];
    $headerStr = implode("\r\n", $headers);

    if (@mail($to, $encodedSubject, $htmlBody, $headerStr, '-f ' . $from)) {
        return ['ok' => true, 'method' => 'PHP mail() (-f)', 'error' => ''];
    }

    if (@mail($to, $encodedSubject, $htmlBody, $headerStr)) {
        return ['ok' => true, 'method' => 'PHP mail()', 'error' => ''];
    }

    $lastErr = error_get_last()['message'] ?? 'PHP mail() devolvió false';
    $combinedErr = $smtpError !== '' ? ("SMTP: {$smtpError} | mail(): {$lastErr}") : $lastErr;
    return ['ok' => false, 'method' => 'ninguno', 'error' => $combinedErr];
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
): array {
    $transportHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $socket = @stream_socket_client("{$transportHost}:{$port}", $errno, $errstr, 12);
    if (!$socket) {
        return ['ok' => false, 'error' => "No se pudo conectar a {$transportHost}:{$port} ({$errno}: {$errstr})"];
    }
    stream_set_timeout($socket, 12);

    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($data);
    };

    $lastResp = '';
    $cmd = function (string $command, array $expectedCodes) use ($socket, $read, &$lastResp): bool {
        fwrite($socket, $command . "\r\n");
        $lastResp = $read();
        $code = (int) substr($lastResp, 0, 3);
        return in_array($code, $expectedCodes, true);
    };

    $greeting = $read();
    if ((int) substr($greeting, 0, 3) !== 220) {
        fclose($socket);
        return ['ok' => false, 'error' => "Saludo SMTP inesperado: {$greeting}"];
    }

    $domain = 'safesatech.es';
    if (!$cmd("EHLO {$domain}", [250])) {
        fclose($socket);
        return ['ok' => false, 'error' => "EHLO rechazado: {$lastResp}"];
    }

    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220])) {
            fclose($socket);
            return ['ok' => false, 'error' => "STARTTLS falló: {$lastResp}"];
        }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'No se pudo iniciar cifrado TLS.'];
        }
        if (!$cmd("EHLO {$domain}", [250])) {
            fclose($socket);
            return ['ok' => false, 'error' => "EHLO tras TLS falló: {$lastResp}"];
        }
    }

    if (!$cmd('AUTH LOGIN', [334])) {
        fclose($socket);
        return ['ok' => false, 'error' => "AUTH LOGIN rechazado: {$lastResp}"];
    }
    if (!$cmd(base64_encode($user), [334])) {
        fclose($socket);
        return ['ok' => false, 'error' => "Usuario SMTP rechazado: {$lastResp}"];
    }
    if (!$cmd(base64_encode($pass), [235])) {
        fclose($socket);
        return ['ok' => false, 'error' => "Contraseña SMTP rechazada: {$lastResp}"];
    }

    $sender = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : $from;
    if (
        !$cmd("MAIL FROM:<{$sender}>", [250]) ||
        !$cmd("RCPT TO:<{$to}>", [250, 251]) ||
        !$cmd('DATA', [354])
    ) {
        fclose($socket);
        return ['ok' => false, 'error' => "Error en remitente/destinatario: {$lastResp}"];
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

    return $ok
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => "Error al enviar cuerpo del correo: {$dataResp}"];
}
