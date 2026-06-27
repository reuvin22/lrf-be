<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\DayTypeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DayTypeController extends SheetResourceController
{
    protected string $sheetName = 'DayTypes';
    protected string $idColumn  = 'id';
    protected array $headers    = ['id', 'value', 'description', 'overtime_multiplier'];

    public function store(DayTypeRequest $request): JsonResponse
    {
        $data       = $request->validated();
        $data['id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Day type created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(DayTypeRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data       = array_merge($located['data'], $request->validated());
        $data['id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Day type updated successfully.',
            'data'    => $data,
        ]);
    }
}
