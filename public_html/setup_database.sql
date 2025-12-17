-- Database setup for privateradiomanager
-- Run this script to create the necessary tables
-- Note: Database name should be specified when running this script
-- Example: mysql -u <db_user> -p <db_name> < setup_database.sql

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    is_blocked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- Invites table
CREATE TABLE IF NOT EXISTS invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invited_by INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    invite_token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (invite_token),
    INDEX idx_email (email)
);

-- Daily invite tracking
CREATE TABLE IF NOT EXISTS daily_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    invite_date DATE NOT NULL,
    invite_count INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_date (user_id, invite_date)
);

-- Login attempts (for security)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50),
    success BOOLEAN DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_attempted_at (attempted_at)
);

-- Create initial admin users
-- Default admin user (password: admin123 - CHANGE THIS!)
INSERT IGNORE INTO users (username, email, password_hash, is_admin) VALUES 
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

-- Clean up old login attempts (keep only last 30 days)
DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id)
);

-- Specials schedule table
CREATE TABLE IF NOT EXISTS specials_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    full_path VARCHAR(1024) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status ENUM('scheduled','queued','done','canceled','error') NOT NULL DEFAULT 'scheduled',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    queued_at TIMESTAMP NULL,
    canceled_at TIMESTAMP NULL,
    last_error TEXT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_scheduled_at (scheduled_at),
    INDEX idx_status_scheduled_at (status, scheduled_at)
);

-- Clean up expired invites
DELETE FROM invites WHERE expires_at < NOW() AND used_at IS NULL;

-- Clean up expired password reset tokens
DELETE FROM password_reset_tokens WHERE expires_at < NOW() AND used_at IS NULL;
