<?php

namespace App\Traits;

trait SyncsToGoogleSheet
{
    // Override in the controller to change which column holds the primary key (0-indexed).
    protected int $sheetKeyColumn = 0;

    // -------------------------------------------------------------------------
    // Contract — each controller must implement these three methods
    // -------------------------------------------------------------------------

    abstract protected function getSheetName(): string;

    abstract protected function getSheetHeaders(): array;

    abstract protected function toSheetRow(mixed $model): array;

    // -------------------------------------------------------------------------
    // Reusable sheet operations
    // -------------------------------------------------------------------------

    /**
     * Called after a successful DB insert.
     * Writes the header row once (when the sheet is still empty), then appends.
     */
    protected function storeToSheet(string $spreadsheetId, mixed $model): void
    {
        if (empty($this->sheet->read($spreadsheetId, $this->getSheetName()))) {
            $this->sheet->setHeaders($spreadsheetId, $this->getSheetName(), $this->getSheetHeaders());
        }

        $this->sheet->appendRow($spreadsheetId, $this->getSheetName(), $this->toSheetRow($model));
    }

    /**
     * Called after a successful DB update.
     * Finds the row by key value and overwrites it; appends if somehow missing.
     */
    protected function updateInSheet(string $spreadsheetId, mixed $keyValue, mixed $model): void
    {
        $this->sheet->upsertRow(
            $spreadsheetId,
            $this->getSheetName(),
            $this->sheetKeyColumn,
            $keyValue,
            $this->toSheetRow($model)
        );
    }

    /**
     * Called after a successful DB delete.
     * Locates the row by key value and removes it; no-op when not found.
     */
    protected function deleteFromSheet(string $spreadsheetId, mixed $keyValue): void
    {
        $rowNumber = $this->sheet->findRow(
            $spreadsheetId,
            $this->getSheetName(),
            $this->sheetKeyColumn,
            $keyValue
        );

        if ($rowNumber !== null) {
            $this->sheet->deleteRow($spreadsheetId, $this->getSheetName(), $rowNumber);
        }
    }
}
