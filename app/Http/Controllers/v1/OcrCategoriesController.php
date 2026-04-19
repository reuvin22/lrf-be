<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\OcrUploadsCategoryRequest;
use Illuminate\Http\Request;
use App\Models\OcrUploadCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OcrCategoriesController extends Controller
{
    public function index()
    {
        $categories = OcrUploadCategory::orderBy('category_name')->get();
        return response()->json(['data' => $categories], 200);
    }

    public function store(OcrUploadsCategoryRequest $request)
    {
        $data = $request->validated();
        $data['category_id'] = (string) Str::uuid();

        $category = OcrUploadCategory::create($data);

        return response()->json(['data' => $category], 201);
    }

    public function show($id)
    {
        $category = OcrUploadCategory::find($id);

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json(['data' => $category], 200);
    }

    public function update(OcrUploadsCategoryRequest $request, $id)
    {
        $category = OcrUploadCategory::find($id);

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        $validated = $request->validated();

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $category
        ], 200);
    }

    public function destroy($id)
    {
        $category = OcrUploadCategory::find($id);

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully'], 200);
    }
}
