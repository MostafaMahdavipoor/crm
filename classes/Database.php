<?php

namespace Bot;

use Exception;
use mysqli;
use Config\AppConfig;

class Database
{
    private $mysqli;
    private $botLink;

    public function __construct()
    {
        $config = AppConfig::getConfig();
        $this->botLink = $config['bot']['bot_link'];
        $dbConfig = $config['database'];
        $this->mysqli = new mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );
        if ($this->mysqli->connect_errno) {
            error_log("❌ Database Connection Failed: " . $this->mysqli->connect_error);
            exit();
        }
        $this->mysqli->set_charset("utf8mb4");
    }

    public function saveUser($user, $entryToken = null)
    {
        $excludedUsers = [193551966];
        if (in_array($user['id'], $excludedUsers)) {
            return;
        }

        $stmt = $this->mysqli->prepare("SELECT username, first_name, last_name, language FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();

            $username = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName = $user['last_name'] ?? '';
            $language = $user['language_code'] ?? 'en';

            $stmt = $this->mysqli->prepare("
            INSERT INTO users (chat_id, username, first_name, last_name, language, last_activity, entry_token) 
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
            $stmt->bind_param(
                "isssss",
                $user['id'],
                $username,
                $firstName,
                $lastName,
                $language,
                $entryToken
            );
            $stmt->execute();
        } else {
            $stmt->close();

            $username = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName = $user['last_name'] ?? '';
            $language = $user['language_code'] ?? 'en';

            $stmt = $this->mysqli->prepare("
            UPDATE users 
            SET username = ?, first_name = ?, last_name = ?, language = ?, last_activity = NOW()
            WHERE chat_id = ?
        ");
            $stmt->bind_param(
                "ssssi",
                $username,
                $firstName,
                $lastName,
                $language,
                $user['id']
            );
            $stmt->execute();
        }
    }

    public function getAllUsers()
    {
        $query = "SELECT * FROM users";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("❌ Failed to prepare statement in getAllUsers: " . $this->mysqli->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log("❌ Failed to execute statement in getAllUsers: " . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $users;
    }

    public function getAdmins()
    {
        $stmt = $this->mysqli->prepare("SELECT id, chat_id, username FROM users WHERE is_admin = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $admins;
    }

    public function getUsernameByChatId($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['username'] ?? 'Unknown';
    }

    public function setUserLanguage($chatId, $language)
    {
        $stmt = $this->mysqli->prepare("UPDATE `users` SET `language` = ? WHERE `chat_id` = ?");
        $stmt->bind_param("si", $language, $chatId);
        return $stmt->execute();
    }

    public function getUserByUsername($username)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getUserLanguage($chatId)
    {
        // Changed 's' to 'i' for chat_id as it's an integer
        $stmt = $this->mysqli->prepare("SELECT `language` FROM `users` WHERE `chat_id` = ? LIMIT 1");
        $stmt->bind_param('i', $chatId); 
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['language'] ?? 'fa';
    }

    public function getUserInfo($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `username`, `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        if (!$stmt) {
            error_log("Failed to prepare statement: " . $this->mysqli->error);
            return null;
        }
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        if (!$user) {
            error_log("User not found for chat_id: {$chatId}");
            return null;
        }
        return $user;
    }

    public function getUserByChatIdOrUsername($identifier)
    {
        if (is_numeric($identifier)) {
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ?");
            $stmt->bind_param("i", $identifier);
        } else {
            $username = ltrim($identifier, '@');
            $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    }

    public function getUserFullName($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT `first_name`, `last_name` FROM `users` WHERE `chat_id` = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
    }

    public function getUsersBatch($limit = 20, $offset = 0)
    {
        // Removed unnecessary call to $this->db->getTotalCustomersCount($admin_chatId);
        $query = "SELECT id, chat_id, username, first_name, last_name, join_date, last_activity, status, language, is_admin, entry_token 
                  FROM users 
                  ORDER BY id ASC 
                  LIMIT ? OFFSET ?";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("❌ Prepare failed: " . $this->mysqli->error);
            return [];
        }
        $stmt->bind_param("ii", $limit, $offset);
        if (!$stmt->execute()) {
            error_log("❌ Execute failed: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function updateUserStatus($chatId, $status)
    {
        $query = "UPDATE users SET status = ? WHERE chat_id = ?";
        $stmt = $this->mysqli->prepare($query);
        if (!$stmt) {
            error_log("Database Error: " . $this->mysqli->error);
            return false;
        }
        $stmt->bind_param("si", $status, $chatId);
        if (!$stmt->execute()) {
            error_log("Error updating status for User ID: $chatId");
            $stmt->close();
            return false;
        }
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows > 0;
    }

    public function isAdmin($chatId)
    {
        $stmt = $this->mysqli->prepare("SELECT is_admin FROM users WHERE chat_id = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user && $user['is_admin'] == 1;
    }

    public function getUserByUserId($userId)
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE chat_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function insertCustomer($adminChatId, $name, $phone, $email, $status, $note = null)
    {
        // بررسی وجود مشتری قبلی فقط برای همین ادمین
        $stmt = $this->mysqli->prepare("SELECT id FROM customers WHERE admin_chat_id = ? AND phone = ? LIMIT 1");
        if (!$stmt) {
            error_log("❌ Prepare failed for insertCustomer (check existing): " . $this->mysqli->error);
            return false;
        }
        $stmt->bind_param("is", $adminChatId, $phone); // adminChatId is integer, phone is string
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            return false; // اگر مشتری با همین شماره برای این ادمین قبلاً وجود دارد
        }
        $stmt->close();

        // افزودن مشتری جدید
        $stmt = $this->mysqli->prepare("
        INSERT INTO customers (admin_chat_id, name, phone, email, status, note, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        if (!$stmt) {
            error_log("❌ Prepare failed for insertCustomer (insert new): " . $this->mysqli->error);
            return false;
        }
        // adminChatId (i), name (s), phone (s), email (s), status (s), note (s)
        $stmt->bind_param("isssss", $adminChatId, $name, $phone, $email, $status, $note);
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            error_log("❌ Execute failed for insertCustomer: " . $stmt->error);
            $stmt->close();
            return false;
        }
    }

    public function getCustomers(){
        // این تابع بدون فیلتر admin_chat_id تمام مشتریان را برمی‌گرداند.
        // اگر می‌خواهید فقط مشتریان یک ادمین خاص را بگیرید، باید adminChatId را به عنوان پارامتر بپذیرید و کوئری را اصلاح کنید.
        $stmt = $this->mysqli->prepare("SELECT * FROM customers ORDER BY created_at DESC");
        if (!$stmt) {
            error_log("❌ Prepare failed: " . $this->mysqli->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log("❌ Execute failed: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        $customers = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $customers;
    }
    
    public function getCustomersbyId($customerId){
        $stmt = $this->mysqli->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
        if (!$stmt) {
            error_log("❌ Prepare failed for getCustomersbyId: " . $this->mysqli->error);
            return [];
        }
        $stmt->bind_param("i", $customerId);
        if (!$stmt->execute()) {
            error_log("❌ Execute failed for getCustomersbyId: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        $stmt->close();
        return $customer;
    }

   public function getUniqueCustomerRegistrationDates($adminChatId){
        $dates = [];
        $stmt = $this->mysqli->prepare("SELECT DISTINCT DATE(created_at) as registration_date FROM customers WHERE admin_chat_id = ? ORDER BY registration_date DESC");
        if (!$stmt) {
            error_log("❌ Prepare failed for getUniqueCustomerRegistrationDates: " . $this->mysqli->error);
            return [];
        }
        $stmt->bind_param("i", $adminChatId);
        if (!$stmt->execute()) {
            error_log("❌ Execute failed for getUniqueCustomerRegistrationDates: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dates[] = $row['registration_date'];
        }
        $stmt->close();
        return $dates;
    }
    
    // تابع قبلاً بر اساس admin_chat_id فیلتر می‌کرد و صحیح بود.
    public function getCustomersPaginated($offset, $limit ,$adminChatId) {
        $customers = [];
        $stmt = $this->mysqli->prepare("SELECT id, name, phone, email, status, created_at AS registration_date FROM customers WHERE admin_chat_id = ? ORDER BY created_at DESC LIMIT ?, ?");
        if (!$stmt) {
            error_log("❌ Prepare failed for getCustomersPaginated: " . $this->mysqli->error);
            return [];
        }
        $stmt->bind_param("iii", $adminChatId, $offset, $limit); // `adminChatId` is integer
        if (!$stmt->execute()) {
            error_log("❌ Execute failed for getCustomersPaginated: " . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
        $stmt->close();
        return $customers;
    }

    public function getTotalCustomersCount($adminChatId = null) { 
        $count = 0;
        $sql = "SELECT COUNT(id) AS total_count FROM customers";
        $params = []; 
        $types = ""; 

        if ($adminChatId !== null) { 
            $sql .= " WHERE admin_chat_id = ?"; 
            $params[] = $adminChatId; 
            $types .= "i"; 
        }

        $stmt = $this->mysqli->prepare($sql);
        
        if (!$stmt) {
            error_log("❌ Prepare failed for getTotalCustomersCount: " . $this->mysqli->error);
            return 0;
        }
        
        if (!empty($params)) {
            // این تابع کمکی باید در کلاس Database.php شما وجود داشته باشد:
            $bindArgs = array_merge([$types], $params);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindArgs));
        }
        
        if (!$stmt->execute()) {
            error_log("❌ Execute failed for getTotalCustomersCount: " . $stmt->error);
            return 0;
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $count = $row['total_count'];
        
        $stmt->close();
        return $count;
    }

    private function refValues($arr){
        if (strnatcmp(phpversion(),'5.3') >= 0) {
            $refs = [];
            foreach($arr as $key => $value) {
                $refs[$key] = &$arr[$key];
            }
            return $refs;
        }
        return $arr;
    }

    // اضافه کردن تابع getCustomersByDate بر اساس نیاز BotHandler
    public function getCustomersByDate(int $adminChatId, string $date): array
    {
        $customers = [];
        try {
            $stmt = $this->mysqli->prepare(
                "SELECT * FROM customers WHERE admin_chat_id = ? AND DATE(created_at) = ? ORDER BY created_at DESC"
            );
            if (!$stmt) {
                error_log("❌ Prepare failed for getCustomersByDate: " . $this->mysqli->error);
                return [];
            }
            $stmt->bind_param("is", $adminChatId, $date); // adminChatId is int, date is string
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching customers by date: " . $e->getMessage());
            return [];
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
    }
     public function getCustomersToday(int $adminChatId): array
    {
        $today = date('Y-m-d');
        return $this->getCustomersByDate($adminChatId, $today);
    }
     public function getCustomersYesterday(int $adminChatId): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return $this->getCustomersByDate($adminChatId, $yesterday);
    }
     public function getCustomersLastWeek(int $adminChatId): array
    {
        $customers = [];
        try {
            $stmt = $this->mysqli->prepare(
                "SELECT * FROM customers WHERE admin_chat_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY created_at DESC"
            );
            if (!$stmt) {
                error_log("❌ Prepare failed for getCustomersLastWeek: " . $this->mysqli->error);
                return [];
            }
            $stmt->bind_param("i", $adminChatId);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching customers for last week: " . $e->getMessage());
            return [];
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
    }
  public function getCustomersLastMonth(int $adminChatId): array
    {
        $customers = [];
        try {
            $stmt = $this->mysqli->prepare(
                "SELECT * FROM customers WHERE admin_chat_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) ORDER BY created_at DESC"
            );
            if (!$stmt) {
                error_log("❌ Prepare failed for getCustomersLastMonth: " . $this->mysqli->error);
                return [];
            }
            $stmt->bind_param("i", $adminChatId);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching customers for last month: " . $e->getMessage());
            return [];
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
        
    }
    public function getCustomersByDateRange($adminChatId, $startDate, $endDate)
{
    $sql = "SELECT * FROM customers 
            WHERE admin_chat_id = ? 
            AND DATE(created_at) BETWEEN ? AND ? 
            ORDER BY created_at DESC";
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$adminChatId, $startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

}
