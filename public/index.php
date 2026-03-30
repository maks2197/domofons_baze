<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/VoteService.php';
require_once __DIR__ . '/../src/PaymentService.php';
require_once __DIR__ . '/../src/AIModerationService.php';

session_start();

$db = Database::getInstance();
$voteService = new VoteService();
$config = require __DIR__ . '/../config/config.php';

// Handle form submission for creating entrance
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_entrance':
                $address = trim($_POST['address'] ?? '');
                $city = trim($_POST['city'] ?? '');
                $entranceNumber = (int)($_POST['entrance_number'] ?? 0);
                $totalApartments = (int)($_POST['total_apartments'] ?? 0);
                
                if ($address && $entranceNumber > 0 && $totalApartments > 0) {
                    // Find or create building
                    $building = $db->fetchOne("SELECT id FROM buildings WHERE address = :address", ['address' => $address]);
                    
                    if (!$building) {
                        $buildingId = $db->insert('buildings', [
                            'address' => $address,
                            'city' => $city,
                            'total_entrances' => 1
                        ]);
                    } else {
                        $buildingId = $building['id'];
                    }
                    
                    // Create entrance
                    $entranceId = $voteService->createEntrance($buildingId, $entranceNumber, $totalApartments);
                    
                    // Generate apartment numbers
                    $apartments = range(1, $totalApartments);
                    $voteService->addApartments($entranceId, $apartments);
                    
                    // Generate Telegram invite
                    $telegramLink = $voteService->generateTelegramInvite($entranceId);
                    
                    $message = "Подъезд успешно создан! ID: {$entranceId}. Ссылка на чат: {$telegramLink}";
                } else {
                    $message = "Заполните все обязательные поля";
                }
                break;
                
            case 'cast_vote':
                $entranceId = (int)($_POST['entrance_id'] ?? 0);
                $apartmentNumber = trim($_POST['apartment_number'] ?? '');
                $vote = $_POST['vote'] ?? '';
                
                if ($entranceId && $apartmentNumber && in_array($vote, ['yes', 'no'])) {
                    $apartment = $db->fetchOne(
                        "SELECT id FROM apartments WHERE entrance_id = :entrance_id AND apartment_number = :number",
                        ['entrance_id' => $entranceId, 'number' => $apartmentNumber]
                    );
                    
                    if ($apartment) {
                        $voteService->castVote($entranceId, $apartment['id'], $vote);
                        $message = "Ваш голос принят!";
                    } else {
                        $message = "Квартира не найдена";
                    }
                } else {
                    $message = "Заполните все поля";
                }
                break;
        }
    } catch (Exception $e) {
        $message = "Ошибка: " . $e->getMessage();
    }
}

