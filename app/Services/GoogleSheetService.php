<?php

namespace App\Services;

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ValueRange;

class GoogleSheetService
{
    private Client $client;
    private Sheets $sheets;

    public function __construct()
    {
        $client = new Client();

        $client->setApplicationName(
            config('services.google_sheets.application_name', 'LRF App')
        );

        $client->setScopes([
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive',
        ]);

        $client->setAuthConfig(
            storage_path('app/' . env('GOOGLE_SHEETS_CREDENTIALS_FILE'))
        );

        $this->client = $client;
        $this->sheets = new Sheets($client);
    }

    // -------------------------------------------------------------------------
    // Core API wrappers
    // -------------------------------------------------------------------------

    /**
     * Read values from a range with retry handling.
     */
    public function read(string $spreadsheetId, string $range): array
    {
        if (!str_contains($range, '!')) {
            $range .= '!A:Z';
        }

        $attempts = 3;

        while ($attempts--) {
            try {
                $response = $this->sheets
                    ->spreadsheets_values
                    ->get($spreadsheetId, $range);

                return $response->getValues() ?? [];
            } catch (GoogleServiceException $e) {
                if ($attempts === 0) {
                    throw $e;
                }

                sleep(1);
            }
        }

        return [];
    }

    /**
     * Read multiple ranges.
     */
    public function batchRead(string $spreadsheetId, array $ranges): array
    {
        $formattedRanges = array_map(function ($range) {
            return str_contains($range, '!')
                ? $range
                : "{$range}!A:Z";
        }, $ranges);

        $response = $this->sheets->spreadsheets_values->batchGet(
            $spreadsheetId,
            ['ranges' => $formattedRanges]
        );

        $result = [];

        foreach ($response->getValueRanges() as $valueRange) {
            $result[$valueRange->getRange()] = $valueRange->getValues() ?? [];
        }

        return $result;
    }

    /**
     * Append rows.
     */
    public function append(string $spreadsheetId, string $range, array $values): object
    {
        $body = new ValueRange([
            'values' => $values,
        ]);

        return $this->sheets->spreadsheets_values->append(
            $spreadsheetId,
            $range,
            $body,
            [
                'valueInputOption' => 'USER_ENTERED',
                // OVERWRITE writes into the next already-existing (and already
                // validated) empty row instead of INSERT_ROWS, which would insert
                // a fresh grid row that carries no dropdown/validation — turning
                // appended enum values into plain text.
                'insertDataOption' => 'OVERWRITE',
            ]
        );
    }

    /**
     * Update values.
     */
    public function update(string $spreadsheetId, string $range, array $values): object
    {
        $body = new ValueRange([
            'values' => $values,
        ]);

        return $this->sheets->spreadsheets_values->update(
            $spreadsheetId,
            $range,
            $body,
            [
                'valueInputOption' => 'USER_ENTERED',
            ]
        );
    }

    /**
     * Batch update.
     */
    public function batchUpdate(string $spreadsheetId, array $data): object
    {
        $dataRanges = [];

        foreach ($data as $range => $values) {
            $dataRanges[] = new ValueRange([
                'range'  => $range,
                'values' => $values,
            ]);
        }

        $body = new BatchUpdateValuesRequest([
            'valueInputOption' => 'USER_ENTERED',
            'data'             => $dataRanges,
        ]);

        return $this->sheets
            ->spreadsheets_values
            ->batchUpdate($spreadsheetId, $body);
    }

    /**
     * Clear range.
     */
    public function clear(string $spreadsheetId, string $range): object
    {
        return $this->sheets->spreadsheets_values->clear(
            $spreadsheetId,
            $range,
            new ClearValuesRequest()
        );
    }

    // -------------------------------------------------------------------------
    // Row helpers
    // -------------------------------------------------------------------------

    public function appendRow(
        string $spreadsheetId,
        string $sheetName,
        array $rowData
    ): object {
        return $this->append(
            $spreadsheetId,
            "{$sheetName}!A:Z",
            [$rowData]
        );
    }

