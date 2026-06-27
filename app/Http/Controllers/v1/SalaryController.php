<?php

namespace App\Http\Controllers\v1;

use App\Helpers\SalaryCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SalaryCalculateRequest;
use App\Services\SalaryRecordService;
use App\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    /**
     * Compute gross salary for a batch of employees.
     *
     * Multipliers come from the SystemSettings sheet; if that sheet can't be
     * reached, the calculator falls back to its built-in defaults (reported via
     * `rates_source`). Each input element is dispatched by `employment_type`.
     */
    public function calculate(SalaryCalculateRequest $request, SystemSettingsService $settings): JsonResponse
    {
        try {
            $calc        = SalaryCalculator::fromSettings($settings);
            $ratesSource = 'system_settings';
        } catch (\Throwable $e) {
            $calc        = new SalaryCalculator([]);
            $ratesSource = 'fallback_defaults';
        }

        $data = array_map(function (array $employee) use ($calc) {
            $breakdown = match ($employee['employment_type']) {
                'FULL_TIME' => $calc->computeFullTime($employee),
                'PART_TIME' => $calc->computePartTime($employee),
                'CONTRACT'  => $calc->computeContract($employee),
                'DAILY'     => $calc->computeDaily($employee),
            };

            // Echo identity fields back alongside the breakdown.
            $identity = [];
            if (isset($employee['employee_id'])) {
                $identity['employee_id'] = $employee['employee_id'];
            }
            if (isset($employee['name'])) {
                $identity['name'] = $employee['name'];
            }

            return $identity + $breakdown;
        }, $request->validated()['employees']);

        return response()->json([
            'success'      => true,
            'rates_source' => $ratesSource,
            'data'         => $data,
        ]);
    }

    /**
     * Closing-day payroll run: gather attendance/overtime/transportation from the
     * sheets, compute each active employee's salary, and write the "Salary Record"
     * tab. Called automatically by the Apps Script time trigger on the closing day
     * (it gates on the date), or manually with ?month=YYYY-MM.
     *
     * Defaults to the previous calendar month (the month the closing day settles).
     */
    public function close(Request $request, SalaryRecordService $service): JsonResponse
    {
        $month = (string) ($request->input('month', $request->query('month', '')));

        if ($month === '') {
            $month = Carbon::now()->subMonthNoOverflow()->format('Y-m');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json([
                'success' => false,
                'message' => "Invalid month \"{$month}\". Expected format YYYY-MM.",
            ], 422);
        }

        try {
            $result = $service->generateForMonth($month);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Salary close failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success'           => true,
            'message'           => "Salary Record generated for {$month}.",
            'period'            => $result['period'],
            'period_start'      => $result['period_start'],
            'period_end'        => $result['period_end'],
            'employees_settled' => $result['written'],
            'data'              => $result['records'],
        ]);
    }
}
