<?php

namespace App\Helpers;

use App\Models\Employees;
use App\Services\SystemSettingsService;
use InvalidArgumentException;

/**
 * SalaryCalculator
 *
 * Computes gross salary for the labor-management system's worker categories.
 * Each category has its own pay structure (see method docblocks). All money
 * values are returned as floats rounded to 2 decimals.
 *
 * Rates are NOT hard-coded. The instance is constructed with the settings map
 * from the SystemSettings sheet (the settings route), so multipliers can be
 * changed there without code changes:
 *  - overtime_rate          → overtime multiplier
 *  - legal_holiday_rate     → holiday multiplier
 *  - night_rate             → night differential (total multiplier; the premium
 *                             added on top of base hours is night_rate − 1)
 * Any multiplier can still be overridden per call via the input array. The
 * FALLBACK constants are only used if a setting row is missing entirely, so the
 * calculator degrades safely instead of dividing by null / multiplying by zero.
 *
 * Categories
 *  - FULL_TIME : fixed monthly base salary, hourly rate derived from base.
 *  - PART_TIME : hourly rate × worked hours.
 *  - CONTRACT  : fixed monthly/project contract amount + allowances.
 *  - DAILY     : per-day rate × worked days (site/temporary labor).
 *
 * Usage:
 *   $calc = SalaryCalculator::fromSettings($systemSettingsService);
 *   $calc = new SalaryCalculator($settingsKeyValueMap);   // e.g. in tests
 *   $breakdown = $calc->computeFullTime([...]);
 */
class SalaryCalculator
{
    /**
     * Emergency fallbacks — used ONLY when the matching SystemSettings row is
     * absent. The authoritative values live in the settings sheet.
     */
    private const FALLBACK = [
        'overtime_rate'      => 1.25,
        'legal_holiday_rate' => 1.35,
        'night_rate'         => 1.5,
    ];

    /** Fallback monthly work hours when an employee has none configured. */
    public const DEFAULT_MONTHLY_WORK_HOURS = 160.0;

    /**
     * @param array $settings  SystemSettings as a key => value map.
     */
    public function __construct(private array $settings = [])
    {
    }

    /**
     * Build a calculator from the live SystemSettings sheet.
     */
    public static function fromSettings(SystemSettingsService $settings): self
    {
        return new self($settings->all());
    }

    // -------------------------------------------------------------------------
    // Rate resolution (settings-driven)
    // -------------------------------------------------------------------------

