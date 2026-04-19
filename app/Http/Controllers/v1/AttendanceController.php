<?php

namespace App\Http\Controllers\v1;

use App\Events\AttendanceEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\AttendanceEmployeeRequest;
use App\Http\Requests\v1\AttendanceRequest;
use App\Http\Resources\v1\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::with(['segments', 'transportation_expenses', 'attendance_subcontractor_segments']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('work_date')) {
            $query->whereDate('work_date', $request->work_date);
        }

        return response()->json([
            'message' => 'Attendance list retrieved successfully',
            'data' => $query->get(),
            'request' => $request->all()
        ]);
    }

    public function store(AttendanceRequest $request)
    {
        $validated = $request->validated();
        $validated['uuid'] = (string) Str::uuid();
        $existing = Attendance::where('employee_id', $validated['employee_id'])
            ->where('work_date', $validated['work_date'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Attendance already exists for this work date',
                'data' => $existing
            ], 200);
        }

        $attendance = Attendance::create($validated);
        event(new AttendanceEvent($attendance, 'updated'));
        return response()->json([
            'message' => 'Attendance created successfully',
            'data' => $attendance
        ], 201);
    }

    public function show(string $id)
    {
        $attendance = Attendance::findOrFail($id);

        return response()->json([
            'message' => 'Attendance retrieved successfully',
            'data' => $attendance
        ]);
    }

    public function update(AttendanceRequest $request, string $id)
    {
        $attendance = Attendance::findOrFail($id);

        $validated = $request->validated();

        $attendance->update($validated);
        event(new AttendanceEvent($attendance, 'updated'));
        return response()->json([
            'message' => 'Attendance updated successfully',
            'data' => $attendance
        ]);
    }

    public function destroy(string $id)
    {
        $attendance = Attendance::findOrFail($id);
        $attendance->delete();
        event(new AttendanceEvent($attendance, 'updated'));
        return response()->json([
            'message' => 'Attendance deleted successfully'
        ]);
    }

    public function dashboard(Request $request)
    {
        $date = $request->date ?? now()->toDateString();

        $attendance = Attendance::whereDate('work_date', $date)->get();

        $attendance->load([
            'employee',
            'segments',
            'transportation_expenses',
            'attendance_subcontractor_segments',
        ]);

        return AttendanceResource::collection($attendance);
    }

    public function attendance_employee(AttendanceEmployeeRequest $request)
    {
        $validated = $request->validated();
        $validated['uuid'] = (string) Str::uuid();
        $attendanceEmployee = AttendanceEmployee::create($validated);

        return response()->json([
            'message' => 'Employee assigned to attendance successfully',
            'data' => $attendanceEmployee,
        ]);
    }

    public function get_attendance_employee_by_attendance(Request $request)
    {
        $attendance_id = $request->query('attendance_id');

        if (!$attendance_id) {
            return response()->json([
                'message' => 'attendance_id is required',
            ], 400);
        }

        $attendanceEmployees = AttendanceEmployee::with('segments')
            ->where('attendance_id', $attendance_id)
            ->get();

        return response()->json([
            'message' => 'Employees and segments for attendance retrieved successfully',
            'data' => $attendanceEmployees,
        ]);
    }
}
