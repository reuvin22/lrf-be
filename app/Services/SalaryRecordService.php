<?php

namespace App\Services;

use App\Helpers\SalaryCalculator;
use Carbon\Carbon;

/**
 * SalaryRecordService
 *
 * Closing-day payroll. For a work month it reads attendance, overtime and
 * transportation from the sheets, computes each active employee's salary via the
 * settings-driven {@see SalaryCalculator}, and writes a per-employee summary to
 * the "Salary Record" tab.
 *
 * Period model: a work month is a full calendar month (YYYY-MM). The closing day
 * (SystemSettings `closing_day`) is the day of the FOLLOWING month on which the
 * run fires, so closing on that day settles the previous calendar month.
 */
class SalaryRecordService
{
    public const TAB_TITLE = 'Salary Record';

    /** A1 range prefix — the tab name has a space, so it must be quoted. */
    private const RANGE = "'Salary Record'";

    public const HEADERS = [
        'period', 'employee_id', 'employee_code', 'name', 'employment_type',
        'salary_type', 'pay_basis', 'worked_days', 'total_work_hours',
        'regular_hours', 'overtime_hours', 'holiday_hours', 'hourly_rate',
        'base_salary', 'regular_pay', 'overtime_pay', 'holiday_pay',
        'commute_allowance', 'transportation_total', 'gross_salary', 'generated_at',
    ];

    public function __construct(
        private GoogleSheetService $sheet,
        private SystemSettingsService $settings,
    ) {
    }

    private function spreadsheetId(): string
    {
        return (string) config('services.google_sheets.spreadsheet_id');
    }

    /**
     * Configured monthly closing day (defaults to 10).
     */
    public function closingDay(): int
    {
        return $this->settings->int('closing_day', 10);
    }

    /**
     * Build and persist the Salary Record summary for a work month (YYYY-MM).
     *
     * @return array{period:string,period_start:string,period_end:string,records:array,written:int}
     */
    public function generateForMonth(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        // Rates come from the SystemSettings sheet via the calculator.
        $calc = SalaryCalculator::fromSettings($this->settings);

        $id          = $this->spreadsheetId();
        $employees   = $this->sheet->getRowsAsAssoc($id, 'Employees');
        $attendances = $this->sheet->getRowsAsAssoc($id, 'Attendance');
        $expenses    = $this->sheet->getRowsAsAssoc($id, 'TransportationExpenses');

        // Attendance rows within the work month.
        $periodAttendance = array_filter(
            $attendances,
            fn ($a) => str_starts_with((string) ($a['work_date'] ?? ''), $month)
        );

        // Transportation totals keyed by attendance_id (bounds expenses to the period).
        $expenseByAttendance = [];
        foreach ($expenses as $e) {
            $aid = (string) ($e['attendance_id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $expenseByAttendance[$aid] = ($expenseByAttendance[$aid] ?? 0) + (float) ($e['amount'] ?? 0);
        }

        $records = [];

        foreach ($employees as $emp) {
            $status = strtoupper((string) ($emp['status'] ?? ''));
            if ($status === 'RESIGNED' || $status === 'PENDING') {
                continue; // only settle active staff
            }

            $empId = (string) ($emp['employee_id'] ?? '');
            if ($empId === '') {
                continue;
            }

            $rows = array_filter(
                $periodAttendance,
                fn ($a) => (string) ($a['employee_id'] ?? '') === $empId
            );

            $totalMinutes    = 0;
            $overtimeMinutes = 0;
            $transport       = 0.0;
            $dates           = [];

            foreach ($rows as $a) {
                $totalMinutes    += (int) ($a['total_work_minutes'] ?? 0);
                $overtimeMinutes += (int) ($a['overtime_minutes'] ?? 0);

                $date = (string) ($a['work_date'] ?? '');
                if ($date !== '') {
                    $dates[substr($date, 0, 10)] = true;
                }

                $aid = (string) ($a['attendance_id'] ?? '');
                if ($aid !== '' && isset($expenseByAttendance[$aid])) {
                    $transport += $expenseByAttendance[$aid];
                }
            }

            $aggregate = [
                'worked_days'          => count($dates),
                'total_work_hours'     => round($totalMinutes / 60, 2),
                'overtime_hours'       => round($overtimeMinutes / 60, 2),
                'holiday_hours'        => 0, // per-day holiday detection (CompanyCalendar) is a future hook
                'transportation_total' => $transport,
            ];

            $records[] = $calc->computeMonthlyRecord($emp, $aggregate);
        }

        $written = $this->writeSheet($records, $month);

        return [
            'period'       => $month,
            'period_start' => $start->toDateString(),
            'period_end'   => $end->toDateString(),
            'records'      => $records,
            'written'      => $written,
        ];
    }

    /**
     * Write this period's rows into the single all-month Salary Record tab.
     *
     * Every month lives in one tab (distinguished by the `period` column) so the
     * data is filterable in place. Rows from OTHER months are preserved; only the
     * rows for `$month` are replaced, so re-running a close is idempotent and never
     * duplicates or wipes prior periods. Returns the number of rows written for
     * this month.
     */
    private function writeSheet(array $records, string $month): int
    {
        $id  = $this->spreadsheetId();
        $now = now()->toDateTimeString();

        if (!$this->sheet->tabExists($id, self::TAB_TITLE)) {
            $this->sheet->addSheet($id, self::TAB_TITLE);
        }

        // Keep every row from other months; drop this month's so it can be rebuilt.
        $existing = $this->sheet->getRowsAsAssoc($id, self::TAB_TITLE);
        $retained = array_values(array_filter(
            $existing,
            fn ($row) => (string) ($row['period'] ?? '') !== $month
        ));

        // Fresh rows for this month (stamped with period + generation time).
        $fresh = array_map(function ($record) use ($month, $now) {
            $record['period']       = $month;
            $record['generated_at'] = $now;

            return $record;
        }, $records);

        // Merge and order newest period first, then by employee_id within a period.
        $all = array_merge($retained, $fresh);
        usort($all, function ($a, $b) {
            $periodCmp = strcmp((string) ($b['period'] ?? ''), (string) ($a['period'] ?? ''));

            return $periodCmp !== 0
                ? $periodCmp
                : strcmp((string) ($a['employee_id'] ?? ''), (string) ($b['employee_id'] ?? ''));
        });

        $rows = array_map(
            fn ($record) => array_map(fn ($header) => $record[$header] ?? '', self::HEADERS),
            $all
        );

        // Clear values only (keeps the basic filter + enum chip formatting), then
        // rewrite headers and the full merged data set.
        $this->sheet->clear($id, self::RANGE . '!A:Z');
        $this->sheet->update($id, self::RANGE . '!A1', [self::HEADERS]);

        if (!empty($rows)) {
            $this->sheet->update($id, self::RANGE . '!A2', $rows);
        }

        return count($fresh);
    }
}