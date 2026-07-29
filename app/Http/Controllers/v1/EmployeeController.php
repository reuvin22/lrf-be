<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\EmployeeRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EmployeeController extends SheetResourceController
{
    protected string $sheetName = 'Employees';
    protected string $idColumn  = 'employee_id';
    protected array $headers    = [
        'employee_id', 'employee_code', 'name', 'name_kana', 'line_user_id',
        'email', 'employment_type', 'role', 'base_salary', 'monthly_work_hours',
        'cost_rate', 'commute_cost_monthly', 'joined_date', 'status', 'salary_type',
    ];

    public function store(EmployeeRequests $request): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['line_user_id'])) {
            $lineUserId = trim((string) $data['line_user_id']);
            $existing = collect($this->all())->first(function ($row) use ($lineUserId) {
                $rowId = isset($row['line_user_id']) ? trim((string) $row['line_user_id']) : '';
                return $rowId !== '' && $rowId === $lineUserId;
            });

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Employee already exists for this LINE user.',
                    'data'    => $existing,
                ], 200);
            }
        }

        $data['employee_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(EmployeeRequests $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                = array_merge($located['data'], $request->validated());
        $data['employee_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully.',
            'data'    => $data,
        ]);
    }
}
