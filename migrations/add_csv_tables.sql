-- Миграция: создание таблиц под импорт CSV
-- Таблицы: expenses, expense_service_mappings, domains
-- Дата: 2026-10-23

-- Таблица расходов из выгрузки провайдера
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_name` VARCHAR(255) NOT NULL,
    `node_id` BIGINT UNSIGNED NOT NULL,
    `expense_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'RUB',
    `category` VARCHAR(100),
    `description` TEXT,
    `status` ENUM('pending','confirmed','rejected','cancelled') DEFAULT 'pending',
    `provider_reference` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_service_node` (`service_name`, `node_id`),
    INDEX `idx_date` (`expense_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сопоставлений сервис→нода (обученная память)
CREATE TABLE IF NOT EXISTS `expense_service_mappings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_name` VARCHAR(255) NOT NULL,
    `node_id` BIGINT UNSIGNED NOT NULL,
    `confidence_score` DECIMAL(3,2) DEFAULT 1.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_service_node` (`service_name`, `node_id`),
    INDEX `idx_service` (`service_name`),
    INDEX `idx_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица доменов из выгрузки провайдера
CREATE TABLE IF NOT EXISTS `domains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_name` VARCHAR(255) NOT NULL,
    `node_id` BIGINT UNSIGNED NOT NULL,
    `domain_type` ENUM('primary','secondary','wildcard') DEFAULT 'primary',
    `is_active` TINYINT(1) DEFAULT 1,
    `first_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_domain_node` (`domain_name`, `node_id`),
    INDEX `idx_domain` (`domain_name`),
    INDEX `idx_node` (`node_id`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
