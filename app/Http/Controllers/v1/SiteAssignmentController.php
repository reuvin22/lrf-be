<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SiteAssignmentRequest;
use App\Http\Resources\v1\SiteAssignmentResource;
use App\Models\SiteAssignment;
use App\Models\SiteAssignments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SiteAssignmentController extends Controller
{
    public function index()
    {
        $assignments = SiteAssignments::with(['employee', 'site'])
            ->orderBy('assignment_id', 'desc')
            ->get();

        return SiteAssignmentResource::collection($assignments);
    }

    public function store(SiteAssignmentRequest $request)
    {
        $validated = $request->validated();
        $validated['assignment_id'] = (string) Str::uuid();
        $siteAssignment = SiteAssignments::create($validated);

        return response()->json([
            'message' => 'Site assignment created successfully',
            'data' => $siteAssignment
        ], 201);
    }

    public function show(string $id)
    {
        $siteAssignment = SiteAssignments::findOrFail($id);

        return response()->json($siteAssignment);
    }

    public function update(SiteAssignmentRequest $request, string $id)
    {
        $siteAssignment = SiteAssignments::findOrFail($id);

        $siteAssignment->update([
            'employee_id' => $request->employee_id,
            'site_id' => $request->site_id,
            'is_leader' => $request->is_leader ?? false,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return response()->json([
            'message' => 'Site assignment updated successfully',
            'data' => $siteAssignment
        ]);
    }

    public function destroy(string $id)
    {
        $siteAssignment = SiteAssignments::findOrFail($id);
        $siteAssignment->delete();

        return response()->json([
            'message' => 'Site assignment deleted successfully'
        ]);
    }

    public function assignedSitesEmployee(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|integer|exists:employees,employee_id',
        ]);

        $employeeId = $request->input('employee_id');
        $assignments = SiteAssignments::with(['employee', 'site'])
            ->where('employee_id', $employeeId)
            ->orderBy('assignment_id', 'desc')
            ->get();

        return response()->json([
            'message' => 'Assigned sites retrieved successfully',
            'data' => SiteAssignmentResource::collection($assignments),
        ]);
    }
}
