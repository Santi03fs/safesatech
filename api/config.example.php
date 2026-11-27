<?php
/**
 * Plantilla de configuración de SafesaTech.es
 * Copia este archivo como api/config.local.php (o usa admin.php la primera vez para generarlo automáticamente).
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_safesatech');
define('DB_USER', 'u000000000_admin');
define('DB_PASS', 'TU_CONTRASEÑA_MYSQL');

// Correo donde llegarán las consultas del formulario
define('CONTACT_RECIPIENT', 'safesatech@gmail.com');
define('CONTACT_FROM', 'noreply@safesatech.es');

// Hash generado con password_hash('tu_clave', PASSWORD_DEFAULT)
define('ADMIN_PASSWORD_HASH', '');

// SMTP opcional (recomendado para evitar carpeta de spam en Gmail)
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_SECURE', 'tls'); // 'tls' o 'ssl'
