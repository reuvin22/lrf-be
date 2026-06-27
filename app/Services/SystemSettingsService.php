<?php

namespace App\Services;

/**
 * SystemSettingsService
 *
 * Reads the SystemSettings tab (the data behind the settings route) into a
 * key => value map so business rules — overtime/holiday/night multipliers,
 * break-deduction tiers, closing day, etc. — can be changed in the sheet
 * without touching code. The map is cached for the lifetime of the instance
 * so a single request does not re-hit the Sheets API per lookup.
 */
class SystemSettingsService
{
    private const TAB = 'SystemSettings';

    private ?array $cache = null;

    public function __construct(private GoogleSheetService $sheet)
    {
    }

    private function spreadsheetId(): string
    {
        return (string) config('services.google_sheets.spreadsheet_id');
    }

    /**
     * All settings as a key => value map.
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), self::TAB);
        $map  = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            if ($key !== null && $key !== '') {
                $map[$key] = $row['value'] ?? null;
            }
        }

        return $this->cache = $map;
    }

    /**
     * Raw value for a key, or $default when missing.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Drop the in-memory cache (e.g. after settings are updated).
     */
    public function flush(): void
    {
        $this->cache = null;
    }
}
