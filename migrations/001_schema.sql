-- ============================================================
-- TCloud - File Manager System
-- Database Schema v1.0
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `tcloud` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tcloud`;

-- ============================================================
-- Tabela: roles (Papéis do sistema)
-- ============================================================
CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT,
    `is_system` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: users (Usuários)
-- ============================================================
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(200) NOT NULL,
    `avatar` VARCHAR(500) DEFAULT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `storage_quota` BIGINT UNSIGNED DEFAULT 1073741824 COMMENT 'Em bytes, default 1GB',
    `storage_used` BIGINT UNSIGNED DEFAULT 0,
    `status` ENUM('active','inactive','suspended') DEFAULT 'active',
    `theme` ENUM('dark','light','auto') DEFAULT 'dark',
    `view_mode` ENUM('list','grid') DEFAULT 'list',
    `last_login` DATETIME DEFAULT NULL,
    `login_attempts` INT DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: permissions (Permissões granulares)
-- ============================================================
CREATE TABLE `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `category` VARCHAR(50) DEFAULT 'general',
    `description` TEXT
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: role_permissions (Permissões por papel)
-- ============================================================
CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: user_permissions (Permissões individuais)
-- ============================================================
CREATE TABLE `user_permissions` (
    `user_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `granted` TINYINT(1) DEFAULT 1,
    PRIMARY KEY (`user_id`, `permission_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: folders (Pastas virtuais)
-- ============================================================
CREATE TABLE `folders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `path` TEXT NOT NULL COMMENT 'Caminho completo virtual',
    `depth` INT DEFAULT 0,
    `color` VARCHAR(7) DEFAULT NULL,
    `icon` VARCHAR(50) DEFAULT NULL,
    `is_trashed` TINYINT(1) DEFAULT 0,
    `trashed_at` DATETIME DEFAULT NULL,
    `is_shared` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_user_parent` (`user_id`, `parent_id`),
    INDEX `idx_trashed` (`is_trashed`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: files (Arquivos)
-- ============================================================
CREATE TABLE `files` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `original_name` VARCHAR(500) NOT NULL,
    `stored_name` VARCHAR(500) NOT NULL COMMENT 'Nome único no disco',
    `extension` VARCHAR(20) DEFAULT NULL,
    `mime_type` VARCHAR(200) DEFAULT NULL,
    `size` BIGINT UNSIGNED DEFAULT 0,
    `hash_sha256` VARCHAR(64) DEFAULT NULL,
    `folder_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `storage_path` TEXT NOT NULL COMMENT 'Caminho físico relativo',
    `thumbnail_path` VARCHAR(500) DEFAULT NULL,
    `is_trashed` TINYINT(1) DEFAULT 0,
    `trashed_at` DATETIME DEFAULT NULL,
    `is_shared` TINYINT(1) DEFAULT 0,
    `is_locked` TINYINT(1) DEFAULT 0,
    `locked_by` INT UNSIGNED DEFAULT NULL,
    `version` INT DEFAULT 1,
    `description` TEXT DEFAULT NULL,
    `tags` JSON DEFAULT NULL,
    `metadata` JSON DEFAULT NULL COMMENT 'Dimensões imagem, duração vídeo, etc',
    `download_count` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_folder` (`folder_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_extension` (`extension`),
    INDEX `idx_trashed` (`is_trashed`),
    INDEX `idx_name` (`original_name`(191)),
    FULLTEXT INDEX `ft_name` (`original_name`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: file_versions (Histórico de versões)
-- ============================================================
CREATE TABLE `file_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_id` INT UNSIGNED NOT NULL,
    `version_number` INT NOT NULL,
    `stored_name` VARCHAR(500) NOT NULL,
    `storage_path` TEXT NOT NULL,
    `size` BIGINT UNSIGNED DEFAULT 0,
    `hash_sha256` VARCHAR(64) DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `comment` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: favorites (Favoritos)
-- ============================================================
CREATE TABLE `favorites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `file_id` INT UNSIGNED DEFAULT NULL,
    `folder_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_fav_file` (`user_id`, `file_id`),
    UNIQUE KEY `uniq_fav_folder` (`user_id`, `folder_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: recent_files (Acessos recentes)
-- ============================================================
CREATE TABLE `recent_files` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `file_id` INT UNSIGNED NOT NULL,
    `accessed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_recent` (`user_id`, `accessed_at` DESC)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: shares (Compartilhamentos)
-- ============================================================
CREATE TABLE `shares` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_id` INT UNSIGNED DEFAULT NULL,
    `folder_id` INT UNSIGNED DEFAULT NULL,
    `shared_by` INT UNSIGNED NOT NULL,
    `shared_with` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = link público',
    `token` VARCHAR(128) UNIQUE DEFAULT NULL COMMENT 'Token para link público',
    `permission` ENUM('view','edit','download') DEFAULT 'view',
    `password_hash` VARCHAR(255) DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `max_downloads` INT DEFAULT NULL,
    `download_count` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shared_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shared_with`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_token` (`token`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: audit_logs (Logs de auditoria)
-- ============================================================
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'file, folder, user, etc',
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `entity_name` VARCHAR(500) DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_action` (`user_id`, `action`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: sessions (Sessões ativas)
-- ============================================================
CREATE TABLE `sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `payload` TEXT,
    `last_activity` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_activity` (`last_activity`)
) ENGINE=InnoDB;

-- ============================================================
-- Tabela: system_settings (Configurações do sistema)
-- ============================================================
CREATE TABLE `system_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `setting_type` ENUM('string','int','bool','json') DEFAULT 'string',
    `category` VARCHAR(50) DEFAULT 'general',
    `description` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

-- Papéis
INSERT INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES
('Master', 'master', 'Acesso total ao sistema', 1),
('Administrador', 'admin', 'Gerencia usuários e configurações', 1),
('Usuário', 'user', 'Acesso padrão ao sistema', 1);

-- Permissões
INSERT INTO `permissions` (`name`, `slug`, `category`) VALUES
('Visualizar arquivos', 'files.view', 'files'),
('Enviar arquivos', 'files.upload', 'files'),
('Criar pastas', 'folders.create', 'files'),
('Renomear itens', 'files.rename', 'files'),
('Mover itens', 'files.move', 'files'),
('Copiar itens', 'files.copy', 'files'),
('Excluir itens', 'files.delete', 'files'),
('Restaurar itens', 'files.restore', 'files'),
('Compartilhar itens', 'files.share', 'files'),
('Editar documentos', 'files.edit', 'files'),
('Criar documentos', 'files.create_doc', 'files'),
('Acessar lixeira', 'trash.access', 'files'),
('Baixar arquivos', 'files.download', 'files'),
('Gerenciar usuários', 'users.manage', 'admin'),
('Ver logs', 'logs.view', 'admin'),
('Configurações sistema', 'settings.manage', 'admin'),
('Gerenciar permissões', 'permissions.manage', 'admin');

-- Permissões para Master (todas)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Permissões para Admin (quase todas)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`;

-- Permissões para Usuário (operações básicas)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `category` = 'files';

-- Configurações padrão do sistema
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`) VALUES
('site_name', 'TCloud', 'string', 'general', 'Nome do sistema'),
('site_logo', '/public/img/logo.svg', 'string', 'general', 'Logo do sistema'),
('max_upload_size', '104857600', 'int', 'uploads', 'Tamanho máximo de upload em bytes (100MB)'),
('allowed_extensions', '["jpg","jpeg","png","gif","webp","svg","pdf","doc","docx","xls","xlsx","ppt","pptx","txt","html","css","js","json","xml","csv","md","zip","rar","7z","tar","gz","mp4","mp3","wav","ogg","avi","mkv","mov"]', 'json', 'uploads', 'Extensões permitidas'),
('blocked_extensions', '["php","phtml","php3","php4","php5","phps","phar","exe","bat","cmd","sh","cgi","pl","py","rb","jar","com","scr","msi","dll","vbs","wsf"]', 'json', 'uploads', 'Extensões bloqueadas'),
('default_quota', '1073741824', 'int', 'storage', 'Quota padrão por usuário em bytes (1GB)'),
('default_theme', 'dark', 'string', 'general', 'Tema padrão'),
('trash_retention_days', '30', 'int', 'storage', 'Dias para manter itens na lixeira'),
('enable_sharing', '1', 'bool', 'features', 'Habilitar compartilhamento'),
('enable_versioning', '1', 'bool', 'features', 'Habilitar versionamento'),
('onlyoffice_url', '', 'string', 'integrations', 'URL do OnlyOffice Document Server'),
('onlyoffice_secret', '', 'string', 'integrations', 'Chave secreta do OnlyOffice'),
('google_client_id', '', 'string', 'integrations', 'Google OAuth Client ID'),
('google_client_secret', '', 'string', 'integrations', 'Google OAuth Client Secret (manter em segredo)'),
('google_enabled', '0', 'bool', 'integrations', 'Habilitar edição via Google Docs/Sheets/Slides');

-- Tokens OAuth2 do Google por usuário
CREATE TABLE IF NOT EXISTS `google_tokens` (
    `user_id` INT UNSIGNED NOT NULL,
    `access_token` TEXT NOT NULL,
    `refresh_token` TEXT DEFAULT NULL,
    `token_type` VARCHAR(50) DEFAULT 'Bearer',
    `expires_at` DATETIME NOT NULL,
    `scope` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_google_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Arquivos temporários do Google Drive (para limpeza)
CREATE TABLE IF NOT EXISTS `google_temp_files` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `file_id` INT UNSIGNED NOT NULL COMMENT 'TCloud file ID',
    `google_file_id` VARCHAR(255) NOT NULL COMMENT 'Google Drive file ID',
    `google_mime_type` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_gtf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gtf_file` FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuário master padrão (senha: Admin@123)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role_id`, `storage_quota`, `status`)
VALUES ('admin', 'admin@tcloud.local', '$2y$12$LJ3m4ys3Gql0tOzLwO9KoeBvBFri0FNEkq9bGWJ7jZT3JZf9JhIOi', 'Administrador Master', 1, 10737418240, 'active');

SET FOREIGN_KEY_CHECKS = 1;
