<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SubContractorWorkerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SubContractorWorkersController extends SheetResourceController
{
    protected string $sheetName = 'SubContractorWorkers';
    protected string $idColumn  = 'worker_id';
    protected array $headers    = [
        'worker_id', 'subcontractor_id', 'subcontractor_name', 'name', 'name_kana', 'status',
    ];

    public function store(SubContractorWorkerRequest $request): JsonResponse
    {
        $data                       = $request->validated();
        $data['worker_id']          = (string) Str::uuid();
        $data['subcontractor_name'] = $this->lookupSubcontractorName($data['subcontractor_id'] ?? null);

        $this->appendRow($data);
        Log::info('THIS IS DATA: ', ['data' => $data]);
        return response()->json([
            'success' => true,
            'message' => 'Worker created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SubContractorWorkerRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data              = array_merge($located['data'], $request->validated());
        $data['worker_id'] = $id;

        if (array_key_exists('subcontractor_id', $request->validated())) {
            $data['subcontractor_name'] = $this->lookupSubcontractorName($data['subcontractor_id'] ?? null);
        }

        $this->updateRowAt($located['rowNumber'], $data);
        Log::info('THIS IS DATA: ', ['data' => $data]);
        return response()->json([
            'success' => true,
            'message' => 'Worker updated successfully.',
            'data'    => $data,
        ]);
    }

    private function lookupSubcontractorName(?string $subcontractorId): string
    {
        if (!$subcontractorId) return '';

        $row = collect($this->sheet->getRowsAsAssoc($this->spreadsheetId(), 'SubContractors'))
            ->firstWhere('subcontractor_id', $subcontractorId);

        return $row['company_name'] ?? '';
    }
}
