<?php

namespace App\Services;

class StatutoryPayrollService
{
    /**
     * Calculate Indian Statutory Salary Breakdown
     */
    public static function calculateBreakdown(float $baseSalary, string $taxRegime = 'new', string $state = 'Maharashtra'): array
    {
        $basic = round($baseSalary * 0.50, 2); // Basic Salary (50% of CTC/Base)
        $hra = round($basic * 0.40, 2); // HRA (40% of Basic)
        $transport = 1600.00; // Standard Transport Allowance
        $specialAllowance = max(0, round($baseSalary - ($basic + $hra + $transport), 2));
        $grossSalary = round($basic + $hra + $transport + $specialAllowance, 2);

        // 1. Provident Fund (PF)
        // Employee PF: 12% of Basic, capped at ₹1,800/mo (12% of 15,000)
        $pfBase = min($basic, 15000.00);
        $employeePF = round($pfBase * 0.12, 2);
        $employerPF = $employeePF;

        // 2. Employee State Insurance (ESI)
        // ESI applies if Gross Salary <= ₹21,000/mo
        $employeeESI = 0.00;
        $employerESI = 0.00;
        if ($grossSalary <= 21000.00) {
            $employeeESI = round($grossSalary * 0.0075, 2);
            $employerESI = round($grossSalary * 0.0325, 2);
        }

        // 3. Professional Tax (PT) - Maharashtra Slabs
        $professionalTax = 0.00;
        if ($grossSalary > 10000.00) {
            $professionalTax = 200.00;
        } elseif ($grossSalary > 7500.00) {
            $professionalTax = 175.00;
        }

        // 4. Tax Deducted at Source (TDS) - Monthly Income Tax Estimate
        $annualGross = $grossSalary * 12;
        $tdsMonthly = 0.00;

        if ($taxRegime === 'new') {
            // FY 2024-25 New Tax Regime (Standard deduction ₹75,000)
            $taxableIncome = max(0, $annualGross - 75000);
            $annualTax = 0.00;

            if ($taxableIncome > 1500000) {
                $annualTax += ($taxableIncome - 1500000) * 0.30 + 150000;
            } elseif ($taxableIncome > 1200000) {
                $annualTax += ($taxableIncome - 1200000) * 0.20 + 90000;
            } elseif ($taxableIncome > 900000) {
                $annualTax += ($taxableIncome - 900000) * 0.15 + 45000;
            } elseif ($taxableIncome > 600000) {
                $annualTax += ($taxableIncome - 600000) * 0.10 + 15000;
            } elseif ($taxableIncome > 300000) {
                $annualTax += ($taxableIncome - 300000) * 0.05;
            }

            // Tax rebate under 87A for taxable income <= ₹7,000,000 under New Regime
            if ($taxableIncome <= 700000) {
                $annualTax = 0.00;
            } else {
                $annualTax = $annualTax * 1.04; // 4% Health & Education Cess
            }

            $tdsMonthly = round($annualTax / 12, 2);
        } else {
            // Old Tax Regime (Standard Deduction ₹50,000 + 80C PF deduction)
            $taxableIncome = max(0, $annualGross - 50000 - ($employeePF * 12));
            $annualTax = 0.00;

            if ($taxableIncome > 1000000) {
                $annualTax += ($taxableIncome - 1000000) * 0.30 + 112500;
            } elseif ($taxableIncome > 500000) {
                $annualTax += ($taxableIncome - 500000) * 0.20 + 12500;
            } elseif ($taxableIncome > 250000) {
                $annualTax += ($taxableIncome - 250000) * 0.05;
            }

            if ($taxableIncome <= 500000) {
                $annualTax = 0.00;
            } else {
                $annualTax = $annualTax * 1.04;
            }

            $tdsMonthly = round($annualTax / 12, 2);
        }

        $totalDeductions = round($employeePF + $employeeESI + $professionalTax + $tdsMonthly, 2);
        $netSalary = max(0, round($grossSalary - $totalDeductions, 2));

        return [
            'base_salary' => $baseSalary,
            'basic' => $basic,
            'hra' => $hra,
            'transport' => $transport,
            'special_allowance' => $specialAllowance,
            'gross_salary' => $grossSalary,
            'employee_pf' => $employeePF,
            'employer_pf' => $employerPF,
            'employee_esi' => $employeeESI,
            'employer_esi' => $employerESI,
            'professional_tax' => $professionalTax,
            'tds_monthly' => $tdsMonthly,
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary,
            'tax_regime' => $taxRegime,
        ];
    }
}
