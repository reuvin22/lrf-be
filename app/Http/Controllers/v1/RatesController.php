<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\RatesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class RatesController extends SheetResourceController
{
    protected string $sheetName = 'Rates';
    protected string $idColumn  = 'rate_id';
    protected array $headers    = [
        'rate_id', 'rate_type', 'target_type', 'target_id', 'target_name',
        'site_id', 'site_name', 'unit_price', 'effective_from', 'effective_to',
    ];

    public function store(RatesRequest $request): JsonResponse
    {
        $data            = $request->validated();
        $data['rate_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Rate created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(RatesRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data            = array_merge($located['data'], $request->validated());
        $data['rate_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Rate updated successfully.',
            'data'    => $data,
        ]);
    }
}
