<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\TransportationExpenseRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TransportationExpenseController extends SheetResourceController
{
    protected string $sheetName = 'TransportationExpenses';
    protected string $idColumn  = 'expense_id';
    protected array $headers    = [
        'expense_id', 'attendance_id', 'employee_id', 'amount', 'route', 'site_id',
    ];

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();

        if ($request->filled('attendance_id')) {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => ($r['attendance_id'] ?? null) === $request->attendance_id
            ));
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(TransportationExpenseRequests $request): JsonResponse
    {
        $validated = $request->validated();

        if (array_is_list($validated)) {
            $created = array_map(function ($item) {
                $item['expense_id'] = (string) Str::uuid();
                $this->appendRow($item);
                return $item;
            }, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Transportation expenses created successfully.',
                'data'    => $created,
            ], 201);
        }

        $validated['expense_id'] = (string) Str::uuid();
        $this->appendRow($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transportation expense created successfully.',
            'data'    => $validated,
        ], 201);
    }

    public function update(TransportationExpenseRequests $request, ?string $id = null): JsonResponse
    {
        $validated = $request->validated();

        unset($validated['ids']);

        $ids = $id
            ? explode(',', $id)
            : ($request->input('ids', []));

        $updated = [];

        foreach ($ids as $expenseId) {

            $located = $this->locate($expenseId);

            if (!$located) {
                continue;
            }

            $data = array_merge($located['data'], $validated);

            $data['expense_id'] = $expenseId;

            // IMPORTANT:
            // reorder values based on headers
            $ordered = [];

            foreach ($this->headers as $header) {
                $ordered[$header] = $data[$header] ?? null;
            }

            $this->updateRowAt(
                $located['rowNumber'],
                $ordered
            );

            $updated[] = $ordered;
        }

        return response()->json([
            'success' => true,
            'message' => 'Transportation expense(s) updated successfully.',
            'data'    => $updated,
        ]);
    }

    public function destroy(Request $request, ?string $id = null): JsonResponse
    {
        $ids = $id ? explode(',', $id) : $request->input('ids', []);

        $this->deleteByIds($ids);

        return response()->json([
            'success' => true,
            'message' => 'Transportation expense(s) deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (!is_array($ids) || empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'No IDs provided.',
            ], 400);
        }

        $this->deleteByIds($ids);

        return response()->json([
            'success' => true,
            'message' => 'Transportation expenses deleted successfully.',
        ]);
    }

    /**
     * Delete rows in descending order so row numbers don't shift mid-loop.
     */
    private function deleteByIds(array $ids): void
    {
        $rows         = $this->all();
        $rowNumbers   = [];
        foreach ($rows as $index => $row) {
            if (in_array($row[$this->idColumn] ?? null, $ids, true)) {
                $rowNumbers[] = $index + 2;
            }
        }
        rsort($rowNumbers);
        foreach ($rowNumbers as $rn) {
            $this->deleteRowAt($rn);
        }
    }
}
