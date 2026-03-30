-- Database schema for Intercom Voting System

CREATE DATABASE IF NOT EXISTS intercom_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE intercom_system;

-- Users table (residents, admins)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(255),
    password_hash VARCHAR(255),
    role ENUM('admin', 'resident') DEFAULT 'resident',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Buildings table
CREATE TABLE buildings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    address VARCHAR(500) NOT NULL,
    city VARCHAR(100),
    total_entrances INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Entrances table
CREATE TABLE entrances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    building_id INT NOT NULL,
    entrance_number INT NOT NULL,
    total_apartments INT NOT NULL,
    telegram_chat_id VARCHAR(100),
    telegram_invite_link VARCHAR(500),
    status ENUM('active', 'voting', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entrance (building_id, entrance_number)
);

-- Apartments table
CREATE TABLE apartments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT NOT NULL,
    apartment_number VARCHAR(20) NOT NULL,
    user_id INT,
    vote_status ENUM('pending', 'yes', 'no') DEFAULT 'pending',
    voted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_apartment (entrance_id, apartment_number)
);

-- Votes table
CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT NOT NULL,
    user_id INT,
    apartment_id INT NOT NULL,
    vote ENUM('yes', 'no') NOT NULL,
    vote_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_vote_per_apartment (entrance_id, apartment_id)
);

-- Meetings/Protocols table
CREATE TABLE meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT NOT NULL,
    meeting_date DATE NOT NULL,
    protocol_number VARCHAR(50),
    total_votes INT NOT NULL,
    yes_votes INT NOT NULL,
    no_votes INT NOT NULL,
    percentage_yes DECIMAL(5,2),
    status ENUM('draft', 'approved', 'rejected') DEFAULT 'draft',
    protocol_file_path VARCHAR(500),
    contract_file_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE CASCADE
);

-- Payments table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    entrance_id INT,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'RUB',
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    yookassa_payment_id VARCHAR(100),
    payment_method VARCHAR(50),
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE SET NULL
);

-- Messages table (for AI moderation)
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT,
    user_id INT,
    message_text TEXT NOT NULL,
    ai_moderation_status ENUM('pending', 'approved', 'rejected', 'flagged') DEFAULT 'pending',
    ai_score DECIMAL(3,2),
    ai_provider ENUM('gigachat', 'yandex') DEFAULT 'gigachat',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Reviews table
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    building_id INT,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    ai_moderation_status ENUM('pending', 'approved', 'rejected', 'flagged') DEFAULT 'pending',
    ai_score DECIMAL(3,2),
    ai_provider VARCHAR(50) NOT NULL DEFAULT 'giga',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL
);

-- Questions/Support tickets table
CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    subject VARCHAR(255),
    question_text TEXT NOT NULL,
    ai_suggested_answer TEXT,
    ai_moderation_status ENUM('pending', 'approved', 'rejected', 'flagged') DEFAULT 'pending',
    ai_score DECIMAL(3,2),
    status ENUM('open', 'in_progress', 'closed') DEFAULT 'open',
    admin_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Settings table (for backend configuration)
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('voting_threshold', '51', 'number', 'Percentage threshold for voting approval'),
('telegram_bot_token', '', 'string', 'Telegram bot token for notifications'),
('yookassa_shop_id', '', 'string', 'YooKassa shop ID'),
('yookassa_secret_key', '', 'string', 'YooKassa secret key'),
('gigachat_client_id', '', 'string', 'GigaChat client ID'),
('gigachat_client_secret', '', 'string', 'GigaChat client secret'),
('yandex_api_key', '', 'string', 'Yandex API key for AI moderation');

-- Indexes for performance
CREATE INDEX idx_entrance_status ON entrances(status);
CREATE INDEX idx_vote_date ON votes(vote_date);
CREATE INDEX idx_payment_status ON payments(payment_status);
CREATE INDEX idx_message_moderation ON messages(ai_moderation_status);