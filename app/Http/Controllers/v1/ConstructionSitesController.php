<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\ConstructionSitesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ConstructionSitesController extends SheetResourceController
{
    protected string $sheetName = 'ConstructionSites';
    protected string $idColumn  = 'site_id';
    protected array $headers    = [
        'site_id', 'site_code', 'site_name', 'client_name', 'contract_type',
        'address', 'status', 'start_date', 'end_date', 'contract_amount', 'dotto_genka_code',
    ];

    public function store(ConstructionSitesRequest $request): JsonResponse
    {
        $data            = $request->validated();
        $data['site_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Construction site created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(ConstructionSitesRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data            = array_merge($located['data'], $request->validated());
        $data['site_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Construction site updated successfully.',
            'data'    => $data,
        ]);
    }
}
