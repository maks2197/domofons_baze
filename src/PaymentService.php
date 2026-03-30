<?php

class PaymentService {
    private Database $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Create a payment via YooKassa
     */
    public function createPayment(int $userId, int $entranceId, float $amount, string $description = ''): array {
        require_once __DIR__ . '/Config.php';
        $yookassaShopId = Config::get('yookassa_shop_id', '');
        $yookassaSecretKey = Config::get('yookassa_secret_key', '');
        $appUrl = Config::get('url', 'http://localhost');

        if (empty($yookassaShopId) || empty($yookassaSecretKey)) {
            throw new Exception('YooKassa credentials not configured');
        }

        // Create payment record in database
        $paymentId = $this->db->insert('payments', [
            'user_id' => $userId,
            'entrance_id' => $entranceId,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_status' => 'pending'
        ]);

        // Prepare YooKassa request
        $paymentData = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB'
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $appUrl . '/public/payment_success.php?payment_id=' . $paymentId
            ],
            'description' => $description ?: 'Оплата установки домофона',
            'metadata' => [
                'payment_id' => $paymentId,
                'entrance_id' => $entranceId
            ]
        ];

        // Make request to YooKassa API
        $ch = curl_init('https://api.yookassa.ru/v3/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Idempotence-Key: ' . uniqid(),
            'Authorization: Basic ' . base64_encode($yookassaShopId . ':' . $yookassaSecretKey)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->db->update('payments', [
                'payment_status' => 'failed'
            ], 'id = :id', ['id' => $paymentId]);

            throw new Exception('YooKassa API error: ' . $response);
        }

        $result = json_decode($response, true);

        // Update payment with YooKassa payment ID
        $this->db->update('payments', [
            'yookassa_payment_id' => $result['id']
        ], 'id = :id', ['id' => $paymentId]);

        return [
            'payment_id' => $paymentId,
            'yookassa_id' => $result['id'],
            'confirmation_url' => $result['confirmation']['confirmation_url'] ?? '',
            'amount' => $amount
        ];
    }
    
    /**
     * Handle payment webhook from YooKassa
     */
    public function handleWebhook(array $event): void {
        require_once __DIR__ . '/Config.php';

        // Verify webhook signature
        $rawBody = file_get_contents('php://input');
        $signatureHeader = $_SERVER['HTTP_X_YOOKASSA_SIGNATURE'] ?? '';
        $yookassaSecretKey = Config::get('yookassa_secret_key', '');

        if (empty($yookassaSecretKey)) {
            error_log('YooKassa webhook: secret key not configured');
            return;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $yookassaSecretKey);

        if (!hash_equals($expectedSignature, $signatureHeader)) {
            error_log('YooKassa webhook: signature verification failed');
            return;
        }

        $object = $event['object'] ?? [];
        $yookassaPaymentId = $object['id'] ?? '';

        if (empty($yookassaPaymentId)) {
            return;
        }
        
        // Find payment in database
        $payment = $this->db->fetchOne(
            "SELECT * FROM payments WHERE yookassa_payment_id = :yookassa_id",
            ['yookassa_id' => $yookassaPaymentId]
        );
        
        if (!$payment) {
            return;
        }
        
        $status = $object['status'] ?? '';
        
        switch ($status) {
            case 'succeeded':
                $this->db->update('payments', [
                    'payment_status' => 'paid',
                    'paid_at' => date('Y-m-d H:i:s'),
                    'payment_method' => $object['payment_method']['type'] ?? ''
                ], 'id = :id', ['id' => $payment['id']]);
                break;
                
            case 'canceled':
            case 'failed':
                $this->db->update('payments', [
                    'payment_status' => 'failed'
                ], 'id = :id', ['id' => $payment['id']]);
                break;
        }
    }
    
    /**
     * Get payment status
     */
    public function getPaymentStatus(int $paymentId): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM payments WHERE id = :id",
            ['id' => $paymentId]
        );
    }
    
    /**
     * Get all payments for an entrance
     */
    public function getEntrancePayments(int $entranceId): array {
        return $this->db->fetchAll(
            "SELECT p.*, u.full_name, u.phone 
             FROM payments p 
             LEFT JOIN users u ON p.user_id = u.id 
             WHERE p.entrance_id = :entrance_id 
             ORDER BY p.created_at DESC",
            ['entrance_id' => $entranceId]
        );
    }
}