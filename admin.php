<?php
/**
 * Panel de Administración de SafesaTech.es
 * - Configuración inicial guiada (si aún no existe api/config.local.php)
 * - Subida y gestión de fotos/proyectos persistidos en MySQL
 * - Bandeja de correos/consultas recibidas con exportación CSV
 * - Configuración SMTP opcional para entregas 100 % fiables a safesatech@gmail.com
 */

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/api/db.php';

$configFile = __DIR__ . '/api/config.local.php';
$isConfigured = file_exists($configFile) && is_db_configured() && defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flashSuccess = '';
$flashError = '';

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function write_local_config(string $path, array $cfg): bool
{
    $lines = [
        "<?php",
        "// Generado por SafesaTech Admin — NO subir a GitHub",
        "define('DB_HOST', " . var_export($cfg['db_host'], true) . ");",
        "define('DB_NAME', " . var_export($cfg['db_name'], true) . ");",
        "define('DB_USER', " . var_export($cfg['db_user'], true) . ");",
        "define('DB_PASS', " . var_export($cfg['db_pass'], true) . ");",
        "define('CONTACT_RECIPIENT', " . var_export($cfg['recipient'], true) . ");",
        "define('CONTACT_FROM', " . var_export($cfg['from'], true) . ");",
        "define('ADMIN_PASSWORD_HASH', " . var_export($cfg['admin_hash'], true) . ");",
        "define('SMTP_HOST', " . var_export($cfg['smtp_host'] ?? '', true) . ");",
        "define('SMTP_PORT', " . (int) ($cfg['smtp_port'] ?? 587) . ");",
        "define('SMTP_USER', " . var_export($cfg['smtp_user'] ?? '', true) . ");",
        "define('SMTP_PASS', " . var_export($cfg['smtp_pass'] ?? '', true) . ");",
        "define('SMTP_SECURE', " . var_export($cfg['smtp_secure'] ?? 'tls', true) . ");",
        "",
    ];
    return file_put_contents($path, implode("\n", $lines), LOCK_EX) !== false;
}

function optimize_and_save_image(array $file, string $uploadsDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir el archivo.'];
    }

    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'La foto supera el máximo permitido de 8 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG, WebP o AVIF.'];
    }

    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    $token = bin2hex(random_bytes(8));

    // Optimizar a WebP (máx 1280px ancho) si GD está disponible para máximo PageSpeed móvil
    if (function_exists('imagewebp') && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => false,
        };

        if ($img !== false) {
            $origW = imagesx($img);
            $origH = imagesy($img);
            $maxW = 1280;

            if ($origW > $maxW && $origH > 0) {
                $newW = $maxW;
                $newH = (int) round($origH * ($maxW / $origW));
                $resized = imagecreatetruecolor($newW, $newH);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                imagedestroy($img);
                $img = $resized;
            } else {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }

            $filename = "proyecto_{$token}.webp";
            $destPath = $uploadsDir . '/' . $filename;
            $saved = imagewebp($img, $destPath, 82);
            imagedestroy($img);

            if ($saved) {
                return ['ok' => true, 'path' => 'uploads/' . $filename];
            }
        }
    }

    $ext = $allowed[$mime];
    $filename = "proyecto_{$token}.{$ext}";
    $destPath = $uploadsDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo en /uploads/.'];
    }

    return ['ok' => true, 'path' => 'uploads/' . $filename];
}

// =========================================================================
// MODO SETUP INICIAL (solo cuando api/config.local.php aún no existe)
// =========================================================================
if (!$isConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $flashError = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $recipient = trim((string) ($_POST['recipient'] ?? 'safesatech@gmail.com'));
        $adminPass = (string) ($_POST['admin_pass'] ?? '');

        if ($dbName === '' || $dbUser === '') {
            $flashError = 'Introduce el nombre y usuario de la base de datos MySQL de Hostinger.';
        } elseif (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'El correo de destino no es válido.';
        } elseif (strlen($adminPass) < 8) {
            $flashError = 'La contraseña de administrador debe tener al menos 8 caracteres.';
        } else {
            try {
                $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);
                $testPdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                ensure_tables($testPdo);

                $ok = write_local_config($configFile, [
                    'db_host' => $dbHost,
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_pass' => $dbPass,
                    'recipient' => $recipient,
                    'from' => 'noreply@safesatech.es',
                    'admin_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                ]);

                if (!$ok) {
                    $flashError = 'No se pudo escribir api/config.local.php. Revisa los permisos de escritura de la carpeta api/.';
                } else {
                    $_SESSION['safesa_admin'] = true;
                    header('Location: admin.php?setup=ok');
                    exit;
                }
            } catch (Throwable $e) {
                $flashError = 'Error al conectar con MySQL: ' . $e->getMessage();
            }
        }
    }
}

