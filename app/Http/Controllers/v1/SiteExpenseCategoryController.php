<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SiteExpenseCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SiteExpenseCategoryController extends SheetResourceController
{
    protected string $sheetName = 'SiteExpenseCategories';
    protected string $idColumn  = 'category_id';
    protected array $headers    = ['category_id', 'category_name', 'description', 'status'];

    public function store(SiteExpenseCategoryRequest $request): JsonResponse
    {
        $data                = $request->validated();
        $data['category_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Site expense category created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SiteExpenseCategoryRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                = array_merge($located['data'], $request->validated());
        $data['category_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Site expense category updated successfully.',
            'data'    => $data,
        ]);
    }
}
