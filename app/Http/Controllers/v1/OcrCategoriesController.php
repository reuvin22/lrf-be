<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\OcrUploadsCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class OcrCategoriesController extends SheetResourceController
{
    protected string $sheetName = 'OcrUploadCategories';
    protected string $idColumn  = 'category_id';
    protected array $headers    = [
        'category_id', 'category_name', 'description', 'status',
    ];

    public function store(OcrUploadsCategoryRequest $request): JsonResponse
    {
        $data                = $request->validated();
        $data['category_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'OCR category created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(OcrUploadsCategoryRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                = array_merge($located['data'], $request->validated());
        $data['category_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'OCR category updated successfully.',
            'data'    => $data,
        ]);
    }
}