// =========================================================================
// LOGIN / LOGOUT (con protección frente a fuerza bruta por IP)
// =========================================================================
function get_login_rate_file(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return sys_get_temp_dir() . '/safesa_login_' . hash('sha256', $ip) . '.json';
}

function get_login_attempts(): array
{
    $file = get_login_rate_file();
    if (!is_file($file)) {
        return ['count' => 0, 'blocked_until' => 0];
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return ['count' => 0, 'blocked_until' => 0];
    }
    return [
        'count' => (int) ($data['count'] ?? 0),
        'blocked_until' => (int) ($data['blocked_until'] ?? 0),
    ];
}

if ($isConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $attempts = get_login_attempts();
    if ($attempts['blocked_until'] > time()) {
        $waitMin = (int) ceil(($attempts['blocked_until'] - time()) / 60);
        $flashError = "Demasiados intentos fallidos. Espera {$waitMin} min antes de volver a intentarlo.";
    } elseif (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $flashError = 'Sesión caducada. Vuelve a intentarlo.';
    } else {
        $pass = (string) ($_POST['password'] ?? '');
        if (password_verify($pass, ADMIN_PASSWORD_HASH)) {
            @unlink(get_login_rate_file());
            session_regenerate_id(true);
            $_SESSION['safesa_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $count = $attempts['count'] + 1;
        $blockedUntil = $count >= 5 ? (time() + 300) : 0;
        @file_put_contents(
            get_login_rate_file(),
            json_encode(['count' => $count >= 5 ? 0 : $count, 'blocked_until' => $blockedUntil]),
            LOCK_EX
        );
        $flashError = 'Contraseña incorrecta.';
    }
}

if (isset($_GET['setup']) && $_GET['setup'] === 'ok') {
    $flashSuccess = 'Base de datos conectada y tablas creadas correctamente.';
}

$isLoggedIn = $isConfigured && !empty($_SESSION['safesa_admin']);

// =========================================================================
// ACCIONES AUTENTICADAS
// =========================================================================
if ($isLoggedIn) {
    $pdo = get_pdo();

    // Exportar correos a CSV
    if (($_GET['export'] ?? '') === 'csv' && $pdo) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="safesatech-consultas-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['ID', 'Nombre', 'Email', 'Tipo Proyecto', 'Mensaje', 'Email Enviado', 'Estado', 'Fecha']);
        $rows = $pdo->query('SELECT id, nombre, email, tipo_proyecto, mensaje, email_sent, status, created_at FROM contact_leads ORDER BY id DESC')->fetchAll();
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['nombre'],
                $row['email'],
                $row['tipo_proyecto'],
                $row['mensaje'],
                $row['email_sent'] ? 'Sí' : 'No',
                $row['status'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            $flashError = 'Token de seguridad inválido.';
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: admin.php');
            exit;
        } elseif ($action === 'upload_photo' && $pdo) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $tags = trim((string) ($_POST['tags'] ?? 'Proyecto'));
            $description = trim((string) ($_POST['description'] ?? ''));
            $order = (int) ($_POST['display_order'] ?? 0);

            if ($title === '' || $description === '') {
                $flashError = 'Indica un título y una descripción breve para la foto o proyecto.';
            } elseif (empty($_FILES['photo'])) {
                $flashError = 'Selecciona una imagen para subir.';
            } else {
                $saved = optimize_and_save_image($_FILES['photo'], __DIR__ . '/uploads');
                if (!$saved['ok']) {
                    $flashError = $saved['error'];
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO portfolio_items (title, description, tags, image_path, display_order)
                        VALUES (:title, :description, :tags, :image_path, :display_order)
                    ');
                    $stmt->execute([
                        ':title' => mb_substr($title, 0, 160),
                        ':description' => $description,
                        ':tags' => mb_substr($tags !== '' ? $tags : 'Proyecto', 0, 160),
                        ':image_path' => $saved['path'],
                        ':display_order' => $order,
                    ]);
                    $flashSuccess = 'Foto subida y publicada en la web correctamente.';
                }
            }
        } elseif ($action === 'delete_photo' && $pdo) {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT image_path FROM portfolio_items WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $item = $stmt->fetch();
            if ($item) {
                $pdo->prepare('DELETE FROM portfolio_items WHERE id = :id')->execute([':id' => $id]);
                $filePath = __DIR__ . '/' . ltrim((string) $item['image_path'], '/');
                if (str_starts_with((string) $item['image_path'], 'uploads/') && is_file($filePath)) {
                    @unlink($filePath);
                }
                $flashSuccess = 'Foto eliminada correctamente.';
            }
        } elseif ($action === 'update_lead_status' && $pdo) {
            $id = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'respondido');
            if (in_array($status, ['nuevo', 'respondido', 'archivado'], true)) {
                $pdo->prepare('UPDATE contact_leads SET status = :status WHERE id = :id')
                    ->execute([':status' => $status, ':id' => $id]);
                $flashSuccess = 'Estado de la consulta actualizado.';
            }
        } elseif ($action === 'delete_lead' && $pdo) {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM contact_leads WHERE id = :id')->execute([':id' => $id]);
            $flashSuccess = 'Consulta eliminada.';
        } elseif ($action === 'save_smtp') {
            $recipient = trim((string) ($_POST['recipient'] ?? CONTACT_RECIPIENT));
            $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
            $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
            $smtpUser = trim((string) ($_POST['smtp_user'] ?? ''));
            $rawSmtpPass = (string) ($_POST['smtp_pass'] ?? '');
            $smtpPass = $rawSmtpPass !== '' ? $rawSmtpPass : (defined('SMTP_PASS') ? SMTP_PASS : '');
            if (stripos($smtpHost, 'gmail.com') !== false) {
                $smtpPass = str_replace(' ', '', $smtpPass);
            }
            $smtpSecure = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl'], true) ? $_POST['smtp_secure'] : 'tls';

            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $flashError = 'El correo destinatario no es válido.';
            } else {
                $ok = write_local_config($configFile, [
                    'db_host' => DB_HOST,
                    'db_name' => DB_NAME,
                    'db_user' => DB_USER,
                    'db_pass' => DB_PASS,
                    'recipient' => $recipient,
                    'from' => CONTACT_FROM,
                    'admin_hash' => ADMIN_PASSWORD_HASH,
                    'smtp_host' => $smtpHost,
                    'smtp_port' => $smtpPort,
                    'smtp_user' => $smtpUser,
                    'smtp_pass' => $smtpPass,
                    'smtp_secure' => $smtpSecure,
                ]);
                if ($ok) {
                    $flashSuccess = 'Configuración de correo actualizada.';
                } else {
                    $flashError = 'No se pudo actualizar api/config.local.php.';
                }
            }
        } elseif ($action === 'test_email') {
            require_once __DIR__ . '/api/mailer.php';
            $testRes = send_lead_email_with_debug(
                'Prueba SafesaTech',
                CONTACT_RECIPIENT,
                'Prueba de correo',
                'Este es un correo de prueba enviado desde el panel admin.php de SafesaTech.es.'
            );
            if ($testRes['ok']) {
                $flashSuccess = 'Correo de prueba enviado a ' . CONTACT_RECIPIENT . ' usando ' . $testRes['method'] . '.';
            } else {
                $flashError = 'No se pudo enviar el correo de prueba: ' . $testRes['error'];
            }
        }
    }

    $photos = $pdo ? $pdo->query('SELECT * FROM portfolio_items ORDER BY display_order ASC, id DESC')->fetchAll() : [];
    $leads = $pdo ? $pdo->query('SELECT * FROM contact_leads ORDER BY id DESC LIMIT 200')->fetchAll() : [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panel SafesaTech — Fotos y Correos</title>
  <meta name="robots" content="noindex, nofollow" />
  <link rel="stylesheet" href="styles.css" />
  <style>
    body { padding-block: 36px 72px; }
    .admin-wrap { max-width: 1120px; margin: 0 auto; padding-inline: 20px; }
    .admin-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 28px; }
    .admin-card { padding: 28px; border-radius: 18px; margin-bottom: 24px; }
    .admin-grid { display: grid; grid-template-columns: 1fr 1.25fr; gap: 24px; align-items: start; }
    .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 0.94rem; }
    .alert--ok { background: rgba(29, 209, 161, 0.14); border: 1px solid rgba(29, 209, 161, 0.35); color: #D7FFF2; }
    .alert--err { background: rgba(255, 107, 107, 0.14); border: 1px solid rgba(255, 107, 107, 0.38); color: #FFD5D5; }
    .photo-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
    .photo-item { border-radius: 14px; overflow: hidden; border: 1px solid var(--border-subtle); background: #0B0E14; display: flex; flex-direction: column; }
    .photo-item img { width: 100%; height: 150px; object-fit: cover; }
    .photo-item__body { padding: 14px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
    .photo-item__title { font-weight: 700; font-size: 0.96rem; margin: 0; }
    .photo-item__desc { font-size: 0.84rem; color: var(--text-secondary); margin: 0 0 8px; flex: 1; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border-subtle); vertical-align: top; }
    th { color: var(--cyan); font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .badge-mini { display: inline-block; padding: 3px 9px; border-radius: 99px; font-size: 0.74rem; font-weight: 600; background: rgba(0, 210, 211, 0.14); color: var(--cyan); }
    .badge-mini--ok { background: rgba(29, 209, 161, 0.15); color: var(--turquoise); }
    .badge-mini--warn { background: rgba(255, 189, 46, 0.15); color: #FFD166; }
    .btn-danger { background: rgba(255, 107, 107, 0.16); border: 1px solid rgba(255, 107, 107, 0.35); color: #FFB3B3; padding: 7px 12px; border-radius: 8px; cursor: pointer; font-size: 0.82rem; }
    .btn-mini { background: rgba(255, 255, 255, 0.06); border: 1px solid var(--border-subtle); color: #FFF; padding: 6px 10px; border-radius: 8px; cursor: pointer; font-size: 0.8rem; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 900px) { .admin-grid, .form-row-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="admin-wrap">
    <div class="admin-top">
      <a href="/" class="brand">
        <span class="brand__text"><span class="brand__name">Safesa</span><span class="brand__accent">Tech</span> <span style="font-size:0.85rem;color:var(--text-secondary);font-weight:500;">Panel</span></span>
      </a>
      <div style="display:flex;gap:10px;align-items:center;">
        <a href="/" class="btn btn--ghost btn--sm">Ver web pública</a>
        <?php if ($isLoggedIn): ?>
          <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
            <input type="hidden" name="action" value="logout" />
            <button type="submit" class="btn btn--gradient-border btn--sm">Cerrar sesión</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
      <div class="alert alert--ok"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
      <div class="alert alert--err"><?= h($flashError) ?></div>
    <?php endif; ?>

    <?php if (!$isConfigured): ?>
      <!-- ASISTENTE DE CONFIGURACIÓN INICIAL -->
      <div class="admin-card glass-card" style="max-width:620px;margin:20px auto;">
        <span class="section-eyebrow">Configuración inicial</span>
        <h1 style="margin:0 0 10px;font-size:1.6rem;">Conecta tu base de datos MySQL de Hostinger</h1>
        <p style="color:var(--text-secondary);margin-bottom:22px;">
          En tu panel de Hostinger pulsa <strong>+ Crear base de datos</strong> y pega aquí los datos. Crearemos automáticamente las tablas para guardar las fotos y los correos recibidos.
        </p>
        <form method="POST" class="contact-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
          <input type="hidden" name="action" value="setup" />
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="db_host">Servidor MySQL</label>
              <input id="db_host" name="db_host" class="form-control" value="localhost" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="db_name">Nombre de la BD</label>
              <input id="db_name" name="db_name" class="form-control" placeholder="u123456_safesa" required />
            </div>
          </div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="db_user">Usuario MySQL</label>
              <input id="db_user" name="db_user" class="form-control" placeholder="u123456_admin" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="db_pass">Contraseña MySQL</label>
              <input id="db_pass" name="db_pass" type="password" class="form-control" required />
            </div>
          </div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="recipient">Correo receptor del formulario</label>
              <input id="recipient" name="recipient" type="email" class="form-control" value="safesatech@gmail.com" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="admin_pass">Nueva clave para este panel</label>
              <input id="admin_pass" name="admin_pass" type="password" class="form-control" minlength="8" placeholder="Mínimo 8 caracteres" required />
            </div>
          </div>
          <button type="submit" class="btn btn--primary btn--lg">Conectar base de datos e instalar</button>
        </form>
      </div>

    <?php elseif (!$isLoggedIn): ?>
      <!-- LOGIN -->
      <div class="admin-card glass-card" style="max-width:440px;margin:40px auto;">
        <h1 style="margin:0 0 8px;font-size:1.5rem;">Acceso al estudio</h1>
        <p style="color:var(--text-secondary);margin-bottom:20px;">Introduce tu contraseña para subir fotos y revisar los correos recibidos.</p>
        <form method="POST" class="contact-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
          <input type="hidden" name="action" value="login" />
          <div class="form-group">
            <label class="form-label" for="password">Contraseña</label>
            <input id="password" name="password" type="password" class="form-control" required autofocus />
          </div>
          <button type="submit" class="btn btn--primary btn--lg">Entrar</button>
        </form>
      </div>

    <?php else: ?>
      <!-- PANEL PRINCIPAL -->
      <div class="admin-grid">
        <section class="admin-card glass-card">
          <span class="section-eyebrow">Galería / Portfolio</span>
          <h2 style="margin:0 0 16px;font-size:1.35rem;">Subir nueva foto a la web</h2>
          <form method="POST" enctype="multipart/form-data" class="contact-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
            <input type="hidden" name="action" value="upload_photo" />

            <div class="form-group">
              <label class="form-label" for="photo">Foto (JPG, PNG, WebP, AVIF — máx. 8 MB)</label>
              <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/avif" class="form-control" required />
            </div>

            <div class="form-group">
              <label class="form-label" for="title">Título</label>
              <input id="title" name="title" class="form-control" placeholder="Ej. Plataforma de Reservas" required />
            </div>

            <div class="form-row-2">
              <div class="form-group">
                <label class="form-label" for="tags">Etiquetas (separadas por coma)</label>
                <input id="tags" name="tags" class="form-control" value="Web App, React" />
              </div>
              <div class="form-group">
                <label class="form-label" for="display_order">Orden (0 = primero)</label>
                <input id="display_order" name="display_order" type="number" class="form-control" value="0" />
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="description">Descripción breve</label>
              <textarea id="description" name="description" rows="3" class="form-control" placeholder="Qué problema resuelve este proyecto..." required></textarea>
            </div>

            <button type="submit" class="btn btn--primary">Subir y guardar en la web</button>
          </form>
        </section>

        <section class="admin-card glass-card">
          <span class="section-eyebrow">Publicadas en SafesaTech.es</span>
          <h2 style="margin:0 0 16px;font-size:1.35rem;">Fotos guardadas (<?= count($photos) ?>)</h2>
          <?php if (empty($photos)): ?>
            <p style="color:var(--text-secondary);">Aún no has subido fotos. Mientras tanto la web muestra los 3 casos de muestra por defecto; en cuanto subas la primera foto aparecerá automáticamente en la sección de proyectos.</p>
          <?php else: ?>
            <div class="photo-list">
              <?php foreach ($photos as $photo): ?>
                <article class="photo-item">
                  <img src="<?= h($photo['image_path']) ?>" alt="<?= h($photo['title']) ?>" />
                  <div class="photo-item__body">
                    <h3 class="photo-item__title"><?= h($photo['title']) ?></h3>
                    <span class="badge-mini"><?= h($photo['tags']) ?></span>
                    <p class="photo-item__desc"><?= h($photo['description']) ?></p>
                    <form method="POST" onsubmit="return confirm('¿Eliminar esta foto de la web?');" style="margin:0;">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
                      <input type="hidden" name="action" value="delete_photo" />
                      <input type="hidden" name="id" value="<?= (int) $photo['id'] ?>" />
                      <button type="submit" class="btn-danger">Eliminar</button>
                    </form>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- BANDEJA DE CORREOS GUARDADOS -->
      <section class="admin-card glass-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
          <div>
            <span class="section-eyebrow">Base de datos de clientes</span>
            <h2 style="margin:0;font-size:1.35rem;">Correos y consultas recibidas (<?= count($leads) ?>)</h2>
          </div>
          <a href="admin.php?export=csv" class="btn btn--ghost btn--sm">Exportar CSV</a>
        </div>

        <?php if (empty($leads)): ?>
          <p style="color:var(--text-secondary);">Todavía no se ha recibido ninguna consulta desde el formulario.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Cliente</th>
                  <th>Proyecto</th>
                  <th>Mensaje</th>
                  <th>Email a <?= h(CONTACT_RECIPIENT) ?></th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($leads as $lead): ?>
                  <tr>
                    <td style="white-space:nowrap;color:var(--text-secondary);"><?= h(date('d/m/Y H:i', strtotime($lead['created_at']))) ?></td>
                    <td>
                      <strong><?= h($lead['nombre']) ?></strong><br />
                      <a href="mailto:<?= h($lead['email']) ?>" style="color:var(--cyan);"><?= h($lead['email']) ?></a>
                    </td>
                    <td><span class="badge-mini"><?= h($lead['tipo_proyecto']) ?></span></td>
                    <td style="max-width:340px;color:var(--text-secondary);"><?= nl2br(h($lead['mensaje'])) ?></td>
                    <td>
                      <?php if ($lead['email_sent']): ?>
                        <span class="badge-mini badge-mini--ok">Enviado</span>
                      <?php else: ?>
                        <span class="badge-mini badge-mini--warn">Solo en BD</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
                        <input type="hidden" name="action" value="update_lead_status" />
                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>" />
                        <select name="status" class="btn-mini" onchange="this.form.submit()">
                          <option value="nuevo" <?= $lead['status'] === 'nuevo' ? 'selected' : '' ?>>Nuevo</option>
                          <option value="respondido" <?= $lead['status'] === 'respondido' ? 'selected' : '' ?>>Respondido</option>
                          <option value="archivado" <?= $lead['status'] === 'archivado' ? 'selected' : '' ?>>Archivado</option>
                        </select>
                      </form>
                    </td>
                    <td>
                      <form method="POST" onsubmit="return confirm('¿Borrar esta consulta?');" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
                        <input type="hidden" name="action" value="delete_lead" />
                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>" />
                        <button type="submit" class="btn-danger">Borrar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- AJUSTES SMTP OPCIONALES -->
      <section class="admin-card glass-card">
        <span class="section-eyebrow">Entrega de correo (<?= h(CONTACT_RECIPIENT) ?>)</span>
        <h2 style="margin:0 0 8px;font-size:1.3rem;">Configuración SMTP (opcional)</h2>
        <p style="color:var(--text-secondary);margin-bottom:18px;">
          Por defecto el formulario usa la función <code>mail()</code> del servidor hacia <strong><?= h(CONTACT_RECIPIENT) ?></strong> y además guarda siempre el registro en MySQL. Si deseas entrega autenticada sin riesgo de spam, configura aquí el SMTP de Hostinger o una contraseña de aplicación de Gmail.
        </p>
        <form method="POST" class="contact-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
          <input type="hidden" name="action" value="save_smtp" />
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="smtp_recipient">Correo destino</label>
              <input id="smtp_recipient" name="recipient" type="email" class="form-control" value="<?= h(CONTACT_RECIPIENT) ?>" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="smtp_host">Servidor SMTP (ej. smtp.gmail.com o smtp.hostinger.com)</label>
              <input id="smtp_host" name="smtp_host" class="form-control" value="<?= h(defined('SMTP_HOST') ? SMTP_HOST : '') ?>" />
            </div>
          </div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="smtp_user">Usuario SMTP</label>
              <input id="smtp_user" name="smtp_user" class="form-control" autocomplete="off" placeholder="ej. safesatech@gmail.com o noreply@safesatech.es" value="<?= h(defined('SMTP_USER') ? SMTP_USER : '') ?>" />
            </div>
            <div class="form-group">
              <label class="form-label" for="smtp_pass">Contraseña SMTP / App Password</label>
              <input id="smtp_pass" name="smtp_pass" type="password" class="form-control" autocomplete="new-password" placeholder="Dejar igual si no cambia" />
            </div>
          </div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label" for="smtp_port">Puerto SMTP</label>
              <input id="smtp_port" name="smtp_port" type="number" class="form-control" value="<?= defined('SMTP_PORT') ? (int) SMTP_PORT : 587 ?>" />
            </div>
            <div class="form-group">
              <label class="form-label" for="smtp_secure">Cifrado</label>
              <select id="smtp_secure" name="smtp_secure" class="form-control">
                <option value="tls" <?= (!defined('SMTP_SECURE') || SMTP_SECURE === 'tls') ? 'selected' : '' ?>>TLS (Puerto 587)</option>
                <option value="ssl" <?= (defined('SMTP_SECURE') && SMTP_SECURE === 'ssl') ? 'selected' : '' ?>>SSL (Puerto 465)</option>
              </select>
            </div>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:12px;">
            <button type="submit" class="btn btn--gradient-border">Guardar ajustes de correo</button>
          </div>
        </form>
        <form method="POST" style="margin-top:14px;">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
          <input type="hidden" name="action" value="test_email" />
          <button type="submit" class="btn btn--ghost btn--sm">Probar envío de correo a <?= h(CONTACT_RECIPIENT) ?></button>
        </form>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
