<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SubContractorReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SubContractorReportController extends SheetResourceController
{
    protected string $sheetName = 'SubContractorReports';
    protected string $idColumn  = 'uuid';
    protected array $headers    = [
        'uuid', 'attendance_id', 'employee_id', 'worker_id', 'worker_name',
        'contract_type', 'company_name', 'site_id', 'start_time', 'end_time',
    ];

    public function store(SubContractorReportRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['uuid'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Subcontractor report created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SubContractorReportRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data         = array_merge($located['data'], $request->validated());
        $data['uuid'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Subcontractor report updated successfully.',
            'data'    => $data,
        ]);
    }
}