    public function updateRow(
        string $spreadsheetId,
        string $sheetName,
        int $rowNumber,
        array $rowData
    ): object {
        return $this->update(
            $spreadsheetId,
            "{$sheetName}!A{$rowNumber}",
            [$rowData]
        );
    }

    public function deleteRow(
        string $spreadsheetId,
        string $sheetName,
        int $rowNumber
    ): object {
        $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);

        $request = new SheetsRequest([
            'deleteDimension' => [
                'range' => [
                    'sheetId'    => $sheetId,
                    'dimension'  => 'ROWS',
                    'startIndex' => $rowNumber - 1,
                    'endIndex'   => $rowNumber,
                ],
            ],
        ]);

        return $this->sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest([
                'requests' => [$request],
            ])
        );
    }

    public function findRow(
        string $spreadsheetId,
        string $sheetName,
        int $columnIndex,
        mixed $value
    ): ?int {
        $rows = $this->read(
            $spreadsheetId,
            "{$sheetName}!A:Z"
        );

        foreach ($rows as $index => $row) {

            if ($index === 0) {
                continue;
            }

            if (
                isset($row[$columnIndex]) &&
                (string) $row[$columnIndex] === (string) $value
            ) {
                return $index + 1;
            }
        }

        return null;
    }

    public function upsertRow(
        string $spreadsheetId,
        string $sheetName,
        int $keyColumnIndex,
        mixed $keyValue,
        array $rowData
    ): object {
        $existingRow = $this->findRow(
            $spreadsheetId,
            $sheetName,
            $keyColumnIndex,
            $keyValue
        );

        return $existingRow !== null
            ? $this->updateRow(
                $spreadsheetId,
                $sheetName,
                $existingRow,
                $rowData
            )
            : $this->appendRow(
                $spreadsheetId,
                $sheetName,
                $rowData
            );
    }

    /**
     * Get rows as associative arrays.
     */
    public function getRowsAsAssoc(
        string $spreadsheetId,
        string $range
    ): array {
        if (!str_contains($range, '!')) {
            $range .= '!A:Z';
        }

        $rows = $this->read($spreadsheetId, $range);

        if (empty($rows)) {
            return [];
        }

        $headers = array_shift($rows);
        $width   = count($headers);

        return array_map(
            function ($row) use ($headers, $width) {
                // Normalise every row to exactly the header width: pad short rows
                // with null and truncate any row that has MORE cells than headers.
                // Without the truncate, a single ragged row (extra trailing cell)
                // makes array_combine throw a ValueError and crashes the request.
                $row = array_pad($row, $width, null);
                if (count($row) > $width) {
                    $row = array_slice($row, 0, $width);
                }
                return array_combine($headers, $row);
            },
            $rows
        );
    }

    // -------------------------------------------------------------------------
    // Sheet management
    // -------------------------------------------------------------------------

    public function setHeaders(
        string $spreadsheetId,
        string $sheetName,
        array $headers
    ): object {
        return $this->update(
            $spreadsheetId,
            "{$sheetName}!A1",
            [$headers]
        );
    }

    public function addSheet(
        string $spreadsheetId,
        string $title
    ): object {
        $request = new SheetsRequest([
            'addSheet' => [
                'properties' => [
                    'title' => $title,
                ],
            ],
        ]);

        return $this->sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest([
                'requests' => [$request],
            ])
        );
    }

    public function createSpreadsheet(
        string $title,
        string $sheetName
    ): string {
        $spreadsheet = new \Google\Service\Sheets\Spreadsheet([
            'properties' => [
                'title' => $title,
            ],
            'sheets' => [
                new \Google\Service\Sheets\Sheet([
                    'properties' => new \Google\Service\Sheets\SheetProperties([
                        'title'   => $sheetName,
                        'sheetId' => 0,
                    ]),
                ]),
            ],
        ]);

        $result = $this->sheets
            ->spreadsheets
            ->create($spreadsheet);

        return $result->getSpreadsheetId();
    }

    public function tabExists(
        string $spreadsheetId,
        string $sheetName
    ): bool {
        $spreadsheet = $this->sheets
            ->spreadsheets
            ->get($spreadsheetId);

        foreach ($spreadsheet->getSheets() as $sheet) {

            if (
                $sheet->getProperties()->getTitle() === $sheetName
            ) {
                return true;
            }
        }

        return false;
    }

    public function getSheetIdByName(
        string $spreadsheetId,
        string $sheetName
    ): int {
        $spreadsheet = $this->sheets
            ->spreadsheets
            ->get($spreadsheetId);

        foreach ($spreadsheet->getSheets() as $sheet) {

            if (
                $sheet->getProperties()->getTitle() === $sheetName
            ) {
                return $sheet
                    ->getProperties()
                    ->getSheetId();
            }
        }

        throw new \RuntimeException(
            "Sheet tab '{$sheetName}' not found."
        );
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    // -------------------------------------------------------------------------
    // Validation, dropdowns, formatting
    // -------------------------------------------------------------------------

    /**
     * Apply ONE_OF_LIST dropdown + per-value oval-pill colours to an entire column.
     * Validation extends to every row past the header, so new rows inherit the dropdown.
     */
    public function applyDropdownWithColors(
        string $spreadsheetId,
        string $sheetName,
        int $colIndex,
        array $values,
        array $hexColors
    ): object {
        $sheetId  = $this->getSheetIdByName($spreadsheetId, $sheetName);
        $range    = [
            'sheetId'          => $sheetId,
            'startRowIndex'    => 1,
            'endRowIndex'      => 100000,
            'startColumnIndex' => $colIndex,
            'endColumnIndex'   => $colIndex + 1,
        ];
        $requests = [];

        // 1. Data validation across the entire column (large endRowIndex covers future rows)
        $requests[] = new SheetsRequest([
            'setDataValidation' => [
                'range' => $range,
                'rule' => [
                    'condition' => [
                        'type'   => 'ONE_OF_LIST',
                        'values' => array_map(fn ($v) => ['userEnteredValue' => $v], $values),
                    ],
                    'showCustomUi' => true,
                    'strict'       => true,
                ],
            ],
        ]);

        // 2. Center-align the dropdown column (one-shot — not part of conditional rule)
        $requests[] = new SheetsRequest([
            'repeatCell' => [
                'range'  => $range,
                'cell'   => [
                    'userEnteredFormat' => ['horizontalAlignment' => 'CENTER'],
                ],
                'fields' => 'userEnteredFormat.horizontalAlignment',
            ],
        ]);

        // 2. Conditional-format chip per value (light tint background + bold colour text)
        foreach ($values as $value) {
            $hex = $hexColors[$value] ?? '#5D6D7E';
            [$r, $g, $b] = $this->hexToRgb($hex);
            $bg = [
                'red'   => $r + (1 - $r) * 0.85,
                'green' => $g + (1 - $g) * 0.85,
                'blue'  => $b + (1 - $b) * 0.85,
            ];

            $requests[] = new SheetsRequest([
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges' => [$range],
                        'booleanRule' => [
                            'condition' => [
                                'type'   => 'TEXT_EQ',
                                'values' => [['userEnteredValue' => $value]],
                            ],
                            'format' => [
                                'backgroundColor' => $bg,
                                'textFormat' => [
                                    'foregroundColor' => ['red' => $r, 'green' => $g, 'blue' => $b],
                                    'bold'            => true,
                                ],
                            ],
                        ],
                    ],
                    'index' => 0,
                ],
            ]);
        }

        return $this->sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest(['requests' => $requests])
        );
    }

    /**
     * Apply a live ONE_OF_RANGE dropdown to a column.
     * Source range is in another tab of the same spreadsheet (e.g. "Employees!$C$2:$C$10000").
     * Returns false when the source tab doesn't exist; otherwise true.
     */
    public function applyRangeDropdown(
        string $spreadsheetId,
        string $sheetName,
        int $colIndex,
        string $sourceRange
    ): bool {
        $sourceTab = explode('!', $sourceRange)[0];
        if (!$this->tabExists($spreadsheetId, $sourceTab)) {
            return false;
        }

        $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
        $hasData = !empty($this->read($spreadsheetId, $sourceRange));

        $condition = $hasData
            ? ['type' => 'ONE_OF_RANGE', 'values' => [['userEnteredValue' => '=' . $sourceRange]]]
            : ['type' => 'ONE_OF_LIST',  'values' => [['userEnteredValue' => 'No data available']]];

        // Cover the entire column past the header with an explicit upper bound so
        // future appended rows still inherit the dropdown.
        $range = [
            'sheetId'          => $sheetId,
            'startRowIndex'    => 1,
            'endRowIndex'      => 100000,
            'startColumnIndex' => $colIndex,
            'endColumnIndex'   => $colIndex + 1,
        ];

        $requests = [
            // 1. Data validation — strict so the cell stays a real dropdown.
            new SheetsRequest([
                'setDataValidation' => [
                    'range' => $range,
                    'rule'  => [
                        'condition'    => $condition,
                        'showCustomUi' => true,
                        'strict'       => $hasData,
                    ],
                ],
            ]),
            // 2. Center alignment for the column.
            new SheetsRequest([
                'repeatCell' => [
                    'range'  => $range,
                    'cell'   => ['userEnteredFormat' => ['horizontalAlignment' => 'CENTER']],
                    'fields' => 'userEnteredFormat.horizontalAlignment',
                ],
            ]),
            // 3. Chip-style pill for any non-empty cell so values look like dropdown chips.
            new SheetsRequest([
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges'      => [$range],
                        'booleanRule' => [
                            'condition' => ['type' => 'NOT_BLANK'],
                            'format'    => [
                                'backgroundColor' => ['red' => 0.93, 'green' => 0.95, 'blue' => 0.98],
                                'textFormat'      => [
                                    'foregroundColor' => ['red' => 0.15, 'green' => 0.25, 'blue' => 0.45],
                                    'bold'            => true,
                                ],
                            ],
                        ],
                    ],
                    'index' => 0,
                ],
            ]),
        ];

        $this->sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest(['requests' => $requests])
        );

        return true;
    }

    /**
     * Apply DATE_IS_VALID validation + yyyy-mm-dd display format to date columns.
     */
    public function applyDatePicker(
        string $spreadsheetId,
        string $sheetName,
        array $colIndexes
    ): void {
        if (empty($colIndexes)) return;

        $sheetId  = $this->getSheetIdByName($spreadsheetId, $sheetName);
        $requests = [];

        foreach ($colIndexes as $colIndex) {
            $range = [
                'sheetId'          => $sheetId,
                'startRowIndex'    => 1,
                'endRowIndex'      => 100000,
                'startColumnIndex' => $colIndex,
                'endColumnIndex'   => $colIndex + 1,
            ];

            $requests[] = new SheetsRequest([
                'setDataValidation' => [
                    'range' => $range,
                    'rule'  => [
                        'condition'    => ['type' => 'DATE_IS_VALID'],
                        'showCustomUi' => true,
                        'strict'       => false,
                    ],
                ],
            ]);

            $requests[] = new SheetsRequest([
                'repeatCell' => [
                    'range'  => $range,
                    'cell'   => [
                        'userEnteredFormat' => [
                            'numberFormat' => ['type' => 'DATE', 'pattern' => 'yyyy-mm-dd'],
                        ],
                    ],
                    'fields' => 'userEnteredFormat.numberFormat',
                ],
            ]);
        }

        $this->sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new BatchUpdateSpreadsheetRequest(['requests' => $requests])
        );
    }

}