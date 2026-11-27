<?php
/**
 * Endpoint POST del formulario de contacto:
 * 1) Valida y filtra spam (honeypot)
 * 2) Guarda el correo/consulta en MySQL (contact_leads)
 * 3) Envía la notificación por email a safesatech@gmail.com
 */

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

// Honeypot antispam
if (!empty($data['website'] ?? '')) {
    echo json_encode(['ok' => true, 'message' => 'Consulta recibida.']);
    exit;
}

$nombre = trim((string) ($data['nombre'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$tipoProyecto = trim((string) ($data['tipoProyecto'] ?? ''));
$mensaje = trim((string) ($data['mensaje'] ?? ''));

// Prevenir inyección de cabeceras
$nombre = str_replace(["\r", "\n"], ' ', $nombre);
$email = str_replace(["\r", "\n"], '', $email);
$tipoProyecto = str_replace(["\r", "\n"], ' ', $tipoProyecto);

if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 140) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Por favor, introduce un nombre válido.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Introduce una dirección de correo electrónico válida.']);
    exit;
}

$allowedTypes = ['Página Web', 'App Móvil', 'Software a medida', 'Otro'];
if (!in_array($tipoProyecto, $allowedTypes, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selecciona un tipo de proyecto válido.']);
    exit;
}

if (mb_strlen($mensaje) < 10 || mb_strlen($mensaje) > 5000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Cuéntanos un poco más en el mensaje (entre 10 y 5000 caracteres).']);
    exit;
}

$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
if (is_string($ipAddress) && str_contains($ipAddress, ',')) {
    $ipAddress = trim(explode(',', $ipAddress)[0]);
}

$leadId = null;
$savedToDb = false;
$pdo = null;

try {
    $pdo = get_pdo();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('
            INSERT INTO contact_leads (nombre, email, tipo_proyecto, mensaje, ip_address, email_sent)
            VALUES (:nombre, :email, :tipo_proyecto, :mensaje, :ip_address, 0)
        ');
        $stmt->execute([
            ':nombre' => $nombre,
            ':email' => $email,
            ':tipo_proyecto' => $tipoProyecto,
            ':mensaje' => $mensaje,
            ':ip_address' => $ipAddress ? substr((string) $ipAddress, 0, 45) : null,
        ]);
        $leadId = (int) $pdo->lastInsertId();
        $savedToDb = true;
    }
} catch (Throwable $e) {
    $savedToDb = false;
}

$emailSent = send_lead_email($nombre, $email, $tipoProyecto, $mensaje);

if ($savedToDb && $leadId && $emailSent && $pdo instanceof PDO) {
    try {
        $upd = $pdo->prepare('UPDATE contact_leads SET email_sent = 1 WHERE id = :id');
        $upd->execute([':id' => $leadId]);
    } catch (Throwable $e) {
        // No bloquear la respuesta si falla solo la actualización del flag
    }
}

if (!$savedToDb && !$emailSent) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'El servidor de correo y la base de datos aún no están configurados.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'saved' => $savedToDb,
    'emailed' => $emailSent,
    'message' => 'Consulta recibida correctamente.',
]);
