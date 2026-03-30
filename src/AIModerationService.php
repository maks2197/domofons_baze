<?php

class AIModerationService {
    private Database $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Moderate a message using GigaChat
     */
    public function moderateWithGigaChat(string $text, string $context = 'message'): array {
        $config = require __DIR__ . '/../config/config.php';
        
        if (empty($config['app']['gigachat_client_id']) || empty($config['app']['gigachat_client_secret'])) {
            return $this->fallbackModeration($text, 'gigachat');
        }
        
        // Get access token
        $token = $this->getGigaChatToken();
        
        if (!$token) {
            return $this->fallbackModeration($text, 'gigachat');
        }
        
        $prompt = $this->buildModerationPrompt($text, $context);
        
        $ch = curl_init('https://api.gigachat.ru/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'GigaChat',
            'messages' => [
                ['role' => 'system', 'content' => 'Ты модератор контента. Определяй, является ли текст допустимым.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.1
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return $this->fallbackModeration($text, 'gigachat');
        }
        
        $result = json_decode($response, true);
        $answer = $result['choices'][0]['message']['content'] ?? '';
        
        return $this->parseModerationResult($answer, $text, 'gigachat');
    }
    
    /**
     * Moderate a message using Yandex AI
     */
    public function moderateWithYandex(string $text, string $context = 'message'): array {
        $config = require __DIR__ . '/../config/config.php';
        
        if (empty($config['app']['yandex_api_key'])) {
            return $this->fallbackModeration($text, 'yandex');
        }
        
        $prompt = $this->buildModerationPrompt($text, $context);
        
        $ch = curl_init('https://llm.api.cloud.yandex.net/foundationModels/v1/completion');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'modelUri' => 'gpt://b1g.../yandexgpt/latest',
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.1
            ],
            'messages' => [
                ['role' => 'system', 'content' => 'Ты модератор контента.'],
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ApiKey ' . $config['app']['yandex_api_key']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return $this->fallbackModeration($text, 'yandex');
        }
        
        $result = json_decode($response, true);
        $answer = $result['result']['alternatives'][0]['message']['text'] ?? '';
        
        return $this->parseModerationResult($answer, $text, 'yandex');
    }
    
    /**
     * Get GigaChat access token
     */
    private function getGigaChatToken(): ?string {
        $config = require __DIR__ . '/../config/config.php';
        
        $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'scope' => 'GIGACHAT_API_PERS'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($config['app']['gigachat_client_id'] . ':' . $config['app']['gigachat_client_secret'])
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $result = json_decode($response, true);
        return $result['access_token'] ?? null;
    }
    
    /**
     * Build moderation prompt
     */
    private function buildModerationPrompt(string $text, string $context): string {
        $contextDescriptions = [
            'message' => 'сообщение в чате жильцов',
            'review' => 'отзыв о компании',
            'question' => 'вопрос от клиента'
        ];
        
        $contextDesc = $contextDescriptions[$context] ?? 'текст';
        
        return "Проанализируй следующий {$contextDesc}: \"{$text}\"\n\n" .
               "Определи:\n" .
               "1. Содержит ли текст оскорбления, угрозы, спам или нецензурную лексику?\n" .
               "2. Является ли текст допустимым для публикации? (да/нет)\n" .
               "3. Оценка токсичности от 0 до 1 (где 0 - полностью безопасный, 1 - очень токсичный)\n\n" .
               "Ответь в формате JSON:\n" .
               '{"approved": true/false, "score": 0.0-1.0, "reason": "причина"}';
    }
    
    /**
     * Parse moderation result from AI response
     */
    private function parseModerationResult(string $answer, string $text, string $provider): array {
        // Try to extract JSON from response
        preg_match('/\{[^}]+\}/', $answer, $matches);
        
        if (!empty($matches[0])) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return [
                    'approved' => (bool)($json['approved'] ?? true),
                    'score' => (float)($json['score'] ?? 0.5),
                    'reason' => $json['reason'] ?? '',
                    'provider' => $provider,
                    'status' => $json['approved'] ? 'approved' : 'rejected'
                ];
            }
        }
        
        // Fallback parsing
        $isApproved = stripos($answer, 'да') !== false && stripos($answer, 'нет') === false;
        $score = 0.3; // Default safe score
        
