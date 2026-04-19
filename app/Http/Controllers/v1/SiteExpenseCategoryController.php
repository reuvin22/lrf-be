<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SiteExpenseCategoryRequest;
use App\Models\SiteExpenseCategory;
use Illuminate\Support\Str;

class SiteExpenseCategoryController extends Controller
{
    /**
     * List all categories
     */
    public function index()
    {
        $categories = SiteExpenseCategory::latest()->get();

        return response()->json([
            'data' => $categories
        ]);
    }

    /**
     * Store new category
     */
    public function store(SiteExpenseCategoryRequest $request)
    {
        $validated = $request->validated();
        $validated['category_id'] = (string) Str::uuid();
        $category = SiteExpenseCategory::create($validated);

        return response()->json([
            'message' => 'Category created successfully',
            'data' => $category
        ], 201);
    }

    /**
     * Show single category
     */
    public function show(string $id)
    {
        $category = SiteExpenseCategory::find($id);

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'data' => $category
        ]);
    }

    /**
     * Update category
     */
    public function update(SiteExpenseCategoryRequest $request, string $id)
    {
        $category = SiteExpenseCategory::find($id);

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        $category->update($request->validated());

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $category
        ]);
    }

    /**
     * Delete category
     */
    public function destroy(string $id)
    {
        $category = SiteExpenseCategory::find($id);

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully'
        ]);
    }
}