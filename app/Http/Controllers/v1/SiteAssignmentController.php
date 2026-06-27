<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SiteAssignmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SiteAssignmentController extends SheetResourceController
{
    protected string $sheetName = 'SiteAssignments';
    protected string $idColumn  = 'assignment_id';
    protected array $headers    = [
        'assignment_id', 'worker_id', 'worker_name', 'site_id', 'site_name',
        'is_leader', 'start_date', 'end_date',
    ];

    public function store(SiteAssignmentRequest $request): JsonResponse
    {
        $data                  = $request->validated();
        $data['assignment_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Site assignment created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SiteAssignmentRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                  = array_merge($located['data'], $request->validated());
        $data['assignment_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Site assignment updated successfully.',
            'data'    => $data,
        ]);
    }

    public function assignedSitesEmployee(Request $request): JsonResponse
    {
        $request->validate(['employee_id' => 'required|uuid']);

        return response()->json([
            'success' => true,
            'data'    => $this->where(['worker_id' => $request->employee_id]),
        ]);
    }
}
