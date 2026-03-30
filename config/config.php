<?php
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'intercom_system',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'app' => [
        'name' => getenv('APP_NAME') ?: 'ДомофонПро',
        'url' => getenv('APP_URL') ?: 'http://localhost',
        'telegram_bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'telegram_chat_id' => getenv('TELEGRAM_CHAT_ID') ?: '',
        'yookassa_shop_id' => getenv('YOOKASSA_SHOP_ID') ?: '',
        'yookassa_secret_key' => getenv('YOOKASSA_SECRET_KEY') ?: '',
        'gigachat_client_id' => getenv('GIGACHAT_CLIENT_ID') ?: '',
        'gigachat_client_secret' => getenv('GIGACHAT_CLIENT_SECRET') ?: '',
        'yandex_api_key' => getenv('YANDEX_API_KEY') ?: '',
        'yandex_folder_id' => getenv('YANDEX_FOLDER_ID') ?: '',
        'voting_threshold' => (int)(getenv('VOTING_THRESHOLD') ?: 51),
    ],
];