        return [
            'approved' => $isApproved,
            'score' => $score,
            'reason' => substr($answer, 0, 200),
            'provider' => $provider,
            'status' => $isApproved ? 'approved' : 'flagged'
        ];
    }
    
    /**
     * Fallback moderation when AI services are unavailable
     */
    private function fallbackModeration(string $text, string $provider): array {
        // Simple keyword-based filtering
        $forbiddenWords = ['спам', 'мошенник', 'обман', 'вор'];
        $textLower = mb_strtolower($text);
        
        $hasForbidden = false;
        foreach ($forbiddenWords as $word) {
            if (mb_strpos($textLower, $word) !== false) {
                $hasForbidden = true;
                break;
            }
        }
        
        return [
            'approved' => !$hasForbidden,
            'score' => $hasForbidden ? 0.7 : 0.2,
            'reason' => 'Автоматическая проверка по ключевым словам',
            'provider' => $provider,
            'status' => $hasForbidden ? 'flagged' : 'approved'
        ];
    }
    
    /**
     * Moderate and save a message
     */
    public function moderateMessage(int $entranceId, int $userId, string $messageText): array {
        $result = $this->moderateWithGigaChat($messageText, 'message');
        
        $messageId = $this->db->insert('messages', [
            'entrance_id' => $entranceId,
            'user_id' => $userId,
            'message_text' => $messageText,
            'ai_moderation_status' => $result['status'],
            'ai_score' => $result['score'],
            'ai_provider' => $result['provider']
        ]);
        
        return [
            'message_id' => $messageId,
            'approved' => $result['approved'],
            'status' => $result['status']
        ];
    }
    
    /**
     * Moderate and save a review
     */
    public function moderateReview(int $userId, int $buildingId, string $reviewText, int $rating): array {
        $result = $this->moderateWithGigaChat($reviewText, 'review');
        
        $reviewId = $this->db->insert('reviews', [
            'user_id' => $userId,
            'building_id' => $buildingId,
            'rating' => $rating,
            'review_text' => $reviewText,
            'ai_moderation_status' => $result['status'],
            'ai_score' => $result['score']
        ]);
        
        return [
            'review_id' => $reviewId,
            'approved' => $result['approved'],
            'status' => $result['status']
        ];
    }
    
    /**
     * Moderate and generate answer for a question
     */
    public function moderateQuestion(int $userId, string $subject, string $questionText): array {
        $moderationResult = $this->moderateWithGigaChat($questionText, 'question');
        
        // Generate AI-suggested answer
        $suggestedAnswer = $this->generateAnswerSuggestion($questionText);
        
        $questionId = $this->db->insert('questions', [
            'user_id' => $userId,
            'subject' => $subject,
            'question_text' => $questionText,
            'ai_suggested_answer' => $suggestedAnswer,
            'status' => 'open'
        ]);
        
        return [
            'question_id' => $questionId,
            'approved' => $moderationResult['approved'],
            'suggested_answer' => $suggestedAnswer
        ];
    }
    
    /**
     * Generate AI-suggested answer for a question
     */
    private function generateAnswerSuggestion(string $questionText): string {
        $config = require __DIR__ . '/../config/config.php';
        
        if (empty($config['app']['gigachat_client_id'])) {
            return 'Спасибо за ваш вопрос. Наш менеджер свяжется с вами в ближайшее время.';
        }
        
        $token = $this->getGigaChatToken();
        
        if (!$token) {
            return 'Спасибо за ваш вопрос. Наш менеджер свяжется с вами в ближайшее время.';
        }
        
        $ch = curl_init('https://api.gigachat.ru/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'GigaChat',
            'messages' => [
                ['role' => 'system', 'content' => 'Ты помощник службы поддержки компании по установке домофонов. Отвечай вежливо и профессионально.'],
                ['role' => 'user', 'content' => "Клиент задал вопрос: {$questionText}\n\nПредложи краткий профессиональный ответ."]
            ],
            'temperature' => 0.5
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return 'Спасибо за ваш вопрос. Наш менеджер свяжется с вами в ближайшее время.';
        }
        
        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? 'Спасибо за ваш вопрос. Наш менеджер свяжется с вами в ближайшее время.';
    }
}
