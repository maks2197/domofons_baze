<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AIModerationService.php';

session_start();
$db = Database::getInstance();
$aiService = new AIModerationService();
$config = require __DIR__ . '/../config/config.php';

$message = '';
$messageType = 'info';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewText = trim($_POST['review_text'] ?? '');
    $rating = (int)($_POST['rating'] ?? 5);
    $buildingId = (int)($_POST['building_id'] ?? 0);
    
    if ($reviewText && $buildingId) {
        // Get user ID (or create anonymous)
        $userId = $_SESSION['user_id'] ?? null;
        
        try {
            $result = $aiService->moderateReview($userId ?: 0, $buildingId, $reviewText, $rating);
            
            if ($result['approved']) {
                $message = "Ваш отзыв успешно добавлен и пройдет модерацию.";
                $messageType = 'success';
            } else {
                $message = "Ваш отзыв содержит недопустимый контент и будет проверен модератором.";
                $messageType = 'info';
            }
        } catch (Exception $e) {
            $message = "Ошибка при отправке отзыва: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Заполните все обязательные поля";
        $messageType = 'error';
    }
}

// Get all reviews
$reviews = $db->fetchAll("
    SELECT r.*, u.full_name, b.address 
    FROM reviews r 
    LEFT JOIN users u ON r.user_id = u.id 
    LEFT JOIN buildings b ON r.building_id = b.id 
    WHERE r.ai_moderation_status = 'approved'
    ORDER BY r.created_at DESC
    LIMIT 50
");

// Get buildings for dropdown
$buildings = $db->fetchAll("SELECT id, address FROM buildings ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отзывы - <?= htmlspecialchars($config['app']['name']) ?></title>
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
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .form-group textarea { min-height: 120px; resize: vertical; }
        button { background: #3498db; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #2980b9; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-info { background: #d6eaf8; color: #2c3e50; }
        .alert-error { background: #fadbd8; color: #c0392b; }
        .alert-success { background: #d5f5e3; color: #27ae60; }
        .review { border-left: 4px solid #3498db; padding: 15px; margin: 15px 0; background: #f8f9fa; border-radius: 4px; }
        .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .review-author { font-weight: bold; color: #2c3e50; }
        .review-date { color: #7f8c8d; font-size: 14px; }
        .stars { color: #f39c12; font-size: 20px; }
        .review-text { color: #34495e; line-height: 1.8; }
        .review-address { color: #7f8c8d; font-size: 14px; margin-top: 8px; }
        nav { background: white; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        nav ul { list-style: none; display: flex; gap: 20px; justify-content: center; }
        nav a { text-decoration: none; color: #2c3e50; font-weight: bold; }
        .rating-select { display: flex; gap: 5px; direction: rtl; }
        .rating-select input { display: none; }
        .rating-select label { cursor: pointer; font-size: 24px; color: #ddd; transition: color 0.2s; }
        .rating-select label:hover,
        .rating-select label:hover ~ label,
        .rating-select input:checked ~ label { color: #f39c12; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?= htmlspecialchars($config['app']['name']) ?></h1>
            <p style="text-align: center;">Отзывы наших клиентов</p>
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
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>✍️ Оставить отзыв</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="building_id">Адрес дома</label>
                    <select id="building_id" name="building_id" required>
                        <option value="">Выберите дом</option>
                        <?php foreach ($buildings as $building): ?>
                            <option value="<?= $building['id'] ?>"><?= htmlspecialchars($building['address']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Оценка</label>
                    <div class="rating-select">
                        <input type="radio" id="star5" name="rating" value="5" checked>
                        <label for="star5">★</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4">★</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3">★</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2">★</label>
                        <input type="radio" id="star1" name="rating" value="1">
                        <label for="star1">★</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="review_text">Текст отзыва *</label>
                    <textarea id="review_text" name="review_text" required placeholder="Расскажите о вашем опыте установки домофона..."></textarea>
                </div>
                <button type="submit">Отправить отзыв</button>
            </form>
        </div>
        
        <div class="card">
            <h2>📝 Последние отзывы</h2>
            <?php if (empty($reviews)): ?>
                <p>Пока нет отзывов. Будьте первым!</p>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <div class="review">
                        <div class="review-header">
                            <div>
                                <span class="review-author"><?= htmlspecialchars($review['full_name'] ?? 'Аноним') ?></span>
                                <span class="review-date"><?= date('d.m.Y H:i', strtotime($review['created_at'])) ?></span>
                            </div>
                            <div class="stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <?= $i <= $review['rating'] ? '★' : '☆' ?>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="review-text">
                            <?= nl2br(htmlspecialchars($review['review_text'])) ?>
                        </div>
                        <?php if ($review['address']): ?>
                            <div class="review-address">📍 <?= htmlspecialchars($review['address']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
