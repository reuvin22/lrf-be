<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetService;
use App\Services\SalaryRecordService;
use Illuminate\Console\Command;

class SheetsSetup extends Command
{
    protected $signature   = 'sheets:setup {sheet? : Sheet name to set up (omit to set up all sheets)} {--id= : Use an existing spreadsheet ID (only valid when a single sheet is specified)}';
    protected $description = 'Create Google Spreadsheets, write headers, dropdowns, and conditional colours. Omit the sheet argument to set up all sheets at once.';

    // Global colour map — one hex per enum value, shared across all sheets.
    private const COLORS = [
        // ── Active / positive ────────────────────────────────────────────────
        'ACTIVE'       => '#1E8B4C',
        'COMPLETED'    => '#1E8B4C',
        'SITE'         => '#1E8B4C',

        // ── Inactive / negative ──────────────────────────────────────────────
        'INACTIVE'     => '#C0392B',
        'RESIGNED'     => '#C0392B',
        'TERMINATED'   => '#C0392B',
        'ERROR'        => '#C0392B',

        // ── In-progress / working ────────────────────────────────────────────
        'WORKING'      => '#2471A3',
        'PROCESSING'   => '#2471A3',
        'IN_PROGRESS'  => '#2471A3',
        'OFFICE'       => '#2471A3',
        'Web'          => '#2471A3',

        // ── Pending / preparing ──────────────────────────────────────────────
        'PENDING'      => '#B7950B',
        'PREPARING'    => '#CA6F1E',
        'TRAVEL'       => '#CA6F1E',
        'PART_TIME'    => '#CA6F1E',

        // ── Not started / end of day ─────────────────────────────────────────
        'NOT_STARTED'  => '#717D7E',
        'END_OF_DAY'   => '#5D6D7E',

        // ── Employment & roles ───────────────────────────────────────────────
        'FULL_TIME'    => '#154360',
        'CONTRACT'     => '#6C3483',
        'GENERAL'      => '#5D6D7E',
        'ADMIN'        => '#154360',
        'ACCOUNTING'   => '#1D6A39',

        // ── Contract types ───────────────────────────────────────────────────
        'QUASI_DELEGATION' => '#117A65',
        'FIXED_PRICE'      => '#1A5276',

        // ── Salary types ─────────────────────────────────────────────────────
        'HOURLY_BASED'      => '#0E6655',
        'FIXED_PRICE_BASED' => '#1A5276',

        // ── Pay basis (Salary Record) ────────────────────────────────────────
        'HOURLY'            => '#0E6655',
        'FIXED'             => '#1A5276',

        // ── Rate / target types ──────────────────────────────────────────────
        'EMPLOYEE_COST'                 => '#154360',
        'SUBCONTRACTOR_CONTRACT'        => '#CA6F1E',
        'SUBCONTRACTOR_WORKER_CONTRACT' => '#6C3483',
        'EMPLOYEE'                      => '#154360',
        'SUBCONTRACTOR'                 => '#CA6F1E',
        'SUBCONTRACTOR_WORKER'          => '#6C3483',

        // ── Upload source ────────────────────────────────────────────────────
        'LINE'         => '#06C755',

        // ── Segment type ─────────────────────────────────────────────────────
        'TRAVEL_SEG'   => '#CA6F1E', // alias — 'TRAVEL' already covers this
    ];

