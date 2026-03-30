<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AIModerationService.php';

session_start();
$db = Database::getInstance();
$aiService = new AIModerationService();
$config = require __DIR__ . '/../config/config.php';

$message = '';
$messageType = 'info';

// Handle question submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $questionText = trim($_POST['question_text'] ?? '');
    
    if ($subject && $questionText) {
        $userId = $_SESSION['user_id'] ?? null;
        
        try {
            $result = $aiService->moderateQuestion($userId ?: 0, $subject, $questionText);
            
            if ($result['approved']) {
                $message = "Ваш вопрос отправлен. AI предложил предварительный ответ: " . substr($result['suggested_answer'], 0, 100) . "...";
                $messageType = 'success';
            } else {
                $message = "Ваш вопрос будет проверен модератором перед публикацией.";
                $messageType = 'info';
            }
        } catch (Exception $e) {
            $message = "Ошибка при отправке вопроса: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Заполните все обязательные поля";
        $messageType = 'error';
    }
}

// Get all questions (admin can see all, users see only approved)
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$statusFilter = $isAdmin ? '1=1' : "status = 'open' OR status = 'in_progress'";

$questions = $db->fetchAll("
    SELECT q.*, u.full_name 
    FROM questions q 
    LEFT JOIN users u ON q.user_id = u.id 
    WHERE {$statusFilter}
    ORDER BY q.created_at DESC
    LIMIT 50
");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вопросы и ответы - <?= htmlspecialchars($config['app']['name']) ?></title>
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
        nav { background: white; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        nav ul { list-style: none; display: flex; gap: 20px; justify-content: center; }
        nav a { text-decoration: none; color: #2c3e50; font-weight: bold; }
        .question-item { border-left: 4px solid #3498db; padding: 15px; margin: 15px 0; background: #f8f9fa; border-radius: 4px; }
        .question-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .question-subject { font-weight: bold; color: #2c3e50; font-size: 18px; }
        .question-meta { color: #7f8c8d; font-size: 14px; }
        .question-text { color: #34495e; margin: 10px 0; }
        .answer-section { background: #e8f6f3; padding: 15px; border-radius: 4px; margin-top: 10px; }
        .answer-label { font-weight: bold; color: #1abc9c; margin-bottom: 5px; }
        .answer-text { color: #16a085; }
        .ai-badge { display: inline-block; background: #9b59b6; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-open { background: #fef9e7; color: #f39c12; }
        .status-in_progress { background: #d6eaf8; color: #3498db; }
        .status-closed { background: #d5f5e3; color: #27ae60; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?= htmlspecialchars($config['app']['name']) ?></h1>
            <p style="text-align: center;">Вопросы и ответы</p>
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
            <h2>❓ Задать вопрос</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="subject">Тема вопроса *</label>
                    <input type="text" id="subject" name="subject" required placeholder="Например: Стоимость установки">
                </div>
                <div class="form-group">
                    <label for="question_text">Ваш вопрос *</label>
                    <textarea id="question_text" name="question_text" required placeholder="Опишите ваш вопрос подробно..."></textarea>
                </div>
                <button type="submit">Отправить вопрос</button>
            </form>
        </div>
        
        <div class="card">
            <h2>📋 Вопросы и ответы</h2>
            <?php if (empty($questions)): ?>
                <p>Пока нет вопросов. Будьте первым!</p>
            <?php else: ?>
                <?php foreach ($questions as $question): ?>
                    <div class="question-item">
                        <div class="question-header">
                            <div>
                                <span class="question-subject"><?= htmlspecialchars($question['subject']) ?></span>
                                <?php if ($question['ai_suggested_answer']): ?>
                                    <span class="ai-badge">AI ответ</span>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= str_replace('_', '-', $question['status']) ?>">
                                <?= $question['status'] === 'open' ? 'Открыт' : ($question['status'] === 'in_progress' ? 'В работе' : 'Закрыт') ?>
                            </span>
                        </div>
                        <div class="question-meta">
                            <?= htmlspecialchars($question['full_name'] ?? 'Аноним') ?> • <?= date('d.m.Y H:i', strtotime($question['created_at'])) ?>
                        </div>
                        <div class="question-text">
                            <?= nl2br(htmlspecialchars($question['question_text'])) ?>
                        </div>
                        
                        <?php if ($question['ai_suggested_answer']): ?>
                            <div class="answer-section">
                                <div class="answer-label">🤖 Предварительный ответ от AI:</div>
                                <div class="answer-text"><?= nl2br(htmlspecialchars($question['ai_suggested_answer'])) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($question['admin_response']): ?>
                            <div class="answer-section" style="background: #fef9e7;">
                                <div class="answer-label" style="color: #f39c12;">👤 Ответ администратора:</div>
                                <div class="answer-text" style="color: #d35400;"><?= nl2br(htmlspecialchars($question['admin_response'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
