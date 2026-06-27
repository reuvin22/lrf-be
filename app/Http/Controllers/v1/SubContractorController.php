<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SubContractorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SubContractorController extends SheetResourceController
{
    protected string $sheetName = 'SubContractors';
    protected string $idColumn  = 'subcontractor_id';
    protected array $headers    = [
        'subcontractor_id', 'company_name', 'contact_person', 'contact_phone', 'status',
    ];

    public function store(SubContractorRequest $request): JsonResponse
    {
        $data                     = $request->validated();
        $data['subcontractor_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Subcontractor created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SubContractorRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                     = array_merge($located['data'], $request->validated());
        $data['subcontractor_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Subcontractor updated successfully.',
            'data'    => $data,
        ]);
    }
}
