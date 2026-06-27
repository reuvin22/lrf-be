# Salary Record — how it's calculated

This note explains how the **Salary Record** sheet is produced. The tab is
generated automatically on the monthly **closing day** and holds one row per
active employee for the settled work month.

- **Logic:** `app/Helpers/SalaryCalculator.php` (PHP) — single source of truth.
- **Orchestration:** `app/Services/SalaryRecordService.php` → endpoint
  `POST /api/v1/salary/close`.
- **Trigger:** `apps-script/SalaryClose.gs` (daily time trigger that detects the
  closing day and calls the endpoint).

---

## When it runs

- The closing day comes from **SystemSettings → `closing_day`** (e.g. `10`).
- It is the day of the *following* month on which payroll closes, so running on
  the closing day settles the **previous calendar month** (the work month).
- The Apps Script checks the date daily; on the closing day it calls the backend.
  You can also run any month manually: `POST /api/v1/salary/close?month=YYYY-MM`.

Only employees with status **ACTIVE** are included (RESIGNED and PENDING are
skipped).

---

## Inputs (all read from the sheets)

| Source tab | Used for |
|---|---|
| `Employees` | base_salary, monthly_work_hours, commute_cost_monthly, employment_type, salary_type, status |
| `Attendance` | per-day `total_work_minutes` and `overtime_minutes` for the work month |
| `TransportationExpenses` | daily reimbursements (matched by `attendance_id`) |
| `SystemSettings` | the multipliers (rates) below |

**Rates (from SystemSettings — change them there, not in code):**

| Setting key | Meaning | Used as |
|---|---|---|
| `overtime_rate` | overtime multiplier (e.g. 1.25) | overtime pay factor |
| `legal_holiday_rate` | holiday multiplier (e.g. 1.35) | holiday pay factor |
| `night_rate` | night total multiplier (e.g. 1.5) | night premium = `night_rate − 1` |

If a rate row is missing, the calculator falls back to 1.25 / 1.35 / 1.5.

---

## Per-employee aggregation (the work month)

```
total_work_hours = Σ total_work_minutes ÷ 60
overtime_hours   = Σ overtime_minutes   ÷ 60
regular_hours    = max(0, total_work_hours − overtime_hours)
worked_days      = count of distinct work_date
transportation_total = Σ TransportationExpenses.amount for that month's attendances
hourly_rate      = base_salary ÷ monthly_work_hours
```

## Pay basis

`pay_basis` is taken from the employee's **`salary_type`**
(`FIXED_PRICE_BASED` → FIXED, `HOURLY_BASED` → HOURLY); if blank, it falls back
to `employment_type` (PART_TIME → HOURLY, otherwise FIXED).

### FIXED (salaried — e.g. FULL_TIME / CONTRACT, or salary_type FIXED_PRICE_BASED)
```
regular_pay       = base_salary                        (the monthly base covers standard hours)
commute_allowance = commute_cost_monthly
overtime_pay      = overtime_hours × hourly_rate × overtime_rate
holiday_pay       = holiday_hours  × hourly_rate × legal_holiday_rate
gross_salary      = regular_pay + overtime_pay + holiday_pay
                    + commute_allowance + transportation_total
```

### HOURLY (e.g. PART_TIME, or salary_type HOURLY_BASED)
```
regular_pay       = regular_hours × hourly_rate
commute_allowance = 0                                  (no fixed monthly allowance)
overtime_pay      = overtime_hours × hourly_rate × overtime_rate
holiday_pay       = holiday_hours  × hourly_rate × legal_holiday_rate
gross_salary      = regular_pay + overtime_pay + holiday_pay + transportation_total
```

> **Worked example (FIXED):** base ¥300,000, monthly_work_hours 160 →
> hourly ¥1,875. 10 overtime hours → 10 × 1,875 × 1.25 = ¥23,437.50. Commute
> ¥15,000, transport ¥3,000 → gross = 300,000 + 23,437.50 + 15,000 + 3,000 =
> **¥341,437.50**.

---

## Columns on the Salary Record sheet

`period` · `employee_id` · `employee_code` · `name` · `employment_type` ·
`salary_type` · `pay_basis` · `worked_days` · `total_work_hours` ·
`regular_hours` · `overtime_hours` · `holiday_hours` · `hourly_rate` ·
`base_salary` · `regular_pay` · `overtime_pay` · `holiday_pay` ·
`commute_allowance` · `transportation_total` · `gross_salary` · `generated_at`

All money values are rounded to 2 decimals. Re-running a month **overwrites**
that sheet (idempotent).

---

## Not yet modelled (extension points)

- **Holiday hours** are currently `0` — attendance rows don't flag holidays.
  Detecting them needs a `CompanyCalendar` lookup (day_type per date).
- **Break deductions** (`break_deduction_*` settings) and **statutory
  deductions** (insurance / pension / tax → net pay) are not applied; the sheet
  shows **gross** salary.
