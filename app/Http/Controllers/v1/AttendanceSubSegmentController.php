<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\AttendanceSubSegmentsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceSubSegmentController extends SheetResourceController
{
    protected string $sheetName = 'AttendanceSubSegments';
    protected string $idColumn  = 'uuid';
    protected array $headers    = [
        'uuid', 'attendance_id', 'segment_id', 'company_id', 'company_name',
        'employee_id', 'worker_id', 'worker_name', 'site_id', 'site_name',
        'contract_type', 'start_time', 'end_time',
    ];

    public function store(AttendanceSubSegmentsRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['uuid'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Attendance sub segment created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(AttendanceSubSegmentsRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data         = array_merge($located['data'], $request->validated());
        $data['uuid'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Attendance sub segment updated successfully.',
            'data'    => $data,
        ]);
    }

    public function getAttendanceSubcontractor(Request $request): JsonResponse
    {
        $employeeId = $request->employee_id;

        if (!$employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'employee_id is required.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->where(['employee_id' => $employeeId]),
        ]);
    }
}
