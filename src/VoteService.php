<?php

class VoteService {
    private Database $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Create a new entrance for voting
     */
    public function createEntrance(int $buildingId, int $entranceNumber, int $totalApartments): int {
        return $this->db->insert('entrances', [
            'building_id' => $buildingId,
            'entrance_number' => $entranceNumber,
            'total_apartments' => $totalApartments,
            'status' => 'active'
        ]);
    }
    
    /**
     * Add apartments to an entrance
     */
    public function addApartments(int $entranceId, array $apartmentNumbers): void {
        foreach ($apartmentNumbers as $number) {
            try {
                $this->db->insert('apartments', [
                    'entrance_id' => $entranceId,
                    'apartment_number' => $number,
                    'vote_status' => 'pending'
                ]);
            } catch (PDOException $e) {
                // Skip duplicates
            }
        }
    }
    
    /**
     * Cast a vote for an apartment
     */
    public function castVote(int $entranceId, int $apartmentId, string $vote, ?int $userId = null, string $ipAddress = ''): bool {
        // Validate vote value
        if (!in_array($vote, ['yes', 'no'], true)) {
            throw new InvalidArgumentException("Vote must be 'yes' or 'no', got: {$vote}");
        }

        $this->db->beginTransaction();

        try {
            // Record the vote
            $this->db->insert('votes', [
                'entrance_id' => $entranceId,
                'apartment_id' => $apartmentId,
                'user_id' => $userId,
                'vote' => $vote,
                'ip_address' => $ipAddress
            ]);
            
            // Update apartment vote status
            $this->db->update('apartments', [
                'vote_status' => $vote,
                'voted_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $apartmentId]);
            
            $this->db->commit();
            
            // Check if voting threshold is reached
            $this->checkVotingThreshold($entranceId);
            
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Check if voting threshold is reached and create protocol if so
     */
    public function checkVotingThreshold(int $entranceId): ?array {
        require_once __DIR__ . '/Config.php';
        $threshold = (int)Config::get('voting_threshold', 51);

        // Get voting statistics
        $stats = $this->getVotingStats($entranceId);

        if ($stats['percentage_yes'] >= $threshold) {
            // Create meeting protocol
            return $this->createMeetingProtocol($entranceId, $stats);
        }

        return null;
    }
    
    /**
     * Get voting statistics for an entrance
     */
    public function getVotingStats(int $entranceId): array {
        $sql = "SELECT 
                COUNT(*) as total_votes,
                SUM(CASE WHEN vote = 'yes' THEN 1 ELSE 0 END) as yes_votes,
                SUM(CASE WHEN vote = 'no' THEN 1 ELSE 0 END) as no_votes
                FROM votes 
                WHERE entrance_id = :entrance_id";
        
        $result = $this->db->fetchOne($sql, ['entrance_id' => $entranceId]);
        
        $totalVotes = (int)($result['total_votes'] ?? 0);
        $yesVotes = (int)($result['yes_votes'] ?? 0);
        $percentageYes = $totalVotes > 0 ? round(($yesVotes / $totalVotes) * 100, 2) : 0;
        
        return [
            'total_votes' => $totalVotes,
            'yes_votes' => $yesVotes,
            'no_votes' => (int)($result['no_votes'] ?? 0),
            'percentage_yes' => $percentageYes
        ];
    }
    
    /**
     * Create meeting protocol automatically
     */
    public function createMeetingProtocol(int $entranceId, array $stats): int {
        $entrance = $this->db->fetchOne(
            "SELECT e.*, b.address 
             FROM entrances e 
             JOIN buildings b ON e.building_id = b.id 
             WHERE e.id = :id",
            ['id' => $entranceId]
        );
        
        $protocolNumber = 'PROT-' . date('Y') . '-' . str_pad((string)$entranceId, 4, '0', STR_PAD_LEFT);
        
        $meetingId = $this->db->insert('meetings', [
            'entrance_id' => $entranceId,
            'meeting_date' => date('Y-m-d'),
            'protocol_number' => $protocolNumber,
            'total_votes' => $stats['total_votes'],
            'yes_votes' => $stats['yes_votes'],
            'no_votes' => $stats['no_votes'],
            'percentage_yes' => $stats['percentage_yes'],
            'status' => 'approved'
        ]);
        
        // Update entrance status
        $this->db->update('entrances', [
            'status' => 'completed'
        ], 'id = :id', ['id' => $entranceId]);
        
        // Generate protocol document
        $this->generateProtocolDocument($meetingId, $entrance, $stats);
        
        // Generate contract
        $this->generateContract($entranceId, $entrance);
        
        return $meetingId;
    }
    
    /**
     * Generate protocol document (PDF/HTML)
     */
    private function generateProtocolDocument(int $meetingId, array $entrance, array $stats): string {
        $content = $this->renderProtocolTemplate($meetingId, $entrance, $stats);
        
        $filename = 'protocol_' . $meetingId . '_' . date('Ymd_His') . '.html';
        $filepath = __DIR__ . '/../uploads/' . $filename;
        
        file_put_contents($filepath, $content);
        
        $this->db->update('meetings', [
            'protocol_file_path' => '/uploads/' . $filename
        ], 'id = :id', ['id' => $meetingId]);
        
        return $filepath;
    }
    
    /**
     * Generate contract for installation
     */
    private function generateContract(int $entranceId, array $entrance): string {
        $content = $this->renderContractTemplate($entranceId, $entrance);
        
        $filename = 'contract_entrance_' . $entranceId . '_' . date('Ymd_His') . '.html';
        $filepath = __DIR__ . '/../uploads/' . $filename;
        
        file_put_contents($filepath, $content);
        
        $this->db->update('meetings', [
            'contract_file_path' => '/uploads/' . $filename
        ], 'entrance_id = :entrance_id', ['entrance_id' => $entranceId]);
        
        return $filepath;
    }
    
    /**
     * Render protocol template
     */
    private function renderProtocolTemplate(int $meetingId, array $entrance, array $stats): string {
        $config = require __DIR__ . '/../config/config.php';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Протокол общего собрания №{$meetingId}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h1 { text-align: center; }
        .section { margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .signature { margin-top: 50px; }
    </style>
</head>
<body>
    <h1>ПРОТОКОЛ ОБЩЕГО СОБРАНИЯ СОБСТВЕННИКОВ ПОМЕЩЕНИЙ</h1>
    <h2>№ {$meetingId} от {$entrance['created_at']}</h2>
    
    <div class="section">
        <strong>Адрес:</strong> {$entrance['address']}, подъезд №{$entrance['entrance_number']}
    </div>
    
    <div class="section">
        <strong>Повестка дня:</strong> Установка домофонной системы в подъезде
    </div>
    
    <div class="section">
        <h3>Результаты голосования:</h3>
        <table>
            <tr>
                <th>Показатель</th>
                <th>Значение</th>
            </tr>
            <tr>
                <td>Всего голосов</td>
                <td>{$stats['total_votes']}</td>
            </tr>
            <tr>
                <td>За установку</td>
                <td>{$stats['yes_votes']} ({$stats['percentage_yes']}%)</td>
            </tr>
            <tr>
                <td>Против установки</td>
                <td>{$stats['no_votes']}</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <strong>Решение:</strong> В связи с тем, что более {$stats['percentage_yes']}% собственников проголосовали "ЗА", 
        общее собрание РЕШИЛО установить домофонную систему в подъезде.
    </div>
    
    <div class="signature">
        <p>Председатель собрания: _________________ / ___________________</p>
        <p>Секретарь собрания: _________________ / ___________________</p>
        <p>Дата: "__" __________ 20__ г.</p>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Render contract template
     */
    private function renderContractTemplate(int $entranceId, array $entrance): string {
        $config = require __DIR__ . '/../config/config.php';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Договор на установку домофона</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h1 { text-align: center; }
        .section { margin: 20px 0; }
        .signature-block { margin-top: 50px; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <h1>ДОГОВОР НА УСТАНОВКУ ДОМОФОННОЙ СИСТЕМЫ</h1>
    
    <div class="section">
        <p>г. {$entrance['city'] ?? ''} &nbsp;&nbsp;&nbsp; "__" __________ 20__ г.</p>
    </div>
    
    <div class="section">
        <p><strong>Исполнитель:</strong> {$config['app']['name']}, с одной стороны, и</p>
        <p><strong>Заказчик:</strong> Собственники помещений по адресу: {$entrance['address']}, подъезд №{$entrance['entrance_number']}, с другой стороны,</p>
        <p>заключили настоящий договор о нижеследующем:</p>
    </div>
    
    <div class="section">
        <h3>1. ПРЕДМЕТ ДОГОВОРА</h3>
        <p>1.1. Исполнитель обязуется установить домофонную систему в подъезде №{$entrance['entrance_number']} по адресу: {$entrance['address']}.</p>
        <p>1.2. Заказчик обязуется оплатить услуги Исполнителя.</p>
    </div>
    
    <div class="section">
        <h3>2. СТОИМОСТЬ И ПОРЯДОК ОПЛАТЫ</h3>
        <p>2.1. Стоимость установки распределяется пропорционально между всеми собственниками подъезда.</p>
        <p>2.2. Оплата производится через систему онлайн-платежей.</p>
    </div>
    
    <div class="section">
        <h3>3. СОГЛАСИЕ НА ОБРАБОТКУ ПЕРСОНАЛЬНЫХ ДАННЫХ</h3>
        <p>3.1. Подписывая данный договор, собственники дают согласие на обработку своих персональных данных в соответствии с Федеральным законом № 152-ФЗ.</p>
    </div>
    
    <div class="signature-block">
        <div>
            <p><strong>ИСПОЛНИТЕЛЬ:</strong></p>
            <p>_________________ / ___________________</p>
            <p>М.П.</p>
        </div>
        <div>
            <p><strong>ЗАКАЗЧИК:</strong></p>
            <p>Представитель собственников:</p>
            <p>_________________ / ___________________</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Generate Telegram invite link
     */
    public function generateTelegramInvite(int $entranceId): string {
        require_once __DIR__ . '/Config.php';
        $telegramBotToken = Config::get('telegram_bot_token', '');

        if (empty($telegramBotToken)) {
            return '';
        }

        $entrance = $this->db->fetchOne("SELECT * FROM entrances WHERE id = :id", ['id' => $entranceId]);

        // Create chat via Telegram Bot API
        $chatName = "Подъезд №{$entrance['entrance_number']} - Домофон";

        // Note: This requires proper Telegram Bot API implementation
        // For now, return a placeholder
        $inviteLink = "https://t.me/joinchat/PLACEHOLDER_{$entranceId}";

        $this->db->update('entrances', [
            'telegram_invite_link' => $inviteLink
        ], 'id = :id', ['id' => $entranceId]);

        return $inviteLink;
    }
}