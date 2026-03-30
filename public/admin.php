<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/VoteService.php';
require_once __DIR__ . '/../src/PaymentService.php';
require_once __DIR__ . '/../src/AIModerationService.php';

session_start();

// Simple admin authentication (in production, use proper auth)
$isAdmin = false;
if (isset($_POST['admin_login'])) {
    $password = $_POST['password'] ?? '';
    // In production, use proper password hashing and database storage
    if ($password === 'admin123') { // Change this!
        $_SESSION['is_admin'] = true;
        $_SESSION['role'] = 'admin';
        $isAdmin = true;
    }
}

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $isAdmin = true;
}

if (!$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Вход для администратора</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-form { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
            h2 { text-align: center; color: #2c3e50; margin-bottom: 30px; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
            input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
            button { width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
            button:hover { background: #2980b9; }
            .alert { padding: 10px; border-radius: 4px; margin-bottom: 20px; background: #fadbd8; color: #c0392b; }
            a { display: block; text-align: center; margin-top: 20px; color: #3498db; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="login-form">
            <h2>🔐 Вход для администратора</h2>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert">Неверный пароль</div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" name="admin_login">Войти</button>
            </form>
            <a href="index.php">← Вернуться на главную</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = Database::getInstance();
$voteService = new VoteService();
$paymentService = new PaymentService();
$aiService = new AIModerationService();
$config = require __DIR__ . '/../config/config.php';

$message = '';
$messageType = 'info';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $settingsToUpdate = [
        'voting_threshold',
        'telegram_bot_token',
        'yookassa_shop_id',
        'yookassa_secret_key',
        'gigachat_client_id',
        'gigachat_client_secret',
        'yandex_api_key'
    ];
    
    foreach ($settingsToUpdate as $key) {
        if (isset($_POST[$key])) {
            $db->update('settings', [
                'setting_value' => $_POST[$key]
            ], 'setting_key = :key', ['key' => $key]);
        }
    }
    
    $message = "Настройки успешно сохранены";
    $messageType = 'success';
}

// Get all settings
$settingsRows = $db->fetchAll("SELECT * FROM settings");
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get statistics
$stats = [
    'total_buildings' => $db->fetchOne("SELECT COUNT(*) as count FROM buildings")['count'],
    'total_entrances' => $db->fetchOne("SELECT COUNT(*) as count FROM entrances")['count'],
    'total_votes' => $db->fetchOne("SELECT COUNT(*) as count FROM votes")['count'],
    'completed_votes' => $db->fetchOne("SELECT COUNT(*) as count FROM entrances WHERE status = 'completed'")['count'],
    'total_payments' => $db->fetchOne("SELECT SUM(amount) as total FROM payments WHERE payment_status = 'paid'")['total'] ?? 0,
    'pending_messages' => $db->fetchOne("SELECT COUNT(*) as count FROM messages WHERE ai_moderation_status = 'pending'")['count'],
    'pending_questions' => $db->fetchOne("SELECT COUNT(*) as count FROM questions WHERE status = 'open'")['count']
];

// Get recent activities
$recentEntrances = $db->fetchAll("
    SELECT e.*, b.address 
    FROM entrances e 
    JOIN buildings b ON e.building_id = b.id 
    ORDER BY e.created_at DESC 
    LIMIT 10
");

$recentPayments = $db->fetchAll("
    SELECT p.*, u.full_name, e.entrance_number 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    LEFT JOIN entrances e ON p.entrance_id = e.id 
    ORDER BY p.created_at DESC 
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - <?= htmlspecialchars($config['app']['name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; line-height: 1.6; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        header { background: #2c3e50; color: white; padding: 20px 0; margin-bottom: 30px; }
        header h1 { text-align: center; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2c3e50 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%); }
        .stat-number { font-size: 36px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 14px; opacity: 0.9; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-group small { color: #7f8c8d; font-size: 12px; }
        button { background: #3498db; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-info { background: #d6eaf8; color: #2c3e50; }
        .alert-success { background: #d5f5e3; color: #27ae60; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: bold; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-paid { background: #d5f5e3; color: #27ae60; }
        .status-pending { background: #fef9e7; color: #f39c12; }
        .status-failed { background: #fadbd8; color: #e74c3c; }
        nav { background: white; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        nav ul { list-style: none; display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        nav a { text-decoration: none; color: #2c3e50; font-weight: bold; padding: 8px 16px; border-radius: 4px; }
        nav a:hover { background: #ecf0f1; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        .tab { padding: 10px 20px; background: #ecf0f1; border-radius: 4px; cursor: pointer; text-decoration: none; color: #2c3e50; font-weight: bold; }
        .tab.active { background: #3498db; color: white; }
        .logout { position: absolute; top: 20px; right: 20px; background: #e74c3c; padding: 8px 16px; border-radius: 4px; color: white; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <a href="?logout=1" class="logout">Выйти</a>
    
    <header>
        <div class="container">
            <h1>🔧 Админ-панель - <?= htmlspecialchars($config['app']['name']) ?></h1>
        </div>
    </header>
    
    <nav>
        <div class="container">
            <ul>
                <li><a href="index.php">Главная</a></li>
                <li><a href="admin.php?tab=settings">Настройки</a></li>
                <li><a href="admin.php?tab=entrances">Подъезды</a></li>
                <li><a href="admin.php?tab=payments">Платежи</a></li>
                <li><a href="admin.php?tab=moderation">Модерация</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php
        $activeTab = $_GET['tab'] ?? 'overview';
        
        if ($activeTab === 'overview' || $activeTab === ''):
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_buildings'] ?></div>
                <div class="stat-label">Домов</div>
            </div>
            <div class="stat-card green">
                <div class="stat-number"><?= $stats['total_entrances'] ?></div>
                <div class="stat-label">Подъездов</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-number"><?= $stats['total_votes'] ?></div>
                <div class="stat-label">Голосов</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-number"><?= $stats['completed_votes'] ?></div>
                <div class="stat-label">Завершено голосований</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="stat-number"><?= number_format($stats['total_payments'], 0, '.', ' ') ?> ₽</div>
                <div class="stat-label">Оплачено</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="stat-number"><?= $stats['pending_questions'] ?></div>
                <div class="stat-label">Вопросов на ответ</div>
            </div>
        </div>
        
        <div class="card">
            <h2>📊 Последние подъезды</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Адрес</th>
                        <th>Подъезд</th>
                        <th>Квартир</th>
                        <th>Статус</th>
                        <th>Дата создания</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentEntrances as $entrance): ?>
                        <tr>
                            <td><?= $entrance['id'] ?></td>
                            <td><?= htmlspecialchars($entrance['address']) ?></td>
                            <td>№<?= $entrance['entrance_number'] ?></td>
                            <td><?= $entrance['total_apartments'] ?></td>
                            <td>
                                <span class="status-badge status-<?= $entrance['status'] ?>">
                                    <?= $entrance['status'] ?>
                                </span>
                            </td>
                            <td><?= date('d.m.Y H:i', strtotime($entrance['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php elseif ($activeTab === 'settings'): ?>
        <div class="card">
            <h2>⚙️ Настройки системы</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="voting_threshold">Порог голосования (%)</label>
                    <input type="number" id="voting_threshold" name="voting_threshold" value="<?= htmlspecialchars($settings['voting_threshold'] ?? 51) ?>" min="1" max="100">
                    <small>Процент голосов "ЗА" для автоматического создания протокола</small>
                </div>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #ecf0f1;">
                <h3 style="margin-bottom: 15px;">Telegram</h3>
                <div class="form-group">
                    <label for="telegram_bot_token">Telegram Bot Token</label>
                    <input type="text" id="telegram_bot_token" name="telegram_bot_token" value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>">
                    <small>Token от @BotFather для создания чатов</small>
                </div>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #ecf0f1;">
                <h3 style="margin-bottom: 15px;">YooKassa</h3>
                <div class="form-group">
                    <label for="yookassa_shop_id">YooKassa Shop ID</label>
                    <input type="text" id="yookassa_shop_id" name="yookassa_shop_id" value="<?= htmlspecialchars($settings['yookassa_shop_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="yookassa_secret_key">YooKassa Secret Key</label>
                    <input type="password" id="yookassa_secret_key" name="yookassa_secret_key" value="<?= htmlspecialchars($settings['yookassa_secret_key'] ?? '') ?>">
                </div>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #ecf0f1;">
                <h3 style="margin-bottom: 15px;">AI Модерация (GigaChat)</h3>
                <div class="form-group">
                    <label for="gigachat_client_id">GigaChat Client ID</label>
                    <input type="text" id="gigachat_client_id" name="gigachat_client_id" value="<?= htmlspecialchars($settings['gigachat_client_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="gigachat_client_secret">GigaChat Client Secret</label>
                    <input type="password" id="gigachat_client_secret" name="gigachat_client_secret" value="<?= htmlspecialchars($settings['gigachat_client_secret'] ?? '') ?>">
                </div>
                <hr style="margin: 20px 0; border: none; border-top: 1px solid #ecf0f1;">
                <h3 style="margin-bottom: 15px;">AI Модерация (Yandex)</h3>
                <div class="form-group">
                    <label for="yandex_api_key">Yandex API Key</label>
                    <input type="password" id="yandex_api_key" name="yandex_api_key" value="<?= htmlspecialchars($settings['yandex_api_key'] ?? '') ?>">
                </div>
                <button type="submit" name="update_settings" class="btn-success">Сохранить настройки</button>
            </form>
        </div>
        
        <?php elseif ($activeTab === 'payments'): ?>
        <div class="card">
            <h2>💳 Последние платежи</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Плательщик</th>
                        <th>Подъезд</th>
                        <th>Сумма</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?= $payment['id'] ?></td>
                            <td><?= htmlspecialchars($payment['full_name'] ?? 'Аноним') ?></td>
                            <td>№<?= $payment['entrance_number'] ?? '-' ?></td>
                            <td><?= number_format($payment['amount'], 2) ?> ₽</td>
                            <td>
                                <span class="status-badge status-<?= $payment['payment_status'] ?>">
                                    <?= $payment['payment_status'] ?>
                                </span>
                            </td>
                            <td><?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>

<?php
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}
?>
