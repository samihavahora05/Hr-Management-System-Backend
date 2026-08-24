<?php

namespace App\Services;

use App\Models\User;

class PayrollService
{
    /**
     * Get default configurable earnings particulars.
     */
    public static function getDefaultEarnings(float $baseSalary = 0): array
    {
        if ($baseSalary <= 0) {
            $baseSalary = 50000.00;
        }

        $basic = round($baseSalary * 0.50, 2);
        $hra = round($baseSalary * 0.20, 2);
        $da = round($baseSalary * 0.10, 2);
        $conveyance = round($baseSalary * 0.05, 2);
        $special = round($baseSalary * 0.15, 2);

        return [
            ['particulars' => 'Basic Salary', 'amount' => $basic],
            ['particulars' => 'Dearness Allowance (DA)', 'amount' => $da],
            ['particulars' => 'House Rent Allowance (HRA)', 'amount' => $hra],
            ['particulars' => 'Conveyance Allowance', 'amount' => $conveyance],
            ['particulars' => 'Special Allowance', 'amount' => $special],
            ['particulars' => 'Other Allowance', 'amount' => 0.00],
        ];
    }

    /**
     * Get default configurable deductions particulars.
     */
    public static function getDefaultDeductions(float $baseSalary = 0): array
    {
        $pf = 1800.00;
        $pt = 200.00;
        $esi = 0.00;
        $tds = 0.00;

        if ($baseSalary > 80000) {
            $tds = round(($baseSalary - 50000) * 0.10, 2);
        }

        return [
            ['particulars' => 'Provident Fund (PF)', 'amount' => $pf],
            ['particulars' => 'Employee State Insurance (ESI)', 'amount' => $esi],
            ['particulars' => 'Professional Tax (PT)', 'amount' => $pt],
            ['particulars' => 'Income Tax (TDS)', 'amount' => $tds],
            ['particulars' => 'Other Deductions', 'amount' => 0.00],
        ];
    }

    /**
     * Compute totals and Net Salary from arrays of line items.
     */
    public static function calculateSummary(array $earnings, array $deductions): array
    {
        $totalEarnings = 0.00;
        foreach ($earnings as $item) {
            $amount = floatval($item['amount'] ?? 0);
            if ($amount > 0) {
                $totalEarnings += $amount;
            }
        }

        $totalDeductions = 0.00;
        foreach ($deductions as $item) {
            $amount = floatval($item['amount'] ?? 0);
            if ($amount > 0) {
                $totalDeductions += $amount;
            }
        }

        $netSalary = max(0.00, $totalEarnings - $totalDeductions);
        $netSalaryWords = self::convertNumberToWords($netSalary);

        return [
            'total_earnings' => round($totalEarnings, 2),
            'total_deductions' => round($totalDeductions, 2),
            'net_salary' => round($netSalary, 2),
            'net_salary_words' => $netSalaryWords,
        ];
    }

    /**
     * Convert currency numeric amount to English words (e.g. Rupees Seventy Five Thousand Only).
     */
    public static function convertNumberToWords(float $number): string
    {
        if ($number <= 0) {
            return 'Rupees Zero Only';
        }

        $wholeNumber = floor($number);
        $fraction = round(($number - $wholeNumber) * 100);

        $words = self::numberToWordsInt((int) $wholeNumber);
        $result = 'Rupees ' . trim($words);

        if ($fraction > 0) {
            $paisaWords = self::numberToWordsInt((int) $fraction);
            $result .= ' and ' . trim($paisaWords) . ' Paise';
        }

        return $result . ' Only';
    }

    private static function numberToWordsInt(int $num): string
    {
        $ones = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
            14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
            18 => 'Eighteen', 19 => 'Nineteen'
        ];

        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
        ];

        if ($num === 0) {
            return 'Zero';
        }

        $output = '';

        if ($num >= 10000000) {
            $crores = floor($num / 10000000);
            $output .= self::numberToWordsInt((int) $crores) . ' Crore ';
            $num %= 10000000;
        }

        if ($num >= 100000) {
            $lakhs = floor($num / 100000);
            $output .= self::numberToWordsInt((int) $lakhs) . ' Lakh ';
            $num %= 100000;
        }

        if ($num >= 1000) {
            $thousands = floor($num / 1000);
            $output .= self::numberToWordsInt((int) $thousands) . ' Thousand ';
            $num %= 1000;
        }

        if ($num >= 100) {
            $hundreds = floor($num / 100);
            $output .= self::numberToWordsInt((int) $hundreds) . ' Hundred ';
            $num %= 100;
        }

        if ($num > 0) {
            if ($num < 20) {
                $output .= $ones[$num] . ' ';
            } else {
                $t = floor($num / 10);
                $o = $num % 10;
                $output .= $tens[$t] . ' ' . $ones[$o] . ' ';
            }
        }

        return trim($output);
    }
}