    // Registry — add one entry per controller that syncs to Sheets.
    // All sheets share the single GOOGLE_SHEETS_ID spreadsheet from .env.
    // enums    → column_name => [allowed values] for any enum column.
    private const REGISTRY = [
        'employees' => [
            'title'   => 'LRF Employees',
            'tab'     => 'Employees',
            // salary_type is kept LAST so adding it didn't shift the pre-existing
            // columns (status/joined_date) and misalign already-populated rows.
            'headers' => [
                'employee_id', 'employee_code', 'name', 'name_kana', 'line_user_id',
                'email', 'employment_type', 'role', 'base_salary', 'monthly_work_hours',
                'cost_rate', 'commute_cost_monthly', 'joined_date', 'status', 'salary_type',
            ],
            'enums' => [
                'employment_type' => ['FULL_TIME', 'PART_TIME', 'CONTRACT'],
                'salary_type'     => ['HOURLY_BASED', 'FIXED_PRICE_BASED'],
                'role'            => ['GENERAL', 'ADMIN', 'ACCOUNTING'],
                'status'          => ['ACTIVE', 'RESIGNED', 'PENDING'],
            ],
            'dates' => ['joined_date'],
        ],
        'attendance' => [
            'title'   => 'LRF Attendance',
            'tab'     => 'Attendance',
            'headers' => [
                'attendance_id', 'employee_id', 'work_date', 'status', 'total_work_minutes', 'overtime_minutes',
            ],
            'enums' => [
                'status' => ['WORKING', 'END_OF_DAY', 'NOT_STARTED'],
            ],
            'dates' => ['work_date'],
        ],
        'attendance_employees' => [
            'title'   => 'LRF Attendance Employees',
            'tab'     => 'AttendanceEmployees',
            'headers' => [
                'uuid', 'attendance_id', 'employee_id',
            ],
            'enums' => [],
            'dates' => [],
        ],
        'attendance_sub_segments' => [
            'title'   => 'LRF Attendance Sub Segments',
            'tab'     => 'AttendanceSubSegments',
            'headers' => [
                'uuid', 'attendance_id', 'segment_id', 'company_id', 'company_name',
                'employee_id', 'worker_id', 'worker_name', 'site_id', 'site_name',
                'contract_type', 'start_time', 'end_time',
            ],
            'enums' => [
                'contract_type' => ['QUASI_DELEGATION', 'FIXED_PRICE'],
            ],
            'dates' => [],
        ],
        'segments' => [
            'title'   => 'LRF Segments',
            'tab'     => 'Segments',
            'headers' => [
                'segment_id', 'attendance_id', 'employee_id', 'site_id',
                'segment_type', 'site_name', 'start_time', 'end_time', 'type',
            ],
            'enums' => [
                'segment_type' => ['TRAVEL', 'SITE', 'OFFICE'],
                'type'         => ['TRAVEL', 'SITE', 'OFFICE'],
            ],
            'dates' => [],
        ],
        'construction_sites' => [
            'title'   => 'LRF Construction Sites',
            'tab'     => 'ConstructionSites',
            'headers' => [
                'site_id', 'site_code', 'site_name', 'client_name', 'contract_type',
                'address', 'status', 'start_date', 'end_date',
                'contract_amount', 'dotto_genka_code',
            ],
            'enums' => [
                'contract_type' => ['QUASI_DELEGATION', 'FIXED_PRICE'],
                'status'        => ['PREPARING', 'IN_PROGRESS', 'COMPLETED'],
            ],
            'dates' => ['start_date', 'end_date'],
        ],
        'site_assignments' => [
            'title'   => 'LRF Site Assignments',
            'tab'     => 'SiteAssignments',
            'headers' => [
                'assignment_id', 'worker_id', 'worker_name', 'site_id', 'site_name', 'is_leader', 'start_date', 'end_date',
            ],
            'enums' => [
                'is_leader' => ['YES', 'NO'],
            ],
            'dates'           => ['start_date', 'end_date'],
            // worker_id / site_id are auto-filled from the chosen worker_name /
            // site_name and locked by the Apps Script (handleSiteAssignmentsEdit /
            // setupSiteAssignmentsSheet). worker_name is intentionally NOT set
            // here: it's a TAGGED dropdown of Employees + SubContractor Workers
            // ([Employee]/[Worker] prefixes) that only the Apps Script can build,
            // so this backend dropdown would clobber it. Only site_name is set.
            'range_dropdowns' => [
                'site_name' => 'ConstructionSites!$C$2:$C$10000', // site_name col (C after site_id added)
            ],
        ],
        'site_sub_contractors' => [
            'title'   => 'LRF Site Sub Contractors',
            'tab'     => 'SiteSubContractors',
            // site_name sits beside site_id, subcontractor_name beside subcontractor_id.
            'headers' => [
                'uuid', 'site_id', 'site_name', 'subcontractor_id', 'subcontractor_name', 'contract_type',
            ],
            'enums' => [
                'contract_type' => ['QUASI_DELEGATION', 'FIXED_PRICE'],
            ],
            'dates' => [],
            // site_id / subcontractor_id are auto-filled from the chosen names and
            // locked by the Apps Script (handleSiteSubContractorsEdit /
            // setupSiteSubContractorsSheet); only the name columns get dropdowns.
            'range_dropdowns' => [
                'site_name'          => 'ConstructionSites!$C$2:$C$10000', // site_name col (C)
                'subcontractor_name' => 'SubContractors!$B$2:$B$10000',    // company_name col (B)
            ],
        ],
        'sub_contractors' => [
            'title'   => 'LRF Sub Contractors',
            'tab'     => 'SubContractors',
            'headers' => [
                'subcontractor_id', 'company_name', 'contact_person', 'contact_phone', 'status',
            ],
            'enums' => [
                'status' => ['ACTIVE', 'TERMINATED'],
            ],
            'dates' => [],
        ],
        'sub_contractor_workers' => [
            'title'   => 'LRF Sub Contractor Workers',
            'tab'     => 'SubContractorWorkers',
            'headers' => [
                'worker_id', 'subcontractor_id', 'subcontractor_name', 'name', 'name_kana', 'status',
            ],
            'enums' => [
                'status' => ['ACTIVE', 'INACTIVE'],
            ],
            'dates' => [],
            // subcontractor_id is auto-filled from the chosen subcontractor_name
            // and locked by the Apps Script (handleSubContractorWorkersEdit /
            // setupSubContractorWorkersSheet); only subcontractor_name gets a dropdown.
            'range_dropdowns' => [
                'subcontractor_name' => 'SubContractors!$B$2:$B$10000', // company_name col (B)
            ],
        ],
        'sub_contractor_reports' => [
            'title'   => 'LRF Sub Contractor Reports',
            'tab'     => 'SubContractorReports',
            'headers' => [
                'uuid', 'attendance_id', 'employee_id', 'worker_id', 'worker_name',
                'contract_type', 'company_name', 'site_id', 'start_time', 'end_time',
            ],
            'enums' => [
                'contract_type' => ['QUASI_DELEGATION', 'FIXED_PRICE'],
            ],
            'dates' => [],
        ],
        'transportation_expenses' => [
            'title'   => 'LRF Transportation Expenses',
            'tab'     => 'TransportationExpenses',
            'headers' => [
                'expense_id', 'attendance_id', 'employee_id', 'amount', 'route', 'site_id',
            ],
            'enums' => [],
            'dates' => [],
        ],
        'rates' => [
            'title'   => 'LRF Rates',
            'tab'     => 'Rates',
            'headers' => [
                'rate_id', 'rate_type', 'target_type', 'target_id', 'target_name',
                'site_id', 'site_name', 'unit_price', 'effective_from', 'effective_to',
            ],
            'enums' => [
                'rate_type'   => ['EMPLOYEE_COST', 'SUBCONTRACTOR_CONTRACT', 'SUBCONTRACTOR_WORKER_CONTRACT'],
                'target_type' => ['EMPLOYEE', 'SUBCONTRACTOR', 'SUBCONTRACTOR_WORKER'],
            ],
            // target_name / site_name are the editable dropdowns; target_id /
            // site_id are auto-filled from the chosen name and locked. Because
            // target_name's source tab depends on target_type (per row), these
            // dropdowns + the id lookups + column locking are handled by the
            // onEdit Apps Script (handleRatesEdit / setupRatesSheet), not here.
            'dates' => ['effective_from', 'effective_to'],
        ],
        'ocr_uploads' => [
            'title'   => 'LRF OCR Uploads',
            'tab'     => 'OcrUploads',
            'headers' => [
                'upload_id', 'uploaded_by', 'category_id', 'site_id', 'subcontractor_id',
                'attendance_id', 'upload_source', 'status', 'image_path',
                'ocr_result_amount', 'ocr_result_date', 'ocr_result_raw',
                'confirmed', 'confirmed_by', 'confirmed_at', 'note',
                'uploaded_at', 'processed_at',
            ],
            'enums' => [
                'upload_source' => ['LINE', 'Web'],
                'status'        => ['PENDING', 'PROCESSING', 'COMPLETED', 'ERROR'],
            ],
            'dates' => ['ocr_result_date', 'confirmed_at', 'uploaded_at', 'processed_at'],
        ],
        'ocr_upload_categories' => [
            'title'   => 'LRF OCR Upload Categories',
            'tab'     => 'OcrUploadCategories',
            'headers' => [
                'category_id', 'category_name', 'description', 'status',
            ],
            'enums' => [
                'status' => ['ACTIVE', 'INACTIVE'],
            ],
            'dates' => [],
        ],
        'company_calendar' => [
            'title'   => 'LRF Company Calendar',
            'tab'     => 'CompanyCalendar',
            'headers' => [
                'calendar_id', 'date', 'day_type', 'note',
            ],
            'enums' => [],
            'dates' => ['date'],
        ],
        'day_types' => [
            'title'   => 'LRF Day Types',
            'tab'     => 'DayTypes',
            'headers' => [
                'id', 'value', 'description', 'overtime_multiplier',
            ],
            'enums'    => [],
            'dates'    => [],
            'defaults' => [
                ['', 'workday',          'Weekday',                              '1.5'],
                ['', 'holiday',          'Prescribed holiday (e.g., Saturday)',  '1.25'],
                ['', 'legal_holiday',    'Statutory holiday (Sunday)',            '1.35'],
                ['', 'national_holiday', 'National holiday',                     '1.35'],
            ],
        ],
        'site_expense_categories' => [
            'title'   => 'LRF Site Expense Categories',
            'tab'     => 'SiteExpenseCategories',
            'headers' => [
                'category_id', 'category_name', 'description', 'status',
            ],
            'enums' => [
                'status' => ['ACTIVE', 'INACTIVE'],
            ],
            'dates' => [],
        ],
        'system_settings' => [
            'title'   => 'LRF System Settings',
            'tab'     => 'SystemSettings',
            'headers' => [
                'system_settings_id', 'key', 'value', 'description',
            ],
            'enums'    => [],
            'dates'    => [],
            'defaults' => [
                ['', 'break_deduction_tier1_threshold', '4',    'Upper limit for no break deduction (hours)'],
                ['', 'break_deduction_tier1_minutes',   '0',    'Up to 4h: no deduction'],
                ['', 'break_deduction_tier2_threshold', '8',    'Upper limit for 45-min deduction (hours)'],
                ['', 'break_deduction_tier2_minutes',   '45',   'Over 4h up to 8h: 45-min deduction'],
                ['', 'break_deduction_tier3_minutes',   '90',   'Over 8h: 90-min deduction'],
                ['', 'standard_work_hours',             '8',    'Prescribed daily work hours (h/day)'],
                ['', 'overtime_rate',                   '1.25', 'Standard overtime multiplier'],
                ['', 'night_rate',                      '1.50', 'Late-night multiplier (after 22:00, HQ office work only)'],
                ['', 'legal_holiday_rate',              '1.35', 'Statutory holiday multiplier (provisional)'],
                ['', 'scheduled_holiday_rate',          '1.25', 'Prescribed holiday multiplier (provisional)'],
                ['', 'night_overtime_rate',             '1.50', 'Late-night + overtime multiplier (provisional)'],
                ['', 'subcontractor_standard_hours',    '8',    'Standard hours for quasi-delegation contracts (h)'],
                ['', 'subcontractor_overtime_rate',     '1.25', 'Overtime multiplier for quasi-delegation contracts'],
                ['', 'subcontractor_default_start',     '09:00','Default start time for quasi-delegation contracts'],
                ['', 'work_start_earliest',             '09:00','Earliest allowed work start time'],
                ['', 'closing_day',                     '10',   'Monthly closing day (day of the following month)'],
            ],
        ],
        'salary_record' => [
            'title'   => 'LRF Salary Record',
            // Reuse the service's tab title + headers so the provisioned columns
            // can never drift from what SalaryRecordService actually writes.
            'tab'     => SalaryRecordService::TAB_TITLE,
            'headers' => SalaryRecordService::HEADERS,
            // Monthly payroll summary — one row per employee per period (YYYY-MM).
            // Every month lives in this single tab; the basic filter lets users
            // slice by period/employee. Rows are generated by SalaryRecordService
            // (the maths lives in SalaryCalculator); this entry only provisions the
            // tab's headers, enum chips, and filter.
            'enums' => [
                'employment_type' => ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'DAILY'],
                'salary_type'     => ['HOURLY_BASED', 'FIXED_PRICE_BASED'],
                'pay_basis'       => ['HOURLY', 'FIXED'],
            ],
            // period is YYYY-MM and generated_at is a timestamp — neither is a
            // yyyy-mm-dd date, so no date picker is applied.
            'dates'  => [],
            // Add a basic filter across all columns so the all-month tab is filterable.
            'filter' => true,
        ],
    ];

    public function handle(GoogleSheetService $sheet): int
    {
        $arg = $this->argument('sheet');

        if ($arg === null) {
            $failed      = [];
            $setupResults = [];

            // Pass 1 — create/configure all tabs (headers, enums, dates, defaults)
            foreach (array_keys(self::REGISTRY) as $key) {
                $this->newLine();
                $this->line("━━━ <comment>{$key}</comment> ━━━");

                $spreadsheetId = $this->setupSheet($sheet, $key, $this->option('id'));

                if ($spreadsheetId === null) {
                    $failed[] = $key;
                    continue;
                }

                $setupResults[$key] = $spreadsheetId;
            }

            // Pass 2 — apply range dropdowns now that all source tabs exist
            if (!empty($setupResults)) {
                $this->newLine();
                $this->line('━━━ <comment>range dropdowns</comment> ━━━');
                foreach ($setupResults as $key => $spreadsheetId) {
                    $this->applyRangeDropdownsForSheet($sheet, $key, $spreadsheetId);
                }
            }

            $this->newLine();

            if (empty($failed)) {
                $this->line('<fg=green>✓ All sheets are ready.</>');
            } else {
                $this->error('Failed: ' . implode(', ', $failed));
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $key = strtolower($arg);

        if (!array_key_exists($key, self::REGISTRY)) {
            $this->error("Unknown sheet \"{$key}\". Available: " . implode(', ', array_keys(self::REGISTRY)));
            return self::FAILURE;
        }

        $spreadsheetId = $this->setupSheet($sheet, $key, $this->option('id'));

        if ($spreadsheetId === null) {
            return self::FAILURE;
        }

        $this->applyRangeDropdownsForSheet($sheet, $key, $spreadsheetId);

        return self::SUCCESS;
    }

    private function applyRangeDropdownsForSheet(GoogleSheetService $sheet, string $key, string $spreadsheetId): void
    {
        $config = self::REGISTRY[$key];

        if (empty($config['range_dropdowns'])) {
            return;
        }

        $headerIndex = array_flip($config['headers']);

        foreach ($config['range_dropdowns'] as $column => $sourceRange) {
            if (!isset($headerIndex[$column])) {
                continue;
            }

            $this->info("  [{$key}] Range dropdown \"{$column}\" → {$sourceRange}...");
            $result = $sheet->applyRangeDropdown($spreadsheetId, $config['tab'], $headerIndex[$column], $sourceRange);

            if (!$result) {
                $sourceTab = explode('!', $sourceRange)[0];
                $this->warn("  Skipped \"{$column}\" — tab \"{$sourceTab}\" not found in this spreadsheet.");
            }
        }
    }

    // Returns the resolved spreadsheet ID on success, null on failure.
    private function setupSheet(GoogleSheetService $sheet, string $key, ?string $idOverride): ?string
    {
        $config = self::REGISTRY[$key];
    
        $resolvedId = $idOverride ?: env('GOOGLE_SHEETS_ID');
    
        if ($resolvedId) {
            $spreadsheetId = $resolvedId;
            $this->info("Using existing spreadsheet \"{$spreadsheetId}\"...");
    
            if (!$sheet->tabExists($spreadsheetId, $config['tab'])) {
                $this->info("Tab \"{$config['tab']}\" not found — creating it...");
                $sheet->addSheet($spreadsheetId, $config['tab']);
            }
        } else {
            $this->info("Creating spreadsheet \"{$config['title']}\"...");
            $spreadsheetId = $sheet->createSpreadsheet($config['title'], $config['tab']);
        }
    
        // 1. HEADERS FIRST
        $this->info("Writing headers to tab \"{$config['tab']}\"...");
        $sheet->setHeaders($spreadsheetId, $config['tab'], $config['headers']);
    
        // 2. ENUM DROPDOWNS (IMPORTANT: BEFORE INSERTING DATA)
        if (!empty($config['enums'])) {
            $headerIndex = array_flip($config['headers']);
    
            foreach ($config['enums'] as $column => $values) {
                if (!isset($headerIndex[$column])) {
                    continue;
                }
    
                $colIndex = $headerIndex[$column];
                $colorMap = array_intersect_key(self::COLORS, array_flip($values));
    
                $this->info("Applying dropdown + colours to column \"{$column}\"...");
    
                // IMPORTANT: apply to full usable range (not just empty cells)
                $sheet->applyDropdownWithColors(
                    $spreadsheetId,
                    $config['tab'],
                    $colIndex,
                    $values,
                    $colorMap
                );
            }
        }
    
        // 3. DATE PICKERS (also BEFORE data)
        if (!empty($config['dates'])) {
            $headerIndex = array_flip($config['headers']);
            $dateColIndexes = array_values(array_filter(
                array_map(fn ($col) => $headerIndex[$col] ?? null, $config['dates']),
                fn ($idx) => $idx !== null
            ));
    
            $this->info("Applying date picker...");
            $sheet->applyDatePicker($spreadsheetId, $config['tab'], $dateColIndexes);
        }
    
        // 4. RANGE DROPDOWNS (IMPORTANT FIX)
        if (!empty($config['range_dropdowns'])) {
            $headerIndex = array_flip($config['headers']);
    
            foreach ($config['range_dropdowns'] as $column => $sourceRange) {
                if (!isset($headerIndex[$column])) {
                    continue;
                }
    
                $this->info("Applying range dropdown \"{$column}\" → {$sourceRange}...");
    
                $sheet->applyRangeDropdown(
                    $spreadsheetId,
                    $config['tab'],
                    $headerIndex[$column],
                    $sourceRange
                );
            }
        }
    
        // 4b. BASIC FILTER (so the all-month data in one tab stays filterable)
        if (!empty($config['filter'])) {
            $this->info("Applying basic filter...");
            $sheet->applyBasicFilter($spreadsheetId, $config['tab'], count($config['headers']));
        }

        // 5. NOW INSERT DEFAULT DATA (IMPORTANT FIX)
        if (!empty($config['defaults'])) {
            $existing = $sheet->read($spreadsheetId, $config['tab']);
            $hasData  = count($existing) > 1;
    
            if ($hasData) {
                $this->line("  Skipping defaults — tab already has data.");
            } else {
                $this->info("Seeding default rows...");
    
                $sheet->append(
                    $spreadsheetId,
                    $config['tab'],
                    $config['defaults']
                );
            }
        }
    
        $this->line("  <fg=green>✓</> {$config['title']} ready.");
    
        if (!env('GOOGLE_SHEETS_ID')) {
            $this->newLine();
            $this->line("  Add to .env:");
            $this->line("  GOOGLE_SHEETS_ID={$spreadsheetId}");
        }
    
        return $spreadsheetId;
    }
}