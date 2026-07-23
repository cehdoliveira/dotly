-- Tabela para registrar migrações executadas
CREATE TABLE IF NOT EXISTS `migrations_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `migration_name` VARCHAR(255) NOT NULL UNIQUE,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('success', 'failed') DEFAULT 'success',
    `error_message` TEXT,
    INDEX idx_migration (migration_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
