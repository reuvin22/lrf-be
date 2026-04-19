<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\DayTypeRequest;
use App\Models\DayType;

class DayTypeController extends Controller
{
    /**
     * List all
     */
    public function index()
    {
        $data = DayType::latest()->get();

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Store
     */
    public function store(DayTypeRequest $request)
    {
        $dayType = DayType::create($request->validated());

        return response()->json([
            'message' => 'Day type created successfully',
            'data' => $dayType
        ], 201);
    }

    /**
     * Show single
     */
    public function show(string $id)
    {
        $dayType = DayType::find($id);

        if (!$dayType) {
            return response()->json([
                'message' => 'Day type not found'
            ], 404);
        }

        return response()->json([
            'data' => $dayType
        ]);
    }

    /**
     * Update
     */
    public function update(DayTypeRequest $request, string $id)
    {
        $dayType = DayType::find($id);

        if (!$dayType) {
            return response()->json([
                'message' => 'Day type not found'
            ], 404);
        }

        $dayType->update($request->validated());

        return response()->json([
            'message' => 'Day type updated successfully',
            'data' => $dayType
        ]);
    }

    /**
     * Delete
     */
    public function destroy(string $id)
    {
        $dayType = DayType::find($id);

        if (!$dayType) {
            return response()->json([
                'message' => 'Day type not found'
            ], 404);
        }

        $dayType->delete();

        return response()->json([
            'message' => 'Day type deleted successfully'
        ]);
    }
}