    /**
     * Resolve a numeric rate from settings, falling back only if the row is gone.
     */
    private function rate(string $key): float
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (float) $value : (self::FALLBACK[$key] ?? 0.0);
    }

    private function overtimeMultiplier(array $i): float
    {
        return isset($i['overtime_multiplier'])
            ? (float) $i['overtime_multiplier']
            : $this->rate('overtime_rate');
    }

    private function holidayMultiplier(array $i): float
    {
        return isset($i['holiday_multiplier'])
            ? (float) $i['holiday_multiplier']
            : $this->rate('legal_holiday_rate');
    }

    /**
     * Night differential premium (added on top of already-counted base hours).
     * `night_rate` is stored as a total multiplier, so the premium is rate − 1.
     * An explicit night_multiplier input is treated as the premium directly.
     */
    private function nightPremium(array $i): float
    {
        if (isset($i['night_multiplier'])) {
            return (float) $i['night_multiplier'];
        }

        return max(0.0, $this->rate('night_rate') - 1.0);
    }

    // -------------------------------------------------------------------------
    // Dispatch / helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch to the correct calculator based on an employee's type.
     *
     * @param  Employees  $employee
     * @param  array  $inputs  Period-specific values (hours, days, allowances…).
     * @return array  Salary breakdown (see per-type methods).
     */
    public function forEmployee(Employees $employee, array $inputs = []): array
    {
        return match ($employee->employment_type) {
            'FULL_TIME' => $this->computeFullTime([
                'base_salary'        => $employee->base_salary,
                'monthly_work_hours' => $employee->monthly_work_hours,
                'commute_cost'       => $employee->commute_cost_monthly ?? 0,
                ...$inputs,
            ]),
            'PART_TIME' => $this->computePartTime($inputs),
            'CONTRACT'  => $this->computeContract([
                'monthly_contract_salary' => $employee->base_salary,
                ...$inputs,
            ]),
            'DAILY'     => $this->computeDaily($inputs),
            default     => throw new InvalidArgumentException(
                "Unsupported employment type: {$employee->employment_type}"
            ),
        };
    }

    /**
     * Resolve the pay basis (HOURLY vs FIXED) for an employee row.
     *
     * `salary_type` (HOURLY_BASED | FIXED_PRICE_BASED) takes precedence; when
     * blank, falls back to employment_type (PART_TIME → hourly, otherwise fixed).
     *
     * @param  array  $employee  Employee row (associative).
     * @return string  'HOURLY' or 'FIXED'
     */
    public static function payBasis(array $employee): string
    {
        $salaryType = $employee['salary_type'] ?? null;

        if ($salaryType === 'HOURLY_BASED') {
            return 'HOURLY';
        }
        if ($salaryType === 'FIXED_PRICE_BASED') {
            return 'FIXED';
        }

        return ($employee['employment_type'] ?? null) === 'PART_TIME' ? 'HOURLY' : 'FIXED';
    }

    /**
     * Build a monthly salary record for one employee from aggregated attendance.
     *
     * FIXED-basis staff are paid their base salary plus overtime/holiday premiums
     * and a monthly commute allowance; HOURLY-basis staff are paid strictly for
     * hours worked. Daily transportation reimbursements are added on top for both.
     *
     * @param array $employee  Employee row: employee_id, employee_code, name,
     *                         employment_type, salary_type, base_salary,
     *                         monthly_work_hours, commute_cost_monthly.
     * @param array $aggregate Period totals: worked_days, total_work_hours,
     *                         overtime_hours, holiday_hours, transportation_total.
     * @param array $options   overtime_multiplier, holiday_multiplier,
     *                         hourly_rate (override).
     * @return array  Normalised record (numeric values rounded to 2 decimals).
     */
    public function computeMonthlyRecord(array $employee, array $aggregate, array $options = []): array
    {
        $baseSalary       = (float) ($employee['base_salary'] ?? 0);
        $monthlyWorkHours = (float) ($employee['monthly_work_hours'] ?? 0);
        $monthlyWorkHours = $monthlyWorkHours > 0 ? $monthlyWorkHours : self::DEFAULT_MONTHLY_WORK_HOURS;
        $commuteMonthly   = (float) ($employee['commute_cost_monthly'] ?? 0);

        $workedDays    = (float) ($aggregate['worked_days'] ?? 0);
        $totalHours    = (float) ($aggregate['total_work_hours'] ?? 0);
        $overtimeHours = (float) ($aggregate['overtime_hours'] ?? 0);
        $holidayHours  = (float) ($aggregate['holiday_hours'] ?? 0);
        $transport     = (float) ($aggregate['transportation_total'] ?? 0);

        $otMult  = $this->overtimeMultiplier($options);
        $holMult = $this->holidayMultiplier($options);

        $regularHours = max(0.0, $totalHours - $overtimeHours);
        $basis        = self::payBasis($employee);

        $hourlyRate = isset($options['hourly_rate'])
            ? (float) $options['hourly_rate']
            : $baseSalary / $monthlyWorkHours;

        $overtimePay = $overtimeHours * $hourlyRate * $otMult;
        $holidayPay  = $holidayHours * $hourlyRate * $holMult;

        if ($basis === 'FIXED') {
            // Salaried: base covers the standard month; OT/holiday paid on top.
            $regularPay       = $baseSalary;
            $commuteAllowance = $commuteMonthly;
        } else {
            // Hourly: pay strictly for hours worked; no fixed commute allowance.
            $regularPay       = $regularHours * $hourlyRate;
            $commuteAllowance = 0.0;
        }

        $gross = $regularPay + $overtimePay + $holidayPay + $commuteAllowance + $transport;

        return self::round([
            'employee_id'          => $employee['employee_id'] ?? '',
            'employee_code'        => $employee['employee_code'] ?? '',
            'name'                 => $employee['name'] ?? '',
            'employment_type'      => $employee['employment_type'] ?? '',
            'salary_type'          => $employee['salary_type'] ?? '',
            'pay_basis'            => $basis,
            'worked_days'          => $workedDays,
            'total_work_hours'     => $totalHours,
            'regular_hours'        => $regularHours,
            'overtime_hours'       => $overtimeHours,
            'holiday_hours'        => $holidayHours,
            'hourly_rate'          => $hourlyRate,
            'base_salary'          => $baseSalary,
            'regular_pay'          => $regularPay,
            'overtime_pay'         => $overtimePay,
            'holiday_pay'          => $holidayPay,
            'commute_allowance'    => $commuteAllowance,
            'transportation_total' => $transport,
            'gross_salary'         => $gross,
        ]);
    }

    /**
     * FULL_TIME — fixed monthly base salary.
     *
     * Hourly rate = base_salary ÷ monthly_work_hours. Eligible for overtime,
     * holiday pay, night differential, commute allowance, and deductions.
     * Multipliers default to the settings (overtime_rate, legal_holiday_rate,
     * night_rate) unless overridden in $i.
     *
     * @param array{
     *     base_salary: int|float,
     *     monthly_work_hours?: int|float,
     *     overtime_hours?: int|float,
     *     holiday_hours?: int|float,
     *     night_hours?: int|float,
     *     commute_cost?: int|float,
     *     deductions?: int|float,
     *     overtime_multiplier?: float,
     *     holiday_multiplier?: float,
     *     night_multiplier?: float,
     * } $i
     * @return array
     */
    public function computeFullTime(array $i): array
    {
        $baseSalary       = (float) ($i['base_salary'] ?? 0);
        $monthlyWorkHours = (float) ($i['monthly_work_hours'] ?? self::DEFAULT_MONTHLY_WORK_HOURS);
        $monthlyWorkHours = $monthlyWorkHours > 0 ? $monthlyWorkHours : self::DEFAULT_MONTHLY_WORK_HOURS;

        $overtimeHours = (float) ($i['overtime_hours'] ?? 0);
        $holidayHours  = (float) ($i['holiday_hours'] ?? 0);
        $nightHours    = (float) ($i['night_hours'] ?? 0);
        $commuteCost   = (float) ($i['commute_cost'] ?? 0);
        $deductions    = (float) ($i['deductions'] ?? 0);

        $otMult      = $this->overtimeMultiplier($i);
        $holMult     = $this->holidayMultiplier($i);
        $nightPremium = $this->nightPremium($i);

        $hourlyRate = $baseSalary / $monthlyWorkHours;

        $overtimePay  = $overtimeHours * $hourlyRate * $otMult;
        $holidayPay   = $holidayHours * $hourlyRate * $holMult;
        $nightDiffPay = $nightHours * $hourlyRate * $nightPremium;

        $gross = $baseSalary + $overtimePay + $holidayPay + $nightDiffPay + $commuteCost - $deductions;

        return self::round([
            'type'               => 'FULL_TIME',
            'base_salary'        => $baseSalary,
            'monthly_work_hours' => $monthlyWorkHours,
            'hourly_rate'        => $hourlyRate,
            'overtime_hours'     => $overtimeHours,
            'overtime_pay'       => $overtimePay,
            'holiday_hours'      => $holidayHours,
            'holiday_pay'        => $holidayPay,
            'night_hours'        => $nightHours,
            'night_differential' => $nightDiffPay,
            'commute_cost'       => $commuteCost,
            'deductions'         => $deductions,
            'gross_salary'       => $gross,
        ]);
    }

    /**
     * PART_TIME — paid on actual worked hours.
     *
     * Regular pay = hourly_rate × worked_hours. Overtime and break deductions
     * still apply; commute allowance only if explicitly provided. Multipliers
     * default to the settings unless overridden in $i.
     *
     * @param array{
     *     hourly_rate: int|float,
     *     worked_hours?: int|float,
     *     overtime_hours?: int|float,
     *     holiday_hours?: int|float,
     *     break_deduction?: int|float,
     *     commute_cost?: int|float,
     *     overtime_multiplier?: float,
     *     holiday_multiplier?: float,
     * } $i
     * @return array
     */
    public function computePartTime(array $i): array
    {
        $hourlyRate    = (float) ($i['hourly_rate'] ?? 0);
        $workedHours   = (float) ($i['worked_hours'] ?? 0);
        $overtimeHours = (float) ($i['overtime_hours'] ?? 0);
        $holidayHours  = (float) ($i['holiday_hours'] ?? 0);
        $breakDeduct   = (float) ($i['break_deduction'] ?? 0);
        $commuteCost   = (float) ($i['commute_cost'] ?? 0);

        $otMult  = $this->overtimeMultiplier($i);
        $holMult = $this->holidayMultiplier($i);

        $regularPay  = $workedHours * $hourlyRate;
        $overtimePay = $overtimeHours * $hourlyRate * $otMult;
        $holidayPay  = $holidayHours * $hourlyRate * $holMult;

        $gross = $regularPay + $overtimePay + $holidayPay + $commuteCost - $breakDeduct;

        return self::round([
            'type'            => 'PART_TIME',
            'hourly_rate'     => $hourlyRate,
            'worked_hours'    => $workedHours,
            'regular_pay'     => $regularPay,
            'overtime_hours'  => $overtimeHours,
            'overtime_pay'    => $overtimePay,
            'holiday_hours'   => $holidayHours,
            'holiday_pay'     => $holidayPay,
            'break_deduction' => $breakDeduct,
            'commute_cost'    => $commuteCost,
            'gross_salary'    => $gross,
        ]);
    }

    /**
     * CONTRACT — fixed-term / project-based agreement.
     *
     * Gross = monthly_contract_salary + project_allowance + transportation
     * + other_allowances + overtime − deductions.
     *
     * @param array{
     *     monthly_contract_salary: int|float,
     *     project_allowance?: int|float,
     *     transportation?: int|float,
     *     other_allowances?: int|float,
     *     overtime_pay?: int|float,
     *     deductions?: int|float,
     * } $i
     * @return array
     */
    public function computeContract(array $i): array
    {
        $monthlySalary    = (float) ($i['monthly_contract_salary'] ?? 0);
        $projectAllowance = (float) ($i['project_allowance'] ?? 0);
        $transportation   = (float) ($i['transportation'] ?? 0);
        $otherAllowances  = (float) ($i['other_allowances'] ?? 0);
        $overtimePay      = (float) ($i['overtime_pay'] ?? 0);
        $deductions       = (float) ($i['deductions'] ?? 0);

        $gross = $monthlySalary + $projectAllowance + $transportation
            + $otherAllowances + $overtimePay - $deductions;

        return self::round([
            'type'                    => 'CONTRACT',
            'monthly_contract_salary' => $monthlySalary,
            'project_allowance'       => $projectAllowance,
            'transportation'          => $transportation,
            'other_allowances'        => $otherAllowances,
            'overtime_pay'            => $overtimePay,
            'deductions'              => $deductions,
            'gross_salary'            => $gross,
        ]);
    }

    /**
     * DAILY — per-day / per-shift labor.
     *
     * Regular pay = daily_rate × worked_days. Holiday days are paid at the
     * holiday multiplier; overtime (in hours) uses an explicit hourly rate.
     * Multipliers default to the settings unless overridden in $i.
     *
     * @param array{
     *     daily_rate: int|float,
     *     worked_days?: int|float,
     *     holiday_days?: int|float,
     *     overtime_hours?: int|float,
     *     overtime_hourly_rate?: int|float,
     *     transportation?: int|float,
     *     deductions?: int|float,
     *     holiday_multiplier?: float,
     *     overtime_multiplier?: float,
     * } $i
     * @return array
     */
    public function computeDaily(array $i): array
    {
        $dailyRate    = (float) ($i['daily_rate'] ?? 0);
        $workedDays   = (float) ($i['worked_days'] ?? 0);
        $holidayDays  = (float) ($i['holiday_days'] ?? 0);
        $otHours      = (float) ($i['overtime_hours'] ?? 0);
        $otHourlyRate = (float) ($i['overtime_hourly_rate'] ?? 0);
        $transport    = (float) ($i['transportation'] ?? 0);
        $deductions   = (float) ($i['deductions'] ?? 0);

        $holMult = $this->holidayMultiplier($i);
        $otMult  = $this->overtimeMultiplier($i);

        $regularPay  = $workedDays * $dailyRate;
        $holidayPay  = $holidayDays * $dailyRate * $holMult;
        $overtimePay = $otHours * $otHourlyRate * $otMult;

        $gross = $regularPay + $holidayPay + $overtimePay + $transport - $deductions;

        return self::round([
            'type'           => 'DAILY',
            'daily_rate'     => $dailyRate,
            'worked_days'    => $workedDays,
            'regular_pay'    => $regularPay,
            'holiday_days'   => $holidayDays,
            'holiday_pay'    => $holidayPay,
            'overtime_hours' => $otHours,
            'overtime_pay'   => $overtimePay,
            'transportation' => $transport,
            'deductions'     => $deductions,
            'gross_salary'   => $gross,
        ]);
    }

    /**
     * Round every numeric value in a breakdown to 2 decimals.
     */
    private static function round(array $breakdown): array
    {
        foreach ($breakdown as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $breakdown[$key] = round((float) $value, 2);
            }
        }

        return $breakdown;
    }
}
