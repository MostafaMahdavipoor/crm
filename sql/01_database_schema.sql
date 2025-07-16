CREATE TABLE IF NOT EXISTS `users` (
                                       `id` INT AUTO_INCREMENT PRIMARY KEY,
                                       `chat_id` BIGINT UNIQUE NOT NULL COMMENT 'Telegram Chat ID',
                                       `username` VARCHAR(255) NULL COMMENT 'Telegram Username',
    `first_name` VARCHAR(255) NULL COMMENT 'User First Name',
    `last_name` VARCHAR(255) NULL COMMENT 'User Last Name',
    `language` VARCHAR(10) DEFAULT 'fa' COMMENT 'User Language (fa/en)',
    `language_code` VARCHAR(10) DEFAULT 'fa' COMMENT 'Telegram Language Code',
    `is_admin` TINYINT(1) DEFAULT 0 COMMENT 'Admin Status',
    `status` ENUM('active', 'blocked', 'pending') DEFAULT 'active' COMMENT 'User Status',
    `referral_code` VARCHAR(50) NULL COMMENT 'Referral Code',
    `entry_token` VARCHAR(100) NULL COMMENT 'Entry Token for Registration',
    `join_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Join Date',
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last Activity',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_chat_id` (`chat_id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_status` (`status`),
    INDEX `idx_is_admin` (`is_admin`),
    INDEX `idx_join_date` (`join_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Telegram Bot Users';
CREATE TABLE IF NOT EXISTS `settings` (
                                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                                          `key` VARCHAR(100) UNIQUE NOT NULL COMMENT 'Setting Key',
    `value` TEXT NOT NULL COMMENT 'Setting Value',
    `description` VARCHAR(255) NULL COMMENT 'Setting Description',
    `type` ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string' COMMENT 'Value Type',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_key` (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application Settings';

CREATE TABLE IF NOT EXISTS `customers` (
                                           `id` INT AUTO_INCREMENT PRIMARY KEY,
                                           `admin_chat_id` BIGINT NOT NULL COMMENT 'Telegram Chat ID of the admin (creator)',
                                           `name` VARCHAR(255) NOT NULL COMMENT 'Full name of the customer',
    `phone` VARCHAR(20) NOT NULL COMMENT 'Phone number of the customer',
    `email` VARCHAR(255) NULL COMMENT 'Email address of the customer',
    `status` ENUM('cold', 'in_progress', 'active_customer') NOT NULL COMMENT 'Status of the customer',
    `note` TEXT NULL COMMENT 'Optional notes about the customer',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Customer registration date',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update date',
    `is_deleted` TINYINT(1) DEFAULT 0 COMMENT 'Soft delete flag (0 = not deleted, 1 = deleted)',

    INDEX `idx_admin_chat_id` (`admin_chat_id`),
    INDEX `idx_name` (`name`),
    INDEX `idx_phone` (`phone`),
    INDEX `idx_status` (`status`),
    INDEX `idx_email` (`email`),
    INDEX `idx_is_deleted` (`is_deleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customers Data';
