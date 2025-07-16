-- =====================================================
-- Telegram Bot Database Schema
-- Version: 1.0
-- Description: Complete database structure for Telegram bot
-- =====================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS telegram_bot_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE telegram_bot_db;

-- =====================================================
-- USERS TABLE
-- =====================================================
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

-- =====================================================
-- CHAT_HISTORY TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `chat_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL COMMENT 'Telegram Chat ID',
    `role` ENUM('user', 'assistant') NOT NULL COMMENT 'Message Role',
    `content_type` ENUM('text', 'image', 'document', 'voice', 'video') DEFAULT 'text' COMMENT 'Content Type',
    `content` TEXT NOT NULL COMMENT 'Message Content',
    `file_id` VARCHAR(255) NULL COMMENT 'Telegram File ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_chat_id` (`chat_id`),
    INDEX `idx_role` (`role`),
    INDEX `idx_created_at` (`created_at`),

    FOREIGN KEY (`chat_id`) REFERENCES `users`(`chat_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI Chat History';

-- =====================================================
-- BROADCASTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL COMMENT 'Broadcast Title',
    `message` TEXT NOT NULL COMMENT 'Broadcast Message',
    `button_text` VARCHAR(100) NULL COMMENT 'Button Text',
    `button_link` VARCHAR(500) NULL COMMENT 'Button Link',
    `status` ENUM('draft', 'sending', 'completed', 'cancelled') DEFAULT 'draft' COMMENT 'Broadcast Status',
    `created_by` BIGINT NOT NULL COMMENT 'Admin Chat ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `started_at` TIMESTAMP NULL COMMENT 'Broadcast Start Time',
    `completed_at` TIMESTAMP NULL COMMENT 'Broadcast Completion Time',

    INDEX `idx_status` (`status`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_created_at` (`created_at`),

    FOREIGN KEY (`created_by`) REFERENCES `users`(`chat_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Broadcast Messages';

-- =====================================================
-- BROADCAST_RECIPIENTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `broadcast_recipients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `broadcast_id` INT NOT NULL COMMENT 'Broadcast ID',
    `user_id` BIGINT NOT NULL COMMENT 'User Chat ID',
    `status` ENUM('pending', 'sent', 'failed') DEFAULT 'pending' COMMENT 'Delivery Status',
    `message_id` BIGINT NULL COMMENT 'Telegram Message ID',
    `error_code` INT NULL COMMENT 'Telegram Error Code',
    `sent_at` TIMESTAMP NULL COMMENT 'Sent Time',

    INDEX `idx_broadcast_id` (`broadcast_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),

    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`chat_id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_broadcast_user` (`broadcast_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Broadcast Recipients';

-- =====================================================
-- SETTINGS TABLE
-- =====================================================
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

-- =====================================================
-- LOGS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `level` ENUM('info', 'success', 'warning', 'error') NOT NULL COMMENT 'Log Level',
    `title` VARCHAR(255) NOT NULL COMMENT 'Log Title',
    `message` TEXT NOT NULL COMMENT 'Log Message',
    `context` JSON NULL COMMENT 'Log Context',
    `chat_id` BIGINT NULL COMMENT 'Related Chat ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_level` (`level`),
    INDEX `idx_chat_id` (`chat_id`),
    INDEX `idx_created_at` (`created_at`),

    FOREIGN KEY (`chat_id`) REFERENCES `users`(`chat_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application Logs';
