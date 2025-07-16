<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;


class BotHandler
{
    private $chatId;
    private $text;
    private $messageId;
    private $message;
    public $db;
    private $fileHandler;
    private $zarinpalPaymentHandler;
    private $botToken;
    private $botLink;

    public function __construct($chatId, $text, $messageId, $message)
    {
        $this->chatId = $chatId;
        $this->text = $text;
        $this->messageId = $messageId;
        $this->message = $message;
        $this->db = new Database();
        $this->fileHandler = new FileHandler();
        $config = AppConfig::getConfig();
        $this->botToken = $config['bot']['token'];
        $this->botLink = $config['bot']['bot_link'];
        $this->zarinpalPaymentHandler = new ZarinpalPaymentHandler();
    }

    public function deleteMessageWithDelay(): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id" => $this->chatId,
            "message_id" => $this->messageId
        ]);
    }

    public function handleSuccessfulPayment($update): void
    {
        $userLanguage = $this->db->getUserLanguage($this->chatId);
        if (isset($update['message']['successful_payment'])) {
            $chatId = $update['message']['chat']['id'];
            $payload = $update['message']['successful_payment']['invoice_payload'];
            $successfulPayment = $update['message']['successful_payment'];
        }
    }

    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - Received pre_checkout_query: " . print_r($update, true) . "\n", FILE_APPEND);
            $url = "https://api.telegram.org/bot" . $this->botToken . "/answerPreCheckoutQuery";
            $post_fields = [
                'pre_checkout_query_id' => $query_id,
                'ok' => true,
                'error_message' => ""
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            curl_close($ch);
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - answerPreCheckoutQuery Response: " . print_r(json_decode($response, true), true) . "\n", FILE_APPEND);
        }
    }

    public function handleCallbackQuery($callbackQuery): void
    {
        $callbackData = $callbackQuery["data"] ?? null;
        $chatId = $callbackQuery["message"]["chat"]["id"] ?? null;
        $callbackQueryId = $callbackQuery["id"] ?? null;
        $messageId = $callbackQuery["message"]["message_id"] ?? null;
        $currentKeyboard = $callbackQuery["message"]["reply_markup"]["inline_keyboard"] ?? [];
        $userLanguage = $this->db->getUserLanguage($this->chatId);
        $user = $this->message['from'] ?? $this->callbackQuery['from'] ?? null;
        if ($user !== null) {
            $this->db->saveUser($user);
        } else {
            error_log("❌ Cannot save user: 'from' is missing in both message and callbackQuery.");
        }
        if (!$callbackData || !$chatId || !$callbackQueryId || !$messageId) {
            error_log("Callback query missing required data.");
            return;
        }
        if (str_starts_with($callbackData, 'customer_creation')) {

            $text = "نام مشتری جدید را وارد کنید";

            $keyboard = [
                [['text' => '📝برگشت', 'callback_data' => 'back']],
              ];

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
            ]);
        }elseif(str_starts_with($callbackData, 'back')){
            $this->showMainMenu($this->chatId,$messageId);
        }
    }

    public function handleRequest(): void
    {
        if (isset($this->message["from"])) {
            $this->db->saveUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field missing for non-start message. Update type might not be a user message.");
        }
        $state = $this->fileHandler->getState($this->chatId);

        if ($this->text === '/start') {
            $this->showMainMenu($this->chatId);
        }
    }

    private function showMainMenu($chatId, $messageId = null): void
    {
        $text = "👋 به سیستم مدیریت مشتری خوش اومدی!\nاز منوی زیر یکی از گزینه‌ها رو انتخاب کن:";

        $keyboard = [
            [['text' => '📝 ثبت مشتری جدید', 'callback_data' => 'customer_creation']],
            [['text' => '📋 لیست مشتری‌ها', 'callback_data' => 'list_customers']],
            [['text' => '💬 یادداشت پیگیری', 'callback_data' => 'add_followup_note']],
            [['text' => '📞 ثبت تماس / جلسه', 'callback_data' => 'log_interaction']],
            [['text' => '🔔 یادآور پیگیری', 'callback_data' => 'set_reminder']],
            [['text' => '📊 گزارش عملکرد', 'callback_data' => 'show_report']],
            [['text' => '⚙️ تنظیمات', 'callback_data' => 'settings_menu']],
            [['text' => '⚙️ تستت', 'callback_data' => 'settings_menu']],
        ];

        $reply_markup = [
            'inline_keyboard' => $keyboard
        ];

        if ($messageId) {
           $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
            ]);
        } else {
            $this->sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
            ]);
        }
    }



    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        $this->logTelegramRequest($method, $data, $response, $httpCode, $curlError);
        if ($curlError) {
            return false;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['description'] ?? 'Unknown error';
            return false;
        }
    }

    private function logTelegramRequest($method, $data, $response, $httpCode, $curlError = null): void
    {
        $logData = [
            'time' => date("Y-m-d H:i:s"),
            'method' => $method,
            'request_data' => $data,
            'response' => $response,
            'http_code' => $httpCode,
            'curl_error' => $curlError
        ];
        $logMessage = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
