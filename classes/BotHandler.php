<?php

namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;
use Bot\DatePicker;

use Bot\jdf;

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
    private $callbackId;

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
        if ($this->messageId) {
            $result = $this->sendRequest("deleteMessage", [
                "chat_id" => $this->chatId,
                "message_id" => $this->messageId
            ]);

            if (!$result) {
            }
        }
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
            error_log("اطلاعات مورد نیاز در کالبک وجود ندارد: callbackData=$callbackData, chatId=$chatId, callbackQueryId=$callbackQueryId, messageId=$messageId");
            return;

        } elseif (str_starts_with($callbackData, 'show_customer_details_')) {
            $customerId = (int)str_replace('show_customer_details_', '', $callbackData);
            error_log("INFO: User " . $chatId . " requested customer details for ID: " . $customerId);

            $customer = $this->db->getCustomersbyId($customerId); 

            if ($customer) {
                $text = "📋 **اطلاعات مشتری:**\n\n" .
                        "نام: " . htmlspecialchars($customer['name'] ?? 'N/A') . "\n" .
                        "شماره تماس: " . htmlspecialchars($customer['phone'] ?? 'N/A') . "\n" .
                        "ایمیل: " . htmlspecialchars($customer['email'] ?? 'N/A') . "\n" .
                        "وضعیت: " . $this->getStatusText($customer['status'] ?? 'N/A') . "\n" .
                        "تاریخ ثبت: " . (isset($customer['created_at']) ? jdf::jdate('Y/m/d', strtotime($customer['created_at'])) : 'N/A') . "\n" .
                        "یادداشت: " . htmlspecialchars($customer['note'] ?? 'ندارد');
                
                $keyboard = [
                    [['text' => '🔍 جستجوی جدید مشتری', 'switch_inline_query_current_chat' => '']], // دکمه برای شروع جستجوی اینلاین جدید
                    [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'cancel']]
                ];

                $this->sendRequest("editMessageText", [
                    "chat_id" => $chatId,
                    "message_id" => $messageId,
                    "text" => $text,
                    "parse_mode" => "HTML",
                    "reply_markup" => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
                ]);
            } else {
                $this->sendRequest("editMessageText", [
                    "chat_id" => $chatId,
                    "message_id" => $messageId,
                    "text" => "❌ مشتری مورد نظر یافت نشد.",
                    "reply_markup" => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'cancel']]]])
                ]);
            }
            $this->answerCallbackQuery();
            return;

        } elseif (str_starts_with($callbackData, 'cancel')) {
            $this->fileHandler->saveState($this->chatId, "");
            $this->showMainMenu($this->chatId, $messageId);
        $this->answerCallbackQuery(); 
    
        } elseif (str_starts_with($callbackData, 'create_customer')) {
            $text = "📋 لطفاً وضعیت مشتری را انتخاب کنید:";

            $keyboard = [
                [['text' => 'فعال', 'callback_data' => 'customer_status_active']],
                [['text' => 'غیرفعال', 'callback_data' => 'customer_status_inactive']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'cancel']]
            ];

            $this->fileHandler->saveState($chatId, "waiting_customer_creation_status");
            $this->fileHandler->saveMessageId($chatId, $messageId);

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
            ]);


            return;
        } elseif (str_starts_with($callbackData, 'manual_date_input')) {
            error_log("DEBUG: Date Range Flow Started for chat_id: " . $this->chatId);
            $userData = $this->fileHandler->getUser($this->chatId) ?: [];
            $userData['customer_search'] = [];
            $this->fileHandler->saveUser($this->chatId, $userData);

            $datePicker = new DatePicker();
            $pickerData = $datePicker->generate(null, null, null, 'customer_range_start');

            $this->sendRequest("editMessageText", [
                "chat_id" => $this->chatId,
                "message_id" => $messageId,
                "text" => "📅 لطفاً **تاریخ شروع** بازه گزارش مشتریان را انتخاب کنید:\n\n" . $pickerData['text'],
                "parse_mode" => "HTML",
                "reply_markup" => $pickerData['reply_markup']
            ]);
            $this->answerCallbackQuery();
            return;

        } elseif (str_starts_with($callbackData, 'customer_range_start-') || str_starts_with($callbackData, 'customer_range_end-')) {
            $datePicker = new DatePicker();
            $result = $datePicker->handleCallback($callbackData);
            $prefix = explode('-', $callbackData)[0];

            if ($result['status'] === 'update') {
                error_log("DEBUG: DatePicker Update for chat_id: " . $this->chatId);
                $this->sendRequest("editMessageText", [
                    "chat_id" => $this->chatId,
                    "message_id" => $messageId,
                    "text" => $result['new_data']['text'],
                    "parse_mode" => "HTML",
                    "reply_markup" => $result['new_data']['reply_markup']
                ]);
                $this->answerCallbackQuery();
                return;
            } elseif ($result['status'] === 'confirmed') {
                $jalaliDate = $result['date'];
                error_log("DEBUG: DatePicker Confirmed for chat_id: " . $this->chatId . " - Jalali Date: " . json_encode($jalaliDate));

                list($gy, $gm, $gd) = jdf::jalali_to_gregorian($jalaliDate['year'], $jalaliDate['month'], $jalaliDate['day']);
                $gregorianDateForDb = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
                error_log("DEBUG: Date Converted for chat_id: " . $this->chatId . " - Gregorian Date: " . $gregorianDateForDb);

                $userData = $this->fileHandler->getUser($this->chatId);

                if ($prefix === 'customer_range_start') {
                    $userData['customer_search']['start_date'] = $gregorianDateForDb;
                    $this->fileHandler->saveUser($this->chatId, $userData);
                    error_log("INFO: Start Date Saved for chat_id: " . $this->chatId . " - Date: " . $gregorianDateForDb);

                    $this->answerCallbackQuery("✅ تاریخ شروع ثبت شد.");
                    $datePickerEnd = new DatePicker();
                    $pickerDataEnd = $datePickerEnd->generate(null, null, null, 'customer_range_end');
                    $this->sendRequest("editMessageText", [
                        "chat_id" => $this->chatId,
                        "message_id" => $messageId,
                        "text" => "📅 لطفاً **تاریخ پایان** بازه گزارش را انتخاب کنید:\n\n" . $pickerDataEnd['text'],
                        "parse_mode" => "HTML",
                        "reply_markup" => $pickerDataEnd['reply_markup']
                    ]);
                    return;
                } elseif ($prefix === 'customer_range_end') {
                    $searchData = $userData['customer_search'] ?? null;
                    if (!$searchData || !isset($searchData['start_date'])) {
                        error_log("ERROR: Start date not found in session for chat_id: " . $this->chatId);
                        $this->answerCallbackQuery("❌ خطا: اطلاعات تاریخ شروع یافت نشد.", true);
                        return;
                    }
                    $startDate = $searchData['start_date'];
                    $endDate = $gregorianDateForDb;
                     error_log("DEBUG: Dates going into strtotime for chat_id: " . $this->chatId . 
                    " - Start Date String: '" . $startDate . "'" . 
                    " (Length: " . strlen($startDate) . ", Type: " . gettype($startDate) . ")" .
                    ", End Date String: '" . $endDate . "'" .
                    " (Length: " . strlen($endDate) . ", Type: " . gettype($endDate) . ")");
                
                      error_log("DEBUG: Hex dump of dates for chat_id: " . $this->chatId .
                    " - Start (Hex): " . bin2hex($startDate) .
                    ", End (Hex): " . bin2hex($endDate));
                     $startTimestamp = strtotime($startDate);
                     $endTimestamp = strtotime($endDate);
                      error_log("DEBUG: Timestamps after strtotime for chat_id: " . $this->chatId . 
                     " - Start TS: " . ($startTimestamp === false ? 'FALSE' : $startTimestamp) . 
                     ", End TS: " . ($endTimestamp === false ? 'FALSE' : $endTimestamp));
                     error_log("DEBUG: User Data after saving Start Date: " . json_encode($userData['customer_search']) . " for chat_id: " . $this->chatId);
                     error_log("INFO: Start Date Saved for chat_id: " . $this->chatId . " - Date: " . $gregorianDateForDb);
                     error_log("DEBUG: Verifying saved start_date for chat_id: " . $this->chatId . " - From userData: " . ($userData['customer_search']['start_date'] ?? 'NOT SET'));
                   
                     if ($startTimestamp === false || $endTimestamp === false) {
                       $this->answerCallbackQuery("❌ خطا: تاریخ‌های وارد شده نامعتبر هستند.", true);
                         return;
                    }
                    if ($endTimestamp < $startTimestamp) {
                        error_log("WARNING: End date was before start date for chat_id: " . $this->chatId);
                        $this->answerCallbackQuery("⚠️ تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد!", true); 
                        $keyboard = [
                            [['text' => '🔄 شروع دوباره انتخاب تاریخ', 'callback_data' => 'manual_date_input']],
                            [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'cancel']]
                        ];

                        $this->sendRequest("editMessageText", [
                            "chat_id" => $this->chatId,
                            "message_id" => $messageId, 
                            "text" => "❌ **خطا:** تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد. لطفاً از دکمه‌های زیر استفاده کنید:",
                            "parse_mode" => "Markdown",
                            "reply_markup" => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
                        ]);
                        return;
                    }

                    error_log("INFO: Executing DB Search for chat_id: " . $this->chatId . " with range " . $startDate . " to " . $endDate);
                    $customersByDate = $this->db->getCustomersByDateRange($this->chatId, $startDate, $endDate);
                    error_log("INFO: DB Search Result for chat_id: " . $this->chatId . " - Customers Found: " . (is_array($customersByDate) ? count($customersByDate) : 'Error'));

                    $startJalali = jdf::jdate('Y/m/d', strtotime($startDate));
                    $endJalali = jdf::jdate('Y/m/d', strtotime($endDate));
                    $text = "📋 مشتریان ثبت شده از <code>$startJalali</code> تا <code>$endJalali</code>:\n\n";
                    $keyboard = [];

                    if (empty($customersByDate)) {
                        $text .= "❌ هیچ مشتری در این بازه زمانی ثبت نشده است.";
                    } else {
                        $text .= "تعداد کل: <b>" . count($customersByDate) . "</b> مشتری\n\n";
                        foreach ($customersByDate as $customer) {
                            $keyboard[] = [['text' => $customer['name'] . " (" . $this->getStatusText($customer['status']) . ")", 'callback_data' => 'customer_' . $customer['id']]];
                        }
                    }
                    $keyboard[] = [['text' => '🔍 جستجوی بازه جدید', 'callback_data' => 'manual_date_input']];
                    $keyboard[] = [['text' => '🔙 بازگشت به پنل تاریخ‌ها', 'callback_data' => 'show_dates_panel']];
                    $keyboard[] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'cancel']];

                    unset($userData['customer_search']);
                    $this->fileHandler->saveUser($this->chatId, $userData);
                    $this->fileHandler->saveState($this->chatId, "");

                    $this->sendRequest('editMessageText', ['chat_id' => $this->chatId, 'text' => $text, 'message_id' => $messageId, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)]);
                    $this->answerCallbackQuery();
                    return;
                }
            }
        } else if (str_starts_with($callbackData, 'customer_creation') || str_starts_with($callbackData, 'back_name')) {
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
            $uniqueDates = $this->db->getUniqueCustomerRegistrationDates($chatId); 

            $keyboard[] = [
                ['text' => ' امروز', 'callback_data' => 'filter_date_today'],
                ['text' => ' دیروز', 'callback_data' => 'filter_date_yesterday']
            ];
            $keyboard[] = [
                ['text' => ' هفته گذشته', 'callback_data' => 'filter_date_last_week'],
                ['text' => ' ماه گذشته', 'callback_data' => 'filter_date_last_month']
            ];
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
                $page = (int) str_replace('list_customers_page_', '', $callbackData);
                if ($page < 1)
                    $page = 1;
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
            $keyboard[] = [['text' => '🔍 جستجوی مشتری ', 'switch_inline_query_current_chat' => '']];
            $keyboard[] = [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']];

            $this->sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
            ]);

            return;
        
        }elseif (str_starts_with($callbackData, 'back_number')) {
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
                "\n<blockquote dir='rtl'>  شماره تماس: $numberCustomer</blockquote>" .
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
                [['text' => '↩️ برگشت به مرحله نام', 'callback_data' => 'back_name']],
                [['text' => '🔙 لغو و بازگشت به منو', 'callback_data' => 'cancel']],
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
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_email']],
                [['text' => '📝 کنسل', 'callback_data' => 'cancel']],
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

        if ($this->text === '/start') {
            $this->fileHandler->saveState($this->chatId, '');
            $this->showMainMenu($this->chatId);
            return; // Added return
        }
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

        if ($state == 'witting_customer_creation_email') {
            $emailCustomer = $this->text;
            $nameCustomer = $this->fileHandler->getNameCustomer($this->chatId);
            $numberCustomer = $this->fileHandler->getPhoneCustomer($this->chatId);
            $messageId = $this->fileHandler->getMessageId($this->chatId);
            $this->deleteMessageWithDelay();
            $this->fileHandler->saveEmailCustomer($this->chatId, $emailCustomer);
            $this->fileHandler->saveState($this->chatId, "waiting_customer_creation_status");

            $text = "<blockquote dir='rtl'>نام مشتری : $nameCustomer</blockquote>" .
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
            return;
        }

        if (
            $textMessage === "/start" ||
            $textMessage === "بازگشت به منوی اصلی" ||
            $textMessage === "برگشت به منوی اصلی" ||
            $textMessage === "/menu"
        ) {
            $this->showMainMenu($chatId, $messageId);
            $this->fileHandler->saveState($chatId, ""); 
            return;
        } else {
            $text = "متاسفم، متوجه درخواست شما نشدم. لطفاً از گزینه‌های موجود استفاده کنید.\n\n" .
                    "برای جستجوی مشتریان، می‌توانید در هر چتی نام ربات را به همراه کلمه کلیدی تایپ کنید. مثلاً: `@YourBotUsername نام مشتری`";

            $keyboard = [
                [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'cancel']]
            ];

            $this->sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => $text,
                "reply_markup" => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
            ]);
            return;
        }
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
            [['text' => '⚙️ مدیریت', 'callback_data' => 'admin_panel']],
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
             $this->fileHandler->saveState($chatId, ""); 
   
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
    public function answerCallbackQuery(string $text = null, bool $showAlert = false): void
    {

        if (!$this->callbackId) {
            return;
        }

        $params = [
            'callback_query_id' => $this->callbackId,
        ];

        if ($text !== null) {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }

        $this->sendRequest("answerCallbackQuery", $params);
    }

    private function isValidGregorianDate($date)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate($parts[1], $parts[2], $parts[0]);
    }
        public function handleInlineQuery($inlineQuery): void
    {
        $inlineQueryId = $inlineQuery['id'];
        $queryText = trim($inlineQuery['query']);
        $results = [];

        error_log("INFO: Inline Query received from user " . $inlineQuery['from']['id'] . " with query: " . $queryText);

        if (!empty($queryText)) {
            // فراخوانی متد جستجوی مشتریان از کلاس DB
            $customers = $this->db->searchCustomers($queryText, 10); // 10 نتیجه اول

            foreach ($customers as $customer) {
                $descriptionPreview = "وضعیت: " . $this->getStatusText($customer['status'] ?? 'N/A');
                if (!empty($customer['phone'])) {
                    $descriptionPreview .= " | تلفن: " . htmlspecialchars($customer['phone']);
                }

                $results[] = [
                    'type' => 'article',
                    'id' => uniqid(), 
                    'title' => htmlspecialchars($customer['name']),
                    'description' => $descriptionPreview,
                    'input_message_content' => [
                        'message_text' => "📋 **اطلاعات مشتری:**\n\n" .
                                          "نام: " . htmlspecialchars($customer['name'] ?? 'N/A') . "\n" .
                                          "شماره تماس: " . htmlspecialchars($customer['phone'] ?? 'N/A') . "\n" .
                                          "ایمیل: " . htmlspecialchars($customer['email'] ?? 'N/A') . "\n" .
                                          "وضعیت: " . $this->getStatusText($customer['status'] ?? 'N/A') . "\n" .
                                          "تاریخ ثبت: " . (isset($customer['created_at']) ? jdf::jdate('Y/m/d', strtotime($customer['created_at'])) : 'N/A') . "\n" .
                                          "یادداشت: " . htmlspecialchars($customer['note'] ?? 'ندارد'),
                        'parse_mode' => 'HTML'
                    ],
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'مشاهده جزئیات کامل', 'callback_data' => 'https://t.me/Atefetest_bot?start=show_customer_details_' . $customer['id']]]
                        ]
                    ],
               ];
            }
        } else {
            $results[] = [
                'type' => 'article',
                'id' => uniqid(),
                'title' => '🔎 شروع جستجوی مشتریان',
                'description' => 'نام، شماره تماس یا ایمیل مشتری را وارد کنید.',
                'input_message_content' => [
                    'message_text' => 'با استفاده از Inline Mode، می‌توانید مشتریان را بر اساس نام، شماره تماس یا ایمیل جستجو کنید.'
                ],
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'cancel']]
                    ]
                ]
            ];
        }

        $this->sendRequest("answerInlineQuery", [
            'inline_query_id' => $inlineQueryId,
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cache_time' => 0 // برای نمایش نتایج زنده، کش را کم کنید
        ]);
    }

}

?>