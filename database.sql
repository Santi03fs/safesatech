-- SafesaTech.es — Esquema de base de datos MySQL
-- Puedes importarlo en phpMyAdmin o dejar que admin.php lo cree automáticamente.

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
