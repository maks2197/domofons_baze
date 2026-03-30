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
    FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE
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

-- MAX Messenger integration tables
CREATE TABLE IF NOT EXISTS max_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT NOT NULL,
    group_id VARCHAR(100) NOT NULL,
    group_name VARCHAR(255),
    invite_link VARCHAR(500),
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE CASCADE,
    UNIQUE KEY unique_group (group_id)
);

CREATE TABLE IF NOT EXISTS max_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrance_id INT,
    user_id INT,
    payment_id INT,
    meeting_id INT,
    notification_type ENUM('voting_results', 'payment_confirmation', 'meeting_announcement', 'broadcast', 'system') DEFAULT 'system',
    message_text TEXT,
    recipients_count INT DEFAULT 1,
    status ENUM('sent', 'partial', 'failed') DEFAULT 'sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS user_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    max_user_id VARCHAR(100),
    telegram_user_id VARCHAR(100),
    whatsapp_number VARCHAR(20),
    viber_number VARCHAR(20),
    notification_preferences JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user (user_id)
);

CREATE TABLE IF NOT EXISTS user_entrances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    entrance_id INT NOT NULL,
    apartment_id INT,
    is_owner BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (entrance_id) REFERENCES entrances(id) ON DELETE CASCADE,
    FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_entrance (user_id, entrance_id)
);

CREATE TABLE IF NOT EXISTS meeting_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    user_id INT NOT NULL,
    response ENUM('confirmed', 'declined', 'maybe') DEFAULT 'confirmed',
    source ENUM('web', 'max_messenger', 'telegram', 'whatsapp') DEFAULT 'web',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_meeting_user (meeting_id, user_id)
);

CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_category ENUM('general', 'design', 'notifications', 'moderation', 'payments', 'integrations') DEFAULT 'general',
    setting_type ENUM('string', 'number', 'boolean', 'json', 'color', 'file') DEFAULT 'string',
    description VARCHAR(255),
    is_public BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS moderation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_type ENUM('message', 'review', 'question', 'vote', 'payment') NOT NULL,
    content_id INT NOT NULL,
    user_id INT,
    action ENUM('approved', 'rejected', 'flagged', 'edited', 'deleted') NOT NULL,
    reason TEXT,
    moderator_id INT,
    ai_provider ENUM('gigachat', 'yandex', 'manual'),
    ai_score DECIMAL(3,2),
    previous_content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS admin_actions_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_description TEXT,
    target_table VARCHAR(50),
    target_id INT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS website_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) UNIQUE NOT NULL,
    page_title VARCHAR(255),
    page_content LONGTEXT,
    meta_description TEXT,
    meta_keywords TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    custom_css TEXT,
    custom_js TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(50) NOT NULL,
    recipient_type ENUM('user', 'entrance', 'all', 'custom') DEFAULT 'user',
    recipient_ids JSON,
    message_title VARCHAR(255),
    message_body TEXT NOT NULL,
    channels JSON,
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    status ENUM('pending', 'processing', 'sent', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Add MAX messenger columns to entrances table
ALTER TABLE entrances ADD COLUMN IF NOT EXISTS max_group_id VARCHAR(100);
ALTER TABLE entrances ADD COLUMN IF NOT EXISTS max_invite_link VARCHAR(500);

-- Insert default MAX messenger settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('max_api_url', 'https://api.max-messenger.ru/v1', 'string', 'MAX Messenger API URL'),
('max_bot_token', '', 'string', 'MAX Messenger Bot Token'),
('max_bot_secret', '', 'string', 'MAX Messenger Bot Secret')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Insert default site settings
INSERT INTO site_settings (setting_key, setting_value, setting_category, setting_type, description, is_public) VALUES
('site_title', 'ДомофонПро - Установка домофонов', 'general', 'string', 'Заголовок сайта', TRUE),
('site_logo', '/public/logo.png', 'design', 'file', 'Логотип сайта', TRUE),
('primary_color', '#3498db', 'design', 'color', 'Основной цвет темы', TRUE),
('secondary_color', '#2c3e50', 'design', 'color', 'Вторичный цвет темы', TRUE),
('enable_registration', '1', 'general', 'boolean', 'Разрешить регистрацию пользователей', FALSE),
('require_phone_verification', '1', 'moderation', 'boolean', 'Требовать подтверждение телефона', FALSE),
('auto_approve_reviews', '0', 'moderation', 'boolean', 'Автоматически одобрять отзывы', FALSE),
('min_review_length', '20', 'moderation', 'number', 'Минимальная длина отзыва', FALSE),
('enable_ai_moderation', '1', 'moderation', 'boolean', 'Включить AI модерацию', FALSE),
('ai_moderation_threshold', '0.7', 'moderation', 'number', 'Порог токсичности для блокировки', FALSE),
('maintenance_mode', '0', 'general', 'boolean', 'Режим обслуживания', FALSE),
('maintenance_message', 'Сайт находится на техническом обслуживании', 'general', 'string', 'Сообщение в режиме обслуживания', TRUE),
('contact_email', 'info@domofonpro.ru', 'general', 'string', 'Контактный email', TRUE),
('contact_phone', '+7 (999) 000-00-00', 'general', 'string', 'Контактный телефон', TRUE),
('working_hours', 'Пн-Пт: 9:00-18:00', 'general', 'string', 'Часы работы', TRUE),
('enable_newsletter', '1', 'notifications', 'boolean', 'Включить рассылку новостей', FALSE),
('newsletter_frequency', 'weekly', 'notifications', 'string', 'Частота рассылки', FALSE),
('enable_sms_notifications', '0', 'notifications', 'boolean', 'Включить SMS уведомления', FALSE),
('sms_api_key', '', 'integrations', 'string', 'API ключ SMS сервиса', FALSE),
('enable_email_notifications', '1', 'notifications', 'boolean', 'Включить email уведомления', FALSE),
('smtp_host', 'smtp.mail.ru', 'integrations', 'string', 'SMTP хост', FALSE),
('smtp_port', '465', 'integrations', 'number', 'SMTP порт', FALSE),
('smtp_user', '', 'integrations', 'string', 'SMTP пользователь', FALSE),
('smtp_password', '', 'integrations', 'string', 'SMTP пароль', FALSE),
('enable_whatsapp', '0', 'integrations', 'boolean', 'Включить WhatsApp уведомления', FALSE),
('whatsapp_api_key', '', 'integrations', 'string', 'API ключ WhatsApp', FALSE),
('enable_viber', '0', 'integrations', 'boolean', 'Включить Viber уведомления', FALSE),
('viber_api_key', '', 'integrations', 'string', 'API ключ Viber', FALSE),
('payment_min_amount', '100', 'payments', 'number', 'Минимальная сумма платежа', FALSE),
('payment_commission', '0', 'payments', 'number', 'Комиссия за платеж (%)', FALSE),
('enable_installments', '0', 'payments', 'boolean', 'Включить рассрочку', FALSE),
('installments_months', '12', 'payments', 'number', 'Срок рассочки (месяцев)', FALSE),
('footer_text', '© 2024 ДомофонПро. Все права защищены.', 'design', 'string', 'Текст в футере', TRUE),
('privacy_policy_url', '/privacy', 'general', 'string', 'URL политики конфиденциальности', TRUE),
('terms_of_service_url', '/terms', 'general', 'string', 'URL условий использования', TRUE);

-- Insert default pages
INSERT INTO website_pages (page_slug, page_title, page_content, meta_description, is_active) VALUES
('about', 'О компании', '<h1>О компании ДомофонПро</h1><p>Мы занимаемся установкой современных домофонных систем с 2010 года.</p>', 'О компании ДомофонПро - установка домофонов', TRUE),
('privacy', 'Политика конфиденциальности', '<h1>Политика конфиденциальности</h1><p>Ваши данные под надежной защитой.</p>', 'Политика конфиденциальности', TRUE),
('terms', 'Условия использования', '<h1>Условия использования сервиса</h1><p>Правила использования нашего сервиса.</p>', 'Условия использования', TRUE),
('contacts', 'Контакты', '<h1>Контакты</h1><p>Свяжитесь с нами любым удобным способом.</p>', 'Контакты ДомофонПро', TRUE);

-- Add indexes for new tables
CREATE INDEX idx_max_notifications_type ON max_notifications(notification_type);
CREATE INDEX idx_max_notifications_status ON max_notifications(status);
CREATE INDEX idx_moderation_logs_content ON moderation_logs(content_type, content_id);
CREATE INDEX idx_admin_actions_admin ON admin_actions_log(admin_id);
CREATE INDEX idx_notifications_queue_status ON notifications_queue(status);
CREATE INDEX idx_user_entrances_user ON user_entrances(user_id);
