<?php
/**
 * Conexión PDO a MySQL y creación automática de tablas.
 */

$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

if (!defined('CONTACT_RECIPIENT')) {
    define('CONTACT_RECIPIENT', 'safesatech@gmail.com');
}
if (!defined('CONTACT_FROM')) {
    $host = $_SERVER['HTTP_HOST'] ?? 'safesatech.es';
    $cleanHost = preg_replace('/^www\./i', '', explode(':', $host)[0]);
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $cleanHost)) {
        $cleanHost = 'safesatech.es';
    }
    define('CONTACT_FROM', 'noreply@' . $cleanHost);
}

function is_db_configured(): bool
{
    return defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')
        && DB_NAME !== '' && DB_USER !== '';
}

function get_pdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_db_configured()) {
        return null;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    ensure_tables($pdo);
    return $pdo;
}

function ensure_tables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `contact_leads` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(140) NOT NULL,
          `email` VARCHAR(190) NOT NULL,
          `tipo_proyecto` VARCHAR(80) NOT NULL,
          `mensaje` TEXT NOT NULL,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
          `status` ENUM('nuevo', 'respondido', 'archivado') NOT NULL DEFAULT 'nuevo',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          INDEX `idx_created_at` (`created_at`),
          INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `portfolio_items` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `title` VARCHAR(160) NOT NULL,
          `description` TEXT NOT NULL,
          `tags` VARCHAR(160) NOT NULL DEFAULT 'Proyecto',
          `image_path` VARCHAR(255) NOT NULL,
          `display_order` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          INDEX `idx_order_id` (`display_order`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $checked = true;
}
