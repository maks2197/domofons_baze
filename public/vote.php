<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/VoteService.php';

session_start();
$db = Database::getInstance();
$voteService = new VoteService();
$config = require __DIR__ . '/../config/config.php';

$entranceId = (int)($_GET['entrance_id'] ?? 0);
$message = '';
$messageType = 'info';

if (!$entranceId) {
    header('Location: index.php');
    exit;
}

$entrance = $db->fetchOne("
    SELECT e.*, b.address, b.city 
    FROM entrances e 
    JOIN buildings b ON e.building_id = b.id 
    WHERE e.id = :id
", ['id' => $entranceId]);

if (!$entrance) {
    header('Location: index.php');
    exit;
}

$stats = $voteService->getVotingStats($entranceId);

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apartmentNumber = trim($_POST['apartment_number'] ?? '');
    $vote = $_POST['vote'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    
    if ($apartmentNumber && in_array($vote, ['yes', 'no']) && $phone) {
        // Find or create user
        $user = $db->fetchOne("SELECT id FROM users WHERE phone = :phone", ['phone' => $phone]);
        
        if (!$user) {
            $userId = $db->insert('users', [
                'full_name' => $_POST['full_name'] ?? '',
                'phone' => $phone,
                'role' => 'resident'
            ]);
        } else {
            $userId = $user['id'];
        }
        
        // Find apartment
        $apartment = $db->fetchOne(
            "SELECT id FROM apartments WHERE entrance_id = :entrance_id AND apartment_number = :number",
            ['entrance_id' => $entranceId, 'number' => $apartmentNumber]
        );
        
        if ($apartment) {
            try {
                $voteService->castVote($entranceId, $apartment['id'], $vote, $userId);
                $message = "Ваш голос принят! Спасибо за участие.";
                $messageType = 'success';
                
                // Refresh stats
                $stats = $voteService->getVotingStats($entranceId);
                
                // Check if protocol was created
                $meeting = $db->fetchOne("SELECT * FROM meetings WHERE entrance_id = :id", ['id' => $entranceId]);
                if ($meeting) {
                    $message = "Голосование завершено! Протокол и договор сформированы автоматически.";
                }
            } catch (Exception $e) {
                $message = "Ошибка при подаче голоса: " . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = "Квартира с таким номером не найдена в этом подъезде";
            $messageType = 'error';
        }
    } else {
        $message = "Заполните все обязательные поля";
        $messageType = 'error';
    }
}

$threshold = $config['app']['voting_threshold'];
$progressPercent = min(100, round(($stats['total_votes'] / $entrance['total_apartments']) * 100, 1));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Голосование - <?= htmlspecialchars($entrance['address']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; line-height: 1.6; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        header { background: #2c3e50; color: white; padding: 20px 0; margin-bottom: 30px; }
        header h1 { text-align: center; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .form-group small { color: #7f8c8d; font-size: 14px; }
        button { background: #3498db; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        button:hover { background: #2980b9; }
        .btn-yes { background: #27ae60; }
        .btn-yes:hover { background: #229954; }
        .btn-no { background: #e74c3c; }
        .btn-no:hover { background: #c0392b; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-info { background: #d6eaf8; color: #2c3e50; }
        .alert-error { background: #fadbd8; color: #c0392b; }
        .alert-success { background: #d5f5e3; color: #27ae60; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat-item { flex: 1; padding: 20px; background: #ecf0f1; border-radius: 4px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #2c3e50; }
        .stat-label { font-size: 14px; color: #7f8c8d; margin-top: 5px; }
        .progress-bar { width: 100%; height: 30px; background: #ecf0f1; border-radius: 15px; overflow: hidden; margin: 15px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #3498db, #27ae60); transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .vote-buttons { display: flex; gap: 20px; margin-top: 20px; }
        .vote-buttons button { flex: 1; padding: 20px; font-size: 18px; font-weight: bold; }
        nav { background: white; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        nav ul { list-style: none; display: flex; gap: 20px; justify-content: center; }
        nav a { text-decoration: none; color: #2c3e50; font-weight: bold; }
        .telegram-link { display: inline-block; margin-top: 15px; padding: 12px 24px; background: #0088cc; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; }
        .telegram-link:hover { background: #0077b5; }
        .threshold-indicator { background: #fff3cd; padding: 15px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?= htmlspecialchars($config['app']['name']) ?></h1>
            <p style="text-align: center;">Голосование за установку домофона</p>
        </div>
    </header>
    
    <nav>
        <div class="container">
            <ul>
                <li><a href="index.php">← Назад к списку</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>📍 <?= htmlspecialchars($entrance['address']) ?>, подъезд №<?= $entrance['entrance_number'] ?></h2>
            <p>Город: <?= htmlspecialchars($entrance['city'] ?? 'Не указан') ?></p>
            <p>Всего квартир: <?= $entrance['total_apartments'] ?></p>
            
            <?php if ($entrance['telegram_invite_link']): ?>
                <a href="<?= htmlspecialchars($entrance['telegram_invite_link']) ?>" class="telegram-link" target="_blank">
                    💬 Присоединиться к чату подъезда в Telegram
                </a>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>📊 Результаты голосования</h2>
            
            <div class="threshold-indicator">
                <strong>Порог для принятия решения:</strong> <?= $threshold ?>% от проголосовавших должны проголосовать "ЗА"
            </div>
            
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-number"><?= $stats['total_votes'] ?> / <?= $entrance['total_apartments'] ?></div>
                    <div class="stat-label">Проголосовало квартир</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #27ae60;"><?= $stats['yes_votes'] ?></div>
                    <div class="stat-label">За установку</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" style="color: #e74c3c;"><?= $stats['no_votes'] ?></div>
                    <div class="stat-label">Против установки</div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Процент "ЗА": <?= $stats['percentage_yes'] ?>%</label>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $stats['percentage_yes'] ?>%">
                        <?= $stats['percentage_yes'] ?>%
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Явка: <?= $progressPercent ?>%</label>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $progressPercent ?>%; background: linear-gradient(90deg, #3498db, #9b59b6);">
                        <?= $progressPercent ?>%
                    </div>
                </div>
            </div>
            
            <?php if ($entrance['status'] === 'completed'): ?>
                <?php
                $meeting = $db->fetchOne("SELECT * FROM meetings WHERE entrance_id = :id", ['id' => $entranceId]);
                if ($meeting):
                ?>
                    <div style="margin-top: 20px; padding: 20px; background: #d5f5e3; border-radius: 4px;">
                        <h3 style="color: #27ae60;">✓ Голосование завершено успешно!</h3>
                        <p>Протокол общего собрания и договор на установку домофона сформированы автоматически.</p>
                        <div style="margin-top: 15px;">
                            <?php if ($meeting['protocol_file_path']): ?>
                                <a href="<?= htmlspecialchars($meeting['protocol_file_path']) ?>" target="_blank" style="display: inline-block; margin-right: 15px; color: #2c3e50; font-weight: bold;">📄 Скачать протокол</a>
                            <?php endif; ?>
                            <?php if ($meeting['contract_file_path']): ?>
                                <a href="<?= htmlspecialchars($meeting['contract_file_path']) ?>" target="_blank" style="display: inline-block; color: #2c3e50; font-weight: bold;">📄 Скачать договор</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($entrance['status'] !== 'completed'): ?>
        <div class="card">
            <h2>🗳️ Проголосовать</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="full_name">ФИО</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Иванов Иван Иванович">
                </div>
                <div class="form-group">
                    <label for="phone">Номер телефона *</label>
                    <input type="tel" id="phone" name="phone" required placeholder="+7 (999) 123-45-67">
                    <small>По телефону мы сможем связаться с вами для уточнения деталей</small>
                </div>
                <div class="form-group">
                    <label for="apartment_number">Номер квартиры *</label>
                    <input type="text" id="apartment_number" name="apartment_number" required placeholder="12">
                </div>
                <div class="form-group">
                    <label>Ваше решение *</label>
                    <div class="vote-buttons">
                        <button type="submit" name="vote" value="yes" class="btn-yes">
                            ✅ ЗА установку домофона
                        </button>
                        <button type="submit" name="vote" value="no" class="btn-no">
                            ❌ ПРОТИВ установки домофона
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
