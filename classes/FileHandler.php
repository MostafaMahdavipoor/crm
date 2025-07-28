<?php

namespace Bot;

class FileHandler
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../parent_ids.json';
    }

    public function saveState($chatId, $state)
    {
        $data = $this->getAllData();
        $data[$chatId]['state'] = $state;
        $this->saveAllData($data);
    }

    public function getState($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['state'] ?? NULL;
    }

    private function getAllData()
    {
        $content = file_get_contents($this->filePath);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            return [];
        }
        return $data ?? [];
    }

    private function saveAllData($data)
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $file = fopen($this->filePath, 'c+');
        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            fwrite($file, $jsonData);
            fflush($file);
            flock($file, LOCK_UN);
        }
        fclose($file);
    }

    public function addMessageId($chatId, $messageId)
    {
        $data = $this->getAllData();
        if (!isset($data[$chatId]['message_ids'])) {
            $data[$chatId]['message_ids'] = [];
        }
        $data[$chatId]['message_ids'][] = $messageId;
        $this->saveAllData($data);
    }

    public function getMessageIds($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['message_ids'] ?? [];
    }

    public function clearMessageIds($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['message_ids'])) {
            unset($data[$chatId]['message_ids']);
        }
        $this->saveAllData($data);
    }
 public function saveUser($chatId, $newUserData)
    {
        if (!is_numeric($chatId)) {
            return ;
        }

        $data = $this->getAllData();
        $existingUser = $data[$chatId] ?? [];

        foreach ($newUserData as $key => $value) {
            $existingUser[$key] = $value;
        }

        $data[$chatId] = $existingUser;
        $this->saveAllData($data);

        
    }

    public function getUser($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId] ?? NULL;
    }

    public function saveMessageId($chatId, $messageId)
    {
        $data = $this->getAllData();
        $data[$chatId]['message_id'] = $messageId;
        $this->saveAllData($data);
    }

    public function getMessageId($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['message_id'] ?? null;
    }

    public function saveNameCustomer($chatId, $nameCustomer)
    {
        $data = $this->getAllData();
        $data[$chatId]['name'] = $nameCustomer;
        $this->saveAllData($data);
    }

    public function getNameCustomer($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['name'] ?? null;
    }

    public function savePhoneCustomer($chatId, $phoneCustomer)
    {
        $data = $this->getAllData();
        $data[$chatId]['phone'] = $phoneCustomer;
        $this->saveAllData($data);
    }

    public function getPhoneCustomer($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['phone'] ?? null;
    }

    public function saveStatusCustomer($chatId, $statusCustomer)
    {
        $data = $this->getAllData();
        $data[$chatId]['status'] = $statusCustomer;
        $this->saveAllData($data);
    }

    public function getStatusCustomer($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['status'] ?? null;
    }

    public function saveNoteCustomer($chatId, $noteCustomer)
    {
        $data = $this->getAllData();
        $data[$chatId]['note'] = $noteCustomer;
        $this->saveAllData($data);
    }

    public function getNoteCustomer($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['note'] ?? null;
    }

    public function saveEmailCustomer($chatId, $emailCustomer)
    {
        $data = $this->getAllData();
        $data[$chatId]['email'] = $emailCustomer;
        $this->saveAllData($data);
    }

    public function getEmailCustomer($chatId)
    {
        $data = $this->getAllData();
        return $data[$chatId]['email'] ?? null;
    }

    public function clearCustomerCreationData($chatId): void
    {
        $this->clearNameCustomer($chatId);
        $this->clearPhoneCustomer($chatId);
        $this->clearEmailCustomer($chatId);
        $this->clearStatusCustomer($chatId);
        $this->clearNoteCustomer($chatId);
    }

    // متدهای مربوط به تاریخ - اصلاح شده
    public function saveStartDate($chatId, $startDate)
    {
        $data = $this->getAllData();
        $data[$chatId]['start_date'] = $startDate;
        $this->saveAllData($data);
    }

    public function getStartDate($chatId): ?string
    {
        $data = $this->getAllData();
        return $data[$chatId]['start_date'] ?? null;
    }

    public function saveEndDate($chatId, $endDate): void
    {
        $data = $this->getAllData();
        $data[$chatId]['end_date'] = $endDate;
        $this->saveAllData($data);
    }

    public function getEndDate($chatId): ?string
    {
        $data = $this->getAllData();
        return $data[$chatId]['end_date'] ?? null;
    }

    // متدهای پاک‌سازی که احتمالاً نیاز دارید
    private function clearNameCustomer($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['name'])) {
            unset($data[$chatId]['name']);
        }
        $this->saveAllData($data);
    }

    private function clearPhoneCustomer($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['phone'])) {
            unset($data[$chatId]['phone']);
        }
        $this->saveAllData($data);
    }

    private function clearEmailCustomer($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['email'])) {
            unset($data[$chatId]['email']);
        }
        $this->saveAllData($data);
    }

    private function clearStatusCustomer($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['status'])) {
            unset($data[$chatId]['status']);
        }
        $this->saveAllData($data);
    }

    private function clearNoteCustomer($chatId)
    {
        $data = $this->getAllData();
        if (isset($data[$chatId]['note'])) {
            unset($data[$chatId]['note']);
        }
        $this->saveAllData($data);
    }
}