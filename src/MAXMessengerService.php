<?php

/**
 * MAX Messenger Integration Service
 * Provides integration with MAX messenger for notifications and chat functionality
 */
class MAXMessengerService {
    private Database $db;
    private string $apiUrl;
    private string $botToken;
    private string $botSecret;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $config = require __DIR__ . '/../config/config.php';
        
        $this->apiUrl = $config['app']['max_api_url'] ?? 'https://api.max-messenger.ru/v1';
        $this->botToken = $config['app']['max_bot_token'] ?? '';
        $this->botSecret = $config['app']['max_bot_secret'] ?? '';
    }
    
    /**
     * Check if MAX messenger is configured
     */
    public function isConfigured(): bool {
        return !empty($this->botToken) && !empty($this->botSecret);
    }
    
    /**
     * Send authentication request to get access token
     */
    public function authenticate(): ?string {
        if (!$this->isConfigured()) {
            return null;
        }
        
        $ch = curl_init($this->apiUrl . '/auth/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'bot_token' => $this->botToken,
            'bot_secret' => $this->botSecret
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
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
     * Send message to a user via MAX messenger
     */
    public function sendMessage(string $recipientId, string $message, array $buttons = []): bool {
        $token = $this->authenticate();
        
        if (!$token) {
            return false;
        }
        
        $payload = [
            'recipient_id' => $recipientId,
            'text' => $message,
            'parse_mode' => 'html'
        ];
        
        if (!empty($buttons)) {
            $payload['keyboard'] = [
                'inline_keyboard' => $buttons
            ];
        }
        
        $ch = curl_init($this->apiUrl . '/messages/send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    /**
     * Send notification about voting results
     */
    public function sendVotingNotification(int $entranceId, array $stats): bool {
        $entrance = $this->db->fetchOne(
            "SELECT e.*, b.address 
             FROM entrances e 
             JOIN buildings b ON e.building_id = b.id 
             WHERE e.id = :id",
            ['id' => $entranceId]
        );
        
        if (!$entrance) {
            return false;
        }
        
        // Get all users subscribed to this entrance
        $users = $this->db->fetchAll(
            "SELECT u.*, ua.max_user_id 
             FROM users u
             JOIN user_entrances ue ON u.id = ue.user_id
             LEFT JOIN user_accounts ua ON u.id = ua.user_id
             WHERE ue.entrance_id = :entrance_id AND ua.max_user_id IS NOT NULL",
            ['entrance_id' => $entranceId]
        );
        
        $message = sprintf(
            "<b>📊 Результаты голосования по подъезду</b>\n\n" .
            "🏠 Адрес: %s, подъезд №%d\n\n" .
            "<b>Итоги:</b>\n" .
            "✅ За: %d (%.1f%%)\n" .
            "❌ Против: %d\n" .
            "📝 Всего голосов: %d\n\n" .
            "%s",
            htmlspecialchars($entrance['address']),
            $entrance['entrance_number'],
            $stats['yes_votes'],
            $stats['percentage_yes'],
            $stats['no_votes'],
            $stats['total_votes'],
            $stats['percentage_yes'] >= 51 ? "🎉 Решение принято! Установка домофона одобрена." : "⏳ Голосование продолжается."
        );
        
        $success = true;
        foreach ($users as $user) {
            if (!$this->sendMessage($user['max_user_id'], $message)) {
                $success = false;
            }
        }
        
        // Log notification
        $this->db->insert('max_notifications', [
            'entrance_id' => $entranceId,
            'notification_type' => 'voting_results',
            'message_text' => $message,
            'recipients_count' => count($users),
            'status' => $success ? 'sent' : 'partial'
        ]);
        
        return $success;
    }
    
    /**
     * Send payment confirmation notification
     */
    public function sendPaymentNotification(int $userId, int $paymentId, float $amount): bool {
        $user = $this->db->fetchOne(
            "SELECT max_user_id FROM user_accounts WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        
        if (!$user || empty($user['max_user_id'])) {
            return false;
        }
        
        $message = sprintf(
            "<b>💳 Оплата подтверждена</b>\n\n" .
            "Сумма: <b>%.2f ₽</b>\n" .
            "ID платежа: #%d\n\n" .
            "Спасибо за оплату! Ваш взнос на установку домофона получен.",
            $amount,
            $paymentId
        );
        
        $result = $this->sendMessage($user['max_user_id'], $message);
        
        // Log notification
        $this->db->insert('max_notifications', [
            'user_id' => $userId,
            'payment_id' => $paymentId,
            'notification_type' => 'payment_confirmation',
            'message_text' => $message,
            'recipients_count' => 1,
            'status' => $result ? 'sent' : 'failed'
        ]);
        
        return $result;
    }
    
    /**
     * Send meeting announcement
     */
    public function sendMeetingAnnouncement(int $meetingId, string $meetingDate, string $location): bool {
        $meeting = $this->db->fetchOne(
            "SELECT m.*, e.entrance_number, b.address 
             FROM meetings m
             JOIN entrances e ON m.entrance_id = e.id
             JOIN buildings b ON e.building_id = b.id
             WHERE m.id = :id",
            ['id' => $meetingId]
        );
        
        if (!$meeting) {
            return false;
        }
        
        // Get all residents
        $users = $this->db->fetchAll(
            "SELECT u.*, ua.max_user_id 
             FROM users u
             JOIN user_entrances ue ON u.id = ue.user_id
             LEFT JOIN user_accounts ua ON u.id = ua.user_id
             WHERE ue.entrance_id = :entrance_id AND ua.max_user_id IS NOT NULL",
            ['entrance_id' => $meeting['entrance_id']]
        );
        
        $message = sprintf(
            "<b>📅 Объявление о собрании</b>\n\n" .
            "🏠 Адрес: %s, подъезд №%d\n" .
            "📆 Дата: %s\n" .
            "📍 Место: %s\n\n" .
            "Повестка: Обсуждение установки домофонной системы.\n" .
            "Явка обязательна!",
            htmlspecialchars($meeting['address']),
            $meeting['entrance_number'],
            date('d.m.Y H:i', strtotime($meetingDate)),
            htmlspecialchars($location)
        );
        
        $buttons = [
            [['text' => '✅ Подтвердить участие', 'callback_data' => 'meeting_confirm_' . $meetingId]],
            [['text' => '❌ Не смогу прийти', 'callback_data' => 'meeting_decline_' . $meetingId]]
        ];
        
        $success = true;
        foreach ($users as $user) {
            if (!$this->sendMessage($user['max_user_id'], $message, $buttons)) {
                $success = false;
            }
        }
        
        // Log notification
        $this->db->insert('max_notifications', [
            'meeting_id' => $meetingId,
            'notification_type' => 'meeting_announcement',
            'message_text' => $message,
            'recipients_count' => count($users),
            'status' => $success ? 'sent' : 'partial'
        ]);
        
        return $success;
    }
    
    /**
     * Create group chat for entrance in MAX messenger
     */
    public function createGroupChat(int $entranceId): ?string {
        $token = $this->authenticate();
        
        if (!$token) {
            return null;
        }
        
        $entrance = $this->db->fetchOne(
            "SELECT e.*, b.address 
             FROM entrances e 
             JOIN buildings b ON e.building_id = b.id 
             WHERE e.id = :id",
            ['id' => $entranceId]
        );
        
        if (!$entrance) {
            return null;
        }
        
        $chatName = sprintf("Подъезд №%d - %s", $entrance['entrance_number'], $entrance['address']);
        
        $ch = curl_init($this->apiUrl . '/groups/create');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'name' => $chatName,
            'description' => 'Чат жильцов подъезда для обсуждения установки домофона'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $result = json_decode($response, true);
        $groupId = $result['group_id'] ?? null;
        $inviteLink = $result['invite_link'] ?? null;
        
        if ($groupId && $inviteLink) {
            // Save to database
            $this->db->update('entrances', [
                'max_group_id' => $groupId,
                'max_invite_link' => $inviteLink
            ], 'id = :id', ['id' => $entranceId]);
            
            // Log creation
            $this->db->insert('max_groups', [
                'entrance_id' => $entranceId,
                'group_id' => $groupId,
                'group_name' => $chatName,
                'invite_link' => $inviteLink,
                'created_by' => 'admin'
            ]);
        }
        
        return $inviteLink;
    }
    
    /**
     * Get webhook data (for handling incoming messages/callbacks)
     */
    public function handleWebhook(): ?array {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            return null;
        }
        
        // Verify webhook signature
        $signature = $_SERVER['HTTP_X_MAX_SIGNATURE'] ?? '';
        if (!$this->verifyWebhookSignature($input, $signature)) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * Verify webhook signature from MAX
     */
    private function verifyWebhookSignature(string $payload, string $signature): bool {
        $expectedSignature = hash_hmac('sha256', $payload, $this->botSecret);
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Process callback from MAX messenger
     */
    public function processCallback(string $callbackData, int $userId): void {
        if (strpos($callbackData, 'meeting_confirm_') === 0) {
            $meetingId = (int)str_replace('meeting_confirm_', '', $callbackData);
            $this->db->insert('meeting_responses', [
                'meeting_id' => $meetingId,
                'user_id' => $userId,
                'response' => 'confirmed',
                'source' => 'max_messenger'
            ]);
        } elseif (strpos($callbackData, 'meeting_decline_') === 0) {
            $meetingId = (int)str_replace('meeting_decline_', '', $callbackData);
            $this->db->insert('meeting_responses', [
                'meeting_id' => $meetingId,
                'user_id' => $userId,
                'response' => 'declined',
                'source' => 'max_messenger'
            ]);
        } elseif (strpos($callbackData, 'vote_') === 0) {
            // Handle quick vote callbacks
            $parts = explode('_', $callbackData);
            if (count($parts) >= 3) {
                $entranceId = (int)$parts[1];
                $vote = $parts[2];
                
                // Get apartment for user
                $apartment = $this->db->fetchOne(
                    "SELECT a.id FROM apartments a
                     JOIN user_entrances ue ON a.entrance_id = ue.entrance_id
                     WHERE ue.user_id = :user_id AND a.entrance_id = :entrance_id
                     LIMIT 1",
                    ['user_id' => $userId, 'entrance_id' => $entranceId]
                );
                
                if ($apartment && in_array($vote, ['yes', 'no'])) {
                    $voteService = new VoteService();
                    try {
                        $voteService->castVote($entranceId, $apartment['id'], $vote, $userId);
                    } catch (Exception $e) {
                        // Log error
                    }
                }
            }
        }
    }
    
    /**
     * Broadcast message to all entrances
     */
    public function broadcastMessage(string $message, array $entranceIds = []): int {
        $token = $this->authenticate();
        
        if (!$token) {
            return 0;
        }
        
        if (empty($entranceIds)) {
            $entranceIds = $this->db->fetchAll("SELECT id FROM entrances") ?: [];
            $entranceIds = array_column($entranceIds, 'id');
        }
        
        $sentCount = 0;
        foreach ($entranceIds as $entranceId) {
            $users = $this->db->fetchAll(
                "SELECT u.*, ua.max_user_id 
                 FROM users u
                 JOIN user_entrances ue ON u.id = ue.user_id
                 LEFT JOIN user_accounts ua ON u.id = ua.user_id
                 WHERE ue.entrance_id = :entrance_id AND ua.max_user_id IS NOT NULL",
                ['entrance_id' => $entranceId]
            );
            
            foreach ($users as $user) {
                if ($this->sendMessage($user['max_user_id'], $message)) {
                    $sentCount++;
                }
            }
        }
        
        // Log broadcast
        $this->db->insert('max_notifications', [
            'notification_type' => 'broadcast',
            'message_text' => $message,
            'recipients_count' => $sentCount,
            'status' => 'sent'
        ]);
        
        return $sentCount;
    }
    
    /**
     * Get statistics for MAX messenger usage
     */
    public function getStatistics(): array {
        $stats = [];
        
        // Total notifications sent
        $stats['total_notifications'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM max_notifications"
        )['count'] ?? 0;
        
        // Notifications by type
        $stats['by_type'] = $this->db->fetchAll(
            "SELECT notification_type, COUNT(*) as count 
             FROM max_notifications 
             GROUP BY notification_type"
        );
        
        // Total groups created
        $stats['total_groups'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM max_groups"
        )['count'] ?? 0;
        
        // Success rate
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM max_notifications WHERE status IN ('sent', 'partial', 'failed')"
        )['count'] ?? 0;
        
        $success = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM max_notifications WHERE status = 'sent'"
        )['count'] ?? 0;
        
        $stats['success_rate'] = $total > 0 ? round(($success / $total) * 100, 2) : 0;
        
        return $stats;
    }
}