// Get all entrances with voting stats
$entrances = $db->fetchAll("
    SELECT e.*, b.address, b.city,
           (SELECT COUNT(*) FROM votes WHERE entrance_id = e.id) as vote_count,
           (SELECT SUM(CASE WHEN vote = 'yes' THEN 1 ELSE 0 END) FROM votes WHERE entrance_id = e.id) as yes_count
    FROM entrances e
    JOIN buildings b ON e.building_id = b.id
    ORDER BY e.created_at DESC
");

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app']['name']) ?> - Установка домофонов</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; line-height: 1.6; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        header { background: #2c3e50; color: white; padding: 20px 0; margin-bottom: 30px; }
        header h1 { text-align: center; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; 
        }
        button { 
            background: #3498db; color: white; border: none; padding: 12px 24px; 
            border-radius: 4px; cursor: pointer; font-size: 16px; 
        }
        button:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-info { background: #d6eaf8; color: #2c3e50; }
        .alert-error { background: #fadbd8; color: #c0392b; }
        .alert-success { background: #d5f5e3; color: #27ae60; }
        .stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat-item { flex: 1; padding: 15px; background: #ecf0f1; border-radius: 4px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #2c3e50; }
        .status-badge { 
            display: inline-block; padding: 5px 10px; border-radius: 4px; 
            font-size: 14px; font-weight: bold; 
        }
        .status-active { background: #d5f5e3; color: #27ae60; }
        .status-voting { background: #fef9e7; color: #f39c12; }
        .status-completed { background: #d6eaf8; color: #3498db; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: bold; }
        .progress-bar { width: 100%; height: 20px; background: #ecf0f1; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: #27ae60; transition: width 0.3s; }
        nav { background: white; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        nav ul { list-style: none; display: flex; gap: 20px; justify-content: center; }
        nav a { text-decoration: none; color: #2c3e50; font-weight: bold; }
        nav a:hover { color: #3498db; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?= htmlspecialchars($config['app']['name']) ?></h1>
            <p style="text-align: center;">Система голосования за установку домофонов</p>
        </div>
    </header>
    
    <nav>
        <div class="container">
            <ul>
                <li><a href="index.php">Главная</a></li>
                <li><a href="vote.php">Голосование</a></li>
                <li><a href="admin.php">Админ-панель</a></li>
                <li><a href="reviews.php">Отзывы</a></li>
                <li><a href="questions.php">Вопросы</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Создать новый подъезд для голосования</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_entrance">
                <div class="form-group">
                    <label for="address">Адрес дома *</label>
                    <input type="text" id="address" name="address" required placeholder="ул. Примерная, д. 1">
                </div>
                <div class="form-group">
                    <label for="city">Город</label>
                    <input type="text" id="city" name="city" placeholder="Москва">
                </div>
                <div class="form-group">
                    <label for="entrance_number">Номер подъезда *</label>
                    <input type="number" id="entrance_number" name="entrance_number" required min="1">
                </div>
                <div class="form-group">
                    <label for="total_apartments">Количество квартир в подъезде *</label>
                    <input type="number" id="total_apartments" name="total_apartments" required min="1">
                </div>
                <button type="submit" class="btn-success">Создать и пригласить жильцов</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Активные голосования</h2>
            <?php if (empty($entrances)): ?>
                <p>Пока нет активных голосований.</p>
            <?php else: ?>
                <?php foreach ($entrances as $entrance): ?>
                    <?php
                    $percentage = $entrance['total_apartments'] > 0 
                        ? round(($entrance['vote_count'] / $entrance['total_apartments']) * 100, 1) 
                        : 0;
                    $yesPercentage = $entrance['vote_count'] > 0 
                        ? round(($entrance['yes_count'] / $entrance['vote_count']) * 100, 1) 
                        : 0;
                    ?>
                    <div style="border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 4px;">
                        <h3>
                            <?= htmlspecialchars($entrance['address']) ?>, подъезд №<?= $entrance['entrance_number'] ?>
                            <span class="status-badge status-<?= $entrance['status'] ?>">
                                <?= $entrance['status'] === 'active' ? 'Активно' : ($entrance['status'] === 'completed' ? 'Завершено' : 'Голосование') ?>
                            </span>
                        </h3>
                        <p>Город: <?= htmlspecialchars($entrance['city'] ?? 'Не указан') ?></p>
                        <p>Всего квартир: <?= $entrance['total_apartments'] ?></p>
                        
                        <div class="stats">
                            <div class="stat-item">
                                <div class="stat-number"><?= $entrance['vote_count'] ?> / <?= $entrance['total_apartments'] ?></div>
                                <div>Проголосовало</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= $yesPercentage ?>%</div>
                                <div>За установку</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Прогресс голосования (<?= $percentage ?>%)</label>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                        
                        <?php if ($entrance['status'] !== 'completed'): ?>
                            <a href="vote.php?entrance_id=<?= $entrance['id'] ?>" style="display: inline-block; margin-top: 10px; color: #3498db; text-decoration: none; font-weight: bold;">
                                Проголосовать →
                            </a>
                        <?php else: ?>
                            <p style="color: #27ae60; font-weight: bold; margin-top: 10px;">✓ Голосование завершено успешно!</p>
                            <?php
                            $meeting = $db->fetchOne("SELECT * FROM meetings WHERE entrance_id = :id", ['id' => $entrance['id']]);
                            if ($meeting):
                            ?>
                                <div style="margin-top: 10px;">
                                    <?php if ($meeting['protocol_file_path']): ?>
                                        <a href="<?= htmlspecialchars($meeting['protocol_file_path']) ?>" target="_blank" style="color: #2c3e50;">📄 Скачать протокол</a>
                                    <?php endif; ?>
                                    <?php if ($meeting['contract_file_path']): ?>
                                        <a href="<?= htmlspecialchars($meeting['contract_file_path']) ?>" target="_blank" style="color: #2c3e50; margin-left: 15px;">📄 Скачать договор</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
