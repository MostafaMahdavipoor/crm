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
//ازینجا کد میزنیم

        public function handleCallbackQuery($callbackQuery): void
        {
            $callbackData = $callbackQuery["data"] ?? null;
            $chatId = $callbackQuery["message"]["chat"]["id"] ?? null;
            $callbackQueryId = $callbackQuery["id"] ?? null;
            $messageId = $callbackQuery["message"]["message_id"] ?? null;

            if (!$callbackData || !$chatId || !$callbackQueryId || !$messageId) {
                error_log("اطلاعات مورد نیاز در کالبک وجود ندارد.");
                return;
            }

            // مدیریت درخواست وارد کردن تاریخ دستی
            if (str_starts_with($callbackData, 'manual_date_input')) {
                $text = "📅 لطفاً تاریخ شروع را به فرمت زیر وارد کنید:\n\n";
                $text .= "فرمت: YYYY-MM-DD (مثال: 2024-01-15)\n";
                $text .= "یا به فرمت شمسی: 1403/01/25\n\n";
                $text .= "پس از وارد کردن تاریخ شروع، تاریخ پایان را نیز خواهم پرسید.";

                $keyboard = [
                    [['text' => '🔙 بازگشت', 'callback_data' => 'manual_date_input']],
                    [['text' => '❌ لغو', 'callback_data' => 'cancel']]
                ];

                $this->fileHandler->saveState($chatId, "waiting_start_date");
                $this->fileHandler->saveMessageId($chatId, $messageId);

                $this->sendRequest('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
                ]);
                return;
            }

            // مدیریت state برای دریافت تاریخ شروع
            $state = $this->fileHandler->getState($chatId);
            if ($state === 'waiting_start_date') {
                $startDate = $this->text;
                $messageId = $this->fileHandler->getMessageId($chatId);
                $this->deleteMessageWithDelay();

                if (!$this->isValidDate($startDate)) {
                    $this->sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ فرمت تاریخ اشتباه است. لطفاً به فرمت YYYY-MM-DD یا 1403/01/25 وارد کنید."
                    ]);
                    return;
                }

                $this->fileHandler->saveStartDate($chatId, $startDate);
                $this->fileHandler->saveState($chatId, "waiting_end_date");

                $text = "📅 تاریخ شروع: $startDate\n\n";
                $text .= "حالا لطفاً تاریخ پایان را وارد کنید:";

                $keyboard = [
                    [['text' => '🔙 بازگشت', 'callback_data' => 'manual_date_input']],
                    [['text' => '❌ لغو', 'callback_data' => 'cancel']]
                ];

                $this->sendRequest('editMessageText', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'message_id' => $messageId,
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
                ]);
                return;
            }

            // مدیریت state برای دریافت تاریخ پایان و فراخوانی getCustomersByDateRange
            if ($state === 'waiting_end_date') {
                $endDate = $this->text;
                $startDate = $this->fileHandler->getStartDate($chatId);
                $messageId = $this->fileHandler->getMessageId($chatId);
                $this->deleteMessageWithDelay();

                if (!$this->isValidDate($endDate)) {
                    $this->sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ فرمت تاریخ اشتباه است. لطفاً به فرمت YYYY-MM-DD یا 1403/01/25 وارد کنید."
                    ]);
                    return;
                }

                // بررسی ترتیب تاریخ‌ها
                if (strtotime($endDate) < strtotime($startDate)) {
                    $this->sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد."
                    ]);
                    return;
                }

                // فراخوانی متد getCustomersByDateRange
                $customersByDate = $this->db->getCustomersByDateRange($chatId, $startDate, $endDate);

                $text = "📋 مشتریان ثبت شده از $startDate تا $endDate:\n";
                $keyboard = [];

                if (empty($customersByDate)) {
                    $text .= "هیچ مشتری در این بازه زمانی ثبت نشده است.";
                } else {
                    foreach ($customersByDate as $customer) {
                        $keyboard[] = [
                            [
                                'text' => $customer['name'] . " (" . $this->getStatusText($customer['status']) . ")",
                                'callback_data' => 'customer_' . $customer['id']
                            ]
                        ];
                    }
                }

                $keyboard[] = [['text' => '🔍 جستجوی بازه جدید', 'callback_data' => 'manual_date_input']];
                $keyboard[] = [['text' => '🔙 بازگشت به پنل تاریخ‌ها', 'callback_data' => 'show_dates_panel']];
                $keyboard[] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'cancel']];

                $this->fileHandler->saveState($chatId, ""); // ریست state

                $this->sendRequest('editMessageText', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'message_id' => $messageId,
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
                ]);
                return;
            }
        if (str_starts_with($callbackData, 'customer_creation') || str_starts_with($callbackData, 'back_name')) {
            $text = "📝 لطفاً نام کامل مشتری را وارد کنید:";
            $keyboard = [
                [['text' => '↩️ برگشت', 'callback_data' => 'back']],
            ];
            $this->fileHandler->saveMessageId($chatId, $messageId);
            $this->fileHandler->saveState($chatId, "witting_customer_creation_name");

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
            ]);
        } elseif (str_starts_with($callbackData, 'back')) {
            $this->showMainMenu($this->chatId, $messageId);
            $this->fileHandler->saveState($this->chatId, ""); // Reset state on returning to main menu
        } elseif (str_starts_with($callbackData, 'cancel')) {
            $this->fileHandler->saveState($this->chatId, "");
            $this->showMainMenu($this->chatId, $messageId);
        } elseif (str_starts_with($callbackData, 'customer_') && !str_contains($callbackData, 'creation')) { // Ensure it's not 'customer_creation'
            $customerId = str_replace('customer_', '', $callbackData);
            $customer = $this->db->getCustomersbyId($customerId);

            if ($customer) {
                $text = "📋 اطلاعات مشتری:\n";
                $text .= "نام: " . ($customer['name'] ?? 'N/A') . "\n";
                $text .= "شماره تماس: " . ($customer['phone'] ?? 'N/A') . "\n";
                $text .= "ایمیل کاربر: " . ($customer['email'] ?? 'N/A') . "\n";
                // Assuming the database column is 'status', not 'statuse'
                $text .= "وضعیت مشتری: " . $this->getStatusText($customer['status'] ?? 'N/A') . "\n"; 
                $text .= "یادداشت: " . ($customer['note'] ?? 'ندارد') . "\n"; 
            } else {
                $text = "❗️ مشتری پیدا نشد.";
            }
            
            $keyboard = []; 
            $keyboard[] = [
                ['text' => '📝 ثبت مشتری جدید', 'callback_data' => 'customer_creation']
            ];
            $keyboard[] = [
                ['text' => '🔙 بازگشت به لیست مشتریان', 'callback_data' => 'list_customers_page_1'] 
            ];
            $keyboard[] = [
                ['text' => '❌ لغو و بازگشت به منو', 'callback_data' => 'cancel']
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
                'parse_mode' => 'HTML'
            ]);
            
            return;
            
} elseif (str_starts_with($callbackData, 'show_dates_panel')) {
    $text = "📅 لطفاً تاریخ مورد نظر را انتخاب کنید:";
    $uniqueDates = $this->db->getUniqueCustomerRegistrationDates($chatId); // حالا این تابع adminChatId را می‌پذیرد

    $keyboard[] = [['text' => ' امروز', 'callback_data' => 'filter_date_today'],
                   ['text' => ' دیروز', 'callback_data' => 'filter_date_yesterday']];
    $keyboard[] = [['text' => ' هفته گذشته', 'callback_data' => 'filter_date_last_week'],
                   ['text' => ' ماه گذشته', 'callback_data' => 'filter_date_last_month']];
    $keyboard[] = [['text' => 'انتخاب بازه زمانی خاص', 'callback_data' => 'manual_date_input']];
    $keyboard[] = [['text' => '🔙 بازگشت به لیست مشتریان', 'callback_data' => 'list_customers_page_1']];
    $keyboard[] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'cancel']];
    $this->sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
    ]);
    return;
 $this->sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
    ]);
    return;
    
} elseif (str_starts_with($callbackData, 'filter_date_')) {
    $selectedDate = str_replace('filter_date_', '', $callbackData);
            $customersByDate = [];
            $filterText = "";

            switch ($selectedDate) {
                case 'today':
                    $customersByDate = $this->db->getCustomersToday($chatId);
                    $filterText = "امروز";
                    break;
                case 'yesterday':
                    $customersByDate = $this->db->getCustomersYesterday($chatId);
                    $filterText = "دیروز";
                    break;
                case 'last_week':
                    $customersByDate = $this->db->getCustomersLastWeek($chatId);
                    $filterText = "هفته گذشته";
                    break;
                case 'last_month':
                    $customersByDate = $this->db->getCustomersLastMonth($chatId);
                    $filterText = "ماه گذشته";
                    break;
            
            }
                
            $text = "📋 مشتریان ثبت شده در {$filterText}:\n";
            $keyboard = [];
            if (empty($customersByDate) && $customersByDate != null) {
                $text .= "هیچ مشتری در این تاریخ ثبت نشده است.";

            } elseif ($customersByDate != null) {
                foreach ($customersByDate as $customer) {
                    $keyboard[] = [['text' => $customer['name'] . " (" . $this->getStatusText($customer['status']) . ")", 'callback_data' => 'customer_' . $customer['id']]];
                }
            }
            $keyboard[] = [['text' => '🔙 بازگشت به پنل تاریخ‌ها', 'callback_data' => 'show_dates_panel']];
            $keyboard[] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'cancel']];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
            ]);
            $this->sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackQueryId
            ]);
            return;
        } elseif (str_starts_with($callbackData, 'list_customers')) {
            $pageSize = 5;
            $page = 1; 

            if (str_starts_with($callbackData, 'list_customers_page_')) {
                $page = (int)str_replace('list_customers_page_', '', $callbackData);
                if ($page < 1) $page = 1; 
            }
            
            $offset = ($page - 1) * $pageSize;

            $customers = $this->db->getCustomersPaginated($offset, $pageSize, $chatId);
            $totalCustomers = $this->db->getTotalCustomersCount($chatId);
            $totalPages = ceil($totalCustomers / $pageSize);

            $keyboard = [];
            if (empty($customers)) {
                $text = "❗️ شما هیچ مشتری‌ای ثبت نکرده‌اید."; 
            } else {
                $text = "📋 لیست مشتریان شما (صفحه {$page} از {$totalPages}):\n"; 
                foreach ($customers as $customer) {
                    $keyboard[] = [
                        ['text' => $customer['name'] . " (" . $this->getStatusText($customer['status']) . ")", 'callback_data' => 'customer_' . $customer['id']]
                    ];
                }
            }
            
            $paginationRow = [];
            if ($page > 1) {
                $paginationRow[] = ['text' => '⬅️ صفحه قبل', 'callback_data' => 'list_customers_page_' . ($page - 1)];
            } 
            if ($page < $totalPages) {
                $paginationRow[] = ['text' => 'صفحه بعد ➡️', 'callback_data' => 'list_customers_page_' . ($page + 1)];
            }
            if (!empty($paginationRow)) {
                $keyboard[] = $paginationRow;
            }
            $keyboard[] = [
                ['text' => '🗓️ نمایش بر اساس تاریخ', 'callback_data' => 'show_dates_panel'],
                ['text' => '📝 ثبت مشتری جدید', 'callback_data' => 'customer_creation']
            ];
            $keyboard[] = [['text' => 'جستجوی مشتریان', 'callback_data' => 'search_customers']];
            $keyboard[] = [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
            ]);

            return;
        } elseif (str_starts_with($callbackData, 'back_number')) {
            $nameCustomer = $this->fileHandler->getNameCustomer($this->chatId);
            $this->fileHandler->saveState($this->chatId, "witting_customer_creation_number"); // Set state to allow re-entering number

            $text = "<blockquote dir='rtl'>نام مشتری : $nameCustomer</blockquote>" .
                "📞 لطفاً شماره تماس مشتری جدید را وارد کنید:\n" .
                "🔑 این شماره برای ارتباط با مشتری ضروری است. لطفاً شماره را با دقت وارد کنید.";

            $keyboard = [
                [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']],
                [['text' => '↩️ برگشت به مرحله نام', 'callback_data' => 'back_name']],
            ];

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE),
                'parse_mode' => 'HTML'
            ]);
        } elseif (str_starts_with($callbackData, 'back_email')) {
            $nameCustomer = $this->fileHandler->getNameCustomer($this->chatId);
            $numberCustomer = $this->fileHandler->getPhoneCustomer($this->chatId);
            $this->fileHandler->saveState($this->chatId, "witting_customer_creation_email");

            $text = "<blockquote dir='rtl'>نام مشتری : $nameCustomer</blockquote>" .
                     "\n<blockquote dir='rtl'>  شماره تماس: $numberCustomer</blockquote>" .
                     "📞 لطفاً ایمیل مشتری جدید را وارد کنید:\n" .
                     "🔑 ایمیل برای ارتباط با مشتری کاربردی است. لطفاً ایمیل را با دقت وارد کنید.";

            $keyboard = [
                [['text' => '✉️ رد کردن مرحله ایمیل', 'callback_data' => 'skip_email']],
                [['text' => '🚫 کنسل', 'callback_data' => 'cancel']],
                [['text' => '↩️ برگشت به مرحله شماره', 'callback_data' => 'back_number']],
            ];

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE),
                'parse_mode' => 'HTML'
            ]);
            return;
        } elseif (str_starts_with($callbackData, 'cold') || str_starts_with($callbackData, 'in_progress') || str_starts_with($callbackData, 'active_customer')) {
            $statusCustomer = $callbackData;
            $this->fileHandler->saveStatusCustomer($this->chatId, $statusCustomer);

            $name = $this->fileHandler->getNameCustomer($this->chatId);
            $number = $this->fileHandler->getPhoneCustomer($this->chatId);
            $email = $this->fileHandler->getEmailCustomer($this->chatId);
            $note = $this->fileHandler->getNoteCustomer($this->chatId); 

            // Handle skipped email: convert 'skipped_email' placeholder to empty string for DB
            $emailToSave = ($email === 'skipped_email') ? '' : $email;

            $result = $this->db->insertCustomer($this->chatId, $name, $number, $emailToSave, $statusCustomer, $note);

            if ($result) {
                $text = "✅ ثبت مشتری با موفقیت انجام شد!";
                $this->fileHandler->clearCustomerCreationData($this->chatId); // Clear temp data after successful creation
                $this->fileHandler->saveState($this->chatId, ""); // Reset state
            } else {
                $text = "❗ این شماره قبلاً ثبت شده است یا خطایی در ثبت مشتری رخ داد.";
            }

            $keyboard = [
                [['text' => '📝 ثبت مشتری جدید', 'callback_data' => 'customer_creation']],
                [['text' => '📋 لیست مشتری‌ها', 'callback_data' => 'list_customers_page_1']],
                [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']],
                [['text' => '↩️ برگشت به مرحله نام', 'callback_data' => 'back_name']],
            ];


            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
            ]);
        } elseif (str_starts_with($callbackData, 'skip_email')) {
            $this->fileHandler->saveEmailCustomer($this->chatId, "skipped_email"); // Mark email as skipped
            $this->fileHandler->saveState($this->chatId, "waiting_customer_creation_status"); // Move to next step: status selection

            $name = $this->fileHandler->getNameCustomer($this->chatId);
            $numberCustomer = $this->fileHandler->getPhoneCustomer($this->chatId);
            $emailCustomer = "رد شد"; // Display text for skipped email

            $text = "<blockquote dir='rtl'>نام مشتری : $name</blockquote>" .
                     "\n<blockquote dir='rtl'>شماره تماس: $numberCustomer</blockquote>" .
                     "\n<blockquote dir='rtl'>ایمیل: $emailCustomer</blockquote>" .
                     "لطفاً وضعیت مشتری را انتخاب کنید:\n" .
                     " وضعیت مشتری می‌تواند یکی از گزینه‌های زیر باشد:";

            $keyboard = [
                [['text' => '❄️ سرد', 'callback_data' => 'cold']],
                [['text' => '🔄 در حال پیگیری', 'callback_data' => 'in_progress']],
                [['text' => '💼 مشتری بالفعل', 'callback_data' => 'active_customer']],
                [['text' => '📝 کنسل', 'callback_data' => 'cancel']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_email']],
            ];

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE),
                'parse_mode' => 'HTML'
            ]);
        }
    }


    private function getStatusText($status): string
    {
        switch ($status) {
            case 'cold':
                return 'سرد';
            case 'in_progress':
                return 'در حال پیگیری';
            case 'active_customer':
                return 'مشتری بالفعل';
            default:
                return 'وضعیت نامشخص';
        }
    }


    public function handleRequest(): void
    {
        $state = $this->fileHandler->getState($this->chatId);
        error_log("State: " . $state);
        if ($this->text === '/start') {
            $this->fileHandler->saveState($this->chatId, null);
            $this->showMainMenu($this->chatId);
            return; // Added return
        }

        
// از اینجا به بعد، کدهای مربوط به مدیریت درخواست‌ها را اضافه می‌کنیم


        if ($state == 'witting_customer_creation_name') {
            $nameCustomer = $this->text;
            $messageId = $this->fileHandler->getMessageId($this->chatId);
            $this->deleteMessageWithDelay();
            $this->fileHandler->saveNameCustomer($this->chatId, $nameCustomer);
            $this->fileHandler->saveState($this->chatId, "witting_customer_creation_number");


            $text = "<blockquote dir='rtl'>نام مشتری : $nameCustomer</blockquote>" .
                "📞 لطفاً شماره تماس مشتری جدید را وارد کنید:\n" .
                "🔑 این شماره برای ارتباط با مشتری ضروری است. لطفاً شماره را با دقت وارد کنید.";

            $keyboard = [
                [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']],
                [['text' => '↩️ برگشت به مرحله نام', 'callback_data' => 'back_name']],
            ];

            $reply_markup = [
                'inline_keyboard' => $keyboard
            ];

            $this->sendRequest('editMessageText', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'message_id' => $messageId,
                'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE),
                'parse_mode' => 'HTML'
            ]);
            return; // Added return
        }

if ($state == 'witting_customer_creation_number') {
    $numberCustomer = $this->text;
    if (!preg_match('/^09\d{9}$/', $numberCustomer)) {
        $this->sendRequest('sendMessage', [
            'chat_id' => $this->chatId,
            'text' => "❌ شماره تماس وارد شده معتبر نیست. لطفاً شماره‌ای با فرمت صحیح وارد کنید. (مثال: 09345678912)",
        ]);
        return;
    }
    
    $name = $this->fileHandler->getNameCustomer($this->chatId);
    $messageId = $this->fileHandler->getMessageId($this->chatId);
    $this->deleteMessageWithDelay();
    $this->fileHandler->savePhoneCustomer($this->chatId, $numberCustomer);
    $this->fileHandler->saveState($this->chatId, "witting_customer_creation_email");

    $text = "<blockquote dir='rtl'>نام مشتری : $name</blockquote>" .
        "\n<blockquote dir='rtl'>شماره تماس: $numberCustomer</blockquote>" .
        "📞 لطفاً ایمیل مشتری جدید را وارد کنید:\n" .
        "🔑 ایمیل برای ارتباط با مشتری کاربردی است. لطفاً ایمیل را با دقت وارد کنید.";

    $keyboard = [
        [['text' => '✉️ رد کردن مرحله ایمیل', 'callback_data' => 'skip_email']],
        [
            ['text' => '🚫 کنسل', 'callback_data' => 'cancel'],
            ['text' => '🔙 بازگشت', 'callback_data' => 'back_number']
        ]
    ];

    $reply_markup = [
        'inline_keyboard' => $keyboard
    ];

    $this->sendRequest('editMessageText', [
        'chat_id' => $this->chatId,
        'text' => $text,
        'message_id' => $messageId,
        'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE),
        'parse_mode' => 'HTML'
    ]);
    return;
}
            $this->fileHandler->saveState($chatId, "waiting_customer_creation_status"); 
            return;     
        }
    
    private function showMainMenu($chatId, $messageId = null): void
    {
        $text = "👋 به سیستم مدیریت مشتری خوش اومدی!\nاز منوی زیر یکی از گزینه‌ها رو انتخاب کن:";
        $keyboard = [
            [['text' => '📝 ثبت مشتری جدید', 'callback_data' => 'customer_creation']],
            [['text' => '📋 لیست مشتری‌ها', 'callback_data' => 'list_customers_page_1']],
            [['text' => '💬 یادداشت پیگیری', 'callback_data' => 'add_followup_note']],
            [['text' => '📞 ثبت تماس / جلسه', 'callback_data' => 'log_interaction']],
            [['text' => '🔔 یادآور پیگیری', 'callback_data' => 'set_reminder']],
            [['text' => '📊 گزارش عملکرد', 'callback_data' => 'show_report']],
            [['text' => '⚙️ تنظیمات', 'callback_data' => 'settings_menu']],
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
            error_log("cURL Error for method {$method}: {$curlError}");
            return false;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage = $errorResponse['description'] ?? 'Unknown error';
            error_log("Telegram API Error for method {$method}: HTTP {$httpCode} - {$errorMessage}. Response: " . $response);
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
    }
private function isValidDate($date): bool
{
   if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
     if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $date)) {
       return true; 
    }
    
    return false;
}


}
?>
