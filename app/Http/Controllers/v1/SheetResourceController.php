<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class SheetResourceController extends Controller
{
    protected string $sheetName;
    protected string $idColumn;
    protected array $headers;

    public function __construct(protected GoogleSheetService $sheet)
    {
    }

    protected function spreadsheetId(): string
    {
        return config('services.google_sheets.spreadsheet_id');
    }

    protected function all(): array
    {
        return $this->sheet->getRowsAsAssoc($this->spreadsheetId(), $this->sheetName);
    }

    protected function find(string $id): ?array
    {
        return collect($this->all())->firstWhere($this->idColumn, $id) ?: null;
    }

    /**
     * Find a record's data + 1-indexed row number in a single API call.
     * Returns ['rowNumber' => int, 'data' => array] or null.
     */
    protected function locate(string $id): ?array
    {
        $rows = $this->all();
        foreach ($rows as $index => $row) {
            if (($row[$this->idColumn] ?? null) === $id) {
                return ['rowNumber' => $index + 2, 'data' => $row];
            }
        }
        return null;
    }

    /**
     * Filter all rows by an associative set of column => value pairs.
     */
    protected function where(array $criteria): array
    {
        return array_values(array_filter($this->all(), function ($row) use ($criteria) {
            foreach ($criteria as $column => $value) {
                if (($row[$column] ?? null) !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Map an associative payload to a positional row aligned with $headers.
     */
    protected function toRow(array $data): array
    {
        return array_map(fn ($h) => $this->stringify($data[$h] ?? ''), $this->headers);
    }

    private function stringify(mixed $v): string
    {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_array($v) || is_object($v)) return json_encode($v);
        return (string) ($v ?? '');
    }

    protected function appendRow(array $data): void
    {
        $this->sheet->appendRow($this->spreadsheetId(), $this->sheetName, $this->toRow($data));
    }

    protected function updateRowAt(int $rowNumber, array $data): void
    {
        $this->sheet->updateRow($this->spreadsheetId(), $this->sheetName, $rowNumber, $this->toRow($data));
    }

    protected function deleteRowAt(int $rowNumber): void
    {
        $this->sheet->deleteRow($this->spreadsheetId(), $this->sheetName, $rowNumber);
    }

    protected function notFound(string $message = 'Not found'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 404);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->all()]);
    }

    public function show(string $id): JsonResponse
    {
        $record = $this->find($id);
        return $record
            ? response()->json(['success' => true, 'data' => $record])
            : $this->notFound();
    }

    public function destroy(Request $request, ?string $id = null): JsonResponse
    {
        if ($id === null) return $this->notFound('Id is required.');

        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $this->deleteRowAt($located['rowNumber']);
        return response()->json(['success' => true, 'message' => 'Deleted successfully.']);
    }
}
