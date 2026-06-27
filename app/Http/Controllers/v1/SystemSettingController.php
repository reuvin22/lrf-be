<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SystemSettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SystemSettingController extends SheetResourceController
{
    protected string $sheetName = 'SystemSettings';
    protected string $idColumn  = 'system_settings_id';
    protected array $headers    = ['system_settings_id', 'key', 'value', 'description'];

    public function store(SystemSettingRequest $request): JsonResponse
    {
        $data                       = $request->validated();
        $data['system_settings_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'System setting created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SystemSettingRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                       = array_merge($located['data'], $request->validated());
        $data['system_settings_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'System setting updated successfully.',
            'data'    => $data,
        ]);
    }
}
