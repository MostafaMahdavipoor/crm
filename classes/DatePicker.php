<?php

namespace Bot;

require_once __DIR__ . "/jdf.php";


class DatePicker
{
    private $jalaliMonths = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    ];

    public function generate($year = null, $month = null, $day = null, $prefix = 'datepicker')
    {
        if ($year === null || $month === null || $day === null) {
            $today = new \DateTime('now', new \DateTimeZone('Asia/Tehran'));
            $g_year = (int)$today->format('Y');
            $g_month = (int)$today->format('n');
            $g_day = (int)$today->format('j');

            list($year, $month, $day) = jdf::gregorian_to_jalali($g_year, $g_month, $g_day);
        }
        if ($year == 0 || $month == 0 ||  $day == 0) {
            $year = 1404;
            $month = 4;
            $day = 15;
        }

        $text = "🗓 لطفاً تاریخ مورد نظر را انتخاب کنید:\n\n";
        $text .= "<b>تاریخ انتخاب شده:</b> <code>$year/$month/$day</code>";

        $reply_markup = [
            'inline_keyboard' => $this->generateKeyboard($year, $month, $day, $prefix)
        ];

        return [
            'text' => $text,
            'reply_markup' => json_encode($reply_markup)
        ];
    }

    /**
     * کیبورد شیشه‌ای را بر اساس تاریخ ورودی تولید می‌کند
     */
    private function generateKeyboard($year, $month, $day, $prefix)
    {
        $keyboard = [];

        // --- ردیف انتخاب سال ---
        $keyboard[] = [
            ['text' => '▼', 'callback_data' => "{$prefix}-year_decr-{$year}-{$month}-{$day}"],
            ['text' => "سال: $year", 'callback_data' => "{$prefix}-noop"], // No-op = No Operation
            ['text' => '▲', 'callback_data' => "{$prefix}-year_incr-{$year}-{$month}-{$day}"]
        ];

        // --- ردیف‌های انتخاب ماه ---
        $monthButtons = [];
        foreach ($this->jalaliMonths as $num => $name) {
            $monthButtons[] = [
                'text' => ($num == $month ? '✅ ' : '') . $name,
                'callback_data' => "{$prefix}-set_month_{$num}-{$year}-{$month}-{$day}"
            ];
        }
        // هر ردیف شامل ۳ ماه
        $keyboard = array_merge($keyboard, array_chunk($monthButtons, 3));

        // --- ردیف‌های انتخاب روز ---
        $dayButtons = [];
        // تعیین تعداد روزهای ماه (در نظر گرفتن سال کبیسه برای اسفند)
        $daysInMonth = 31;
        if ($month > 6 && $month < 12) {
            $daysInMonth = 30;
        } elseif ($month == 12) {
            // این یک محاسبه ساده برای سال کبیسه است، برای دقت بیشتر می‌توان از کتابخانه‌های دقیق‌تر استفاده کرد
            $isLeap = ($year % 33) % 4 == 1;
            $daysInMonth = $isLeap ? 30 : 29;
        }

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dayButtons[] = [
                'text' => ($d == $day ? '✅' : '') . $d,
                'callback_data' => "{$prefix}-set_day_{$d}-{$year}-{$month}-{$day}"
            ];
        }
        // هر ردیف شامل ۷ روز
        $keyboard = array_merge($keyboard, array_chunk($dayButtons, 7));

        // --- ردیف ثبت نهایی ---
        $keyboard[] = [
            ['text' => '✔️ ثبت تاریخ', 'callback_data' => "{$prefix}-confirm-{$year}-{$month}-{$day}"]
        ];

        return $keyboard;
    }

    /**
     * callback_data را پردازش کرده و تاریخ جدید یا وضعیت را برمی‌گرداند
     *
     * @param string $callbackData
     * @return array|string
     */
    public function handleCallback($callbackData)
    {
        // فرمت: {prefix}-{action}-{year}-{month}-{day}
        $parts = explode('-', $callbackData);
        $prefix = $parts[0];
        $action = $parts[1];
        $year = (int)$parts[2];
        $month = (int)$parts[3];
        $day = (int)$parts[4];


        // پردازش اکشن‌ها
        if (str_starts_with($action, 'year_')) {
            $year += ($action === 'year_incr' ? 1 : -1);
        } elseif (str_starts_with($action, 'set_month_')) {
            $month = (int)str_replace('set_month_', '', $action);
        } elseif (str_starts_with($action, 'set_day_')) {
            $day = (int)str_replace('set_day_', '', $action);
        } elseif ($action === 'confirm') {
            return [
                'status' => 'confirmed',
                'date' => ['year' => $year, 'month' => $month, 'day' => $day]
            ];
        } elseif ($action === 'noop') {
            return ['status' => 'noop']; // هیچ کاری انجام نده
        }

        // پس از هر تغییر، تاریخ جدید را برای بازрисов کیبورد برمی‌گردانیم
        return [
            'status' => 'update',
            'new_data' => $this->generate($year, $month, $day, $prefix)
        ];
    }
}
