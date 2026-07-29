<?php

namespace App\Http\Controllers\v1;

use App\Events\AttendanceEvent;
use App\Http\Requests\v1\AttendanceEmployeeRequest;
use App\Http\Requests\v1\AttendanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceController extends SheetResourceController
{
    protected string $sheetName = 'Attendance';
    protected string $idColumn  = 'attendance_id';
    protected array $headers    = [
        'attendance_id', 'employee_id', 'work_date', 'status',
        'total_work_minutes', 'overtime_minutes',
    ];

    public function index(Request $request): JsonResponse
    {
        $rows = $this->all();

        if ($request->filled('employee_id')) {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => ($r['employee_id'] ?? null) === $request->employee_id
            ));
        }

        if ($request->filled('work_date')) {
            $target = (string) $request->work_date;
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => str_starts_with((string) ($r['work_date'] ?? ''), $target)
            ));
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(AttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $duplicate = collect($this->all())->first(
            fn ($r) =>
            ($r['employee_id'] ?? null) === ($data['employee_id'] ?? null)
            && str_starts_with((string) ($r['work_date'] ?? ''), (string) ($data['work_date'] ?? ''))
        );

        if ($duplicate) {
            // Idempotent: an attendance already exists for this employee/work_date,
            // so return it as a success and let the caller continue without error.
            return response()->json([
                'success' => true,
                'message' => 'Attendance already exists for this work date.',
                'data'    => $duplicate,
            ]);
        }

        $data['attendance_id'] = (string) Str::uuid();
        $this->appendRow($data);
        event(new AttendanceEvent((object) $data, 'updated'));

        return response()->json([
            'success' => true,
            'message' => 'Attendance created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(AttendanceRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                  = array_merge($located['data'], $request->validated());
        $data['attendance_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);
        event(new AttendanceEvent((object) $data, 'updated'));

        return response()->json([
            'success' => true,
            'message' => 'Attendance updated successfully.',
            'data'    => $data,
        ]);
    }

    public function destroy(Request $request, ?string $id = null): JsonResponse
    {
        if ($id === null) return $this->notFound('Id is required.');

        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $this->deleteRowAt($located['rowNumber']);
        event(new AttendanceEvent((object) $located['data'], 'deleted'));

        return response()->json([
            'success' => true,
            'message' => 'Attendance deleted successfully.',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $date           = $request->date ?? now()->toDateString();
        $attendances    = $this->all();
        $employees      = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'Employees');
        $segments       = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'Segments');
        $expenses       = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'TransportationExpenses');
        $subSegments    = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'AttendanceSubSegments');

        $employeesById  = collect($employees)->keyBy('employee_id');

        $result = collect($attendances)
            ->filter(fn ($a) => str_starts_with((string) ($a['work_date'] ?? ''), $date))
            ->map(function ($a) use ($employeesById, $segments, $expenses, $subSegments) {
                $aid = $a['attendance_id'] ?? null;
                return array_merge($a, [
                    'employee'                          => $employeesById->get($a['employee_id'] ?? '') ?? null,
                    'segments'                          => array_values(array_filter($segments,    fn ($s) => ($s['attendance_id'] ?? null) === $aid)),
                    'transportation_expenses'           => array_values(array_filter($expenses,    fn ($e) => ($e['attendance_id'] ?? null) === $aid)),
                    'attendance_subcontractor_segments' => array_values(array_filter($subSegments, fn ($s) => ($s['attendance_id'] ?? null) === $aid)),
                ]);
            })
            ->values();

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function attendance_employee(AttendanceEmployeeRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['uuid'] = (string) Str::uuid();

        $row = [
            $data['uuid'],
            $data['attendance_id'] ?? '',
            $data['employee_id'] ?? '',
        ];
        $this->sheet->appendRow($this->spreadsheetId(), 'AttendanceEmployees', $row);

        return response()->json([
            'success' => true,
            'message' => 'Employee assigned to attendance successfully.',
            'data'    => $data,
        ], 201);
    }

    public function get_attendance_employee_by_attendance(Request $request): JsonResponse
    {
        $attendanceId = $request->query('attendance_id');

        if (!$attendanceId) {
            return response()->json([
                'success' => false,
                'message' => 'attendance_id is required.',
            ], 400);
        }

        $rows = $this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'AttendanceEmployees');
        $matches = array_values(array_filter(
            $rows,
            fn ($r) => ($r['attendance_id'] ?? null) === $attendanceId
        ));

        return response()->json(['success' => true, 'data' => $matches]);
    }
}