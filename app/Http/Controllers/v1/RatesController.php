<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\RatesRequest;
use App\Models\Rates;
use Illuminate\Support\Str;

class RatesController extends Controller
{
    /**
     * Display all rates
     */
    public function index()
    {
        $rates = Rates::with(['employee', 'sub_contractor', 'site'])->get();

        return response()->json([
            'data' => $rates
        ]);
    }

    /**
     * Store new rate
     */
    public function store(RatesRequest $request)
    {
        $validated = $request->validated();
        $validated['rate_id'] = (string) Str::uuid();
        $rate = Rates::create($validated);
        return response()->json([
            'message' => 'Rate created successfully',
            'data' => $rate
        ], 201);
    }

    /**
     * Show single rate
     */
    public function show(string $id)
    {
        $rate = Rates::with(['employee', 'sub_contractor', 'site'])
            ->find($id);

        if (!$rate) {
            return response()->json([
                'message' => 'Rate not found'
            ], 404);
        }

        return response()->json([
            'data' => $rate
        ]);
    }

    /**
     * Update rate
     */
    public function update(RatesRequest $request, string $id)
    {
        $rate = Rates::find($id);

        if (!$rate) {
            return response()->json([
                'message' => 'Rate not found'
            ], 404);
        }

        $rate->update($request->validated());

        return response()->json([
            'message' => 'Rate updated successfully',
            'data' => $rate
        ]);
    }

    /**
     * Delete rate
     */
    public function destroy(string $id)
    {
        $rate = Rates::find($id);

        if (!$rate) {
            return response()->json([
                'message' => 'Rate not found'
            ], 404);
        }

        $rate->delete();

        return response()->json([
            'message' => 'Rate deleted successfully'
        ]);
    }
}