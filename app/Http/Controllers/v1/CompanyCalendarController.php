<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\CompanyCalendarRequest;
use App\Models\CompanyCalendar;
use Illuminate\Support\Str;

class CompanyCalendarController extends Controller
{
    /**
     * List all calendar entries
     */
    public function index()
    {
        $data = CompanyCalendar::orderBy('date', 'asc')->get();

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Store new entry
     */
    public function store(CompanyCalendarRequest $request)
    {
        $validated = $request->validated();

        $validated['calendar_id'] = (string) Str::uuid();

        $calendar = CompanyCalendar::create($validated);

        return response()->json([
            'message' => 'Calendar created successfully',
            'data' => $calendar
        ], 201);
    }

    /**
     * Show single entry
     */
    public function show(string $id)
    {
        $calendar = CompanyCalendar::find($id);

        if (!$calendar) {
            return response()->json([
                'message' => 'Calendar not found'
            ], 404);
        }

        return response()->json([
            'data' => $calendar
        ]);
    }

    /**
     * Update entry
     */
    public function update(CompanyCalendarRequest $request, string $id)
    {
        $calendar = CompanyCalendar::find($id);

        if (!$calendar) {
            return response()->json([
                'message' => 'Calendar not found'
            ], 404);
        }

        $calendar->update($request->validated());

        return response()->json([
            'message' => 'Calendar updated successfully',
            'data' => $calendar
        ]);
    }

    /**
     * Delete entry
     */
    public function destroy(string $id)
    {
        $calendar = CompanyCalendar::find($id);

        if (!$calendar) {
            return response()->json([
                'message' => 'Calendar not found'
            ], 404);
        }

        $calendar->delete();

        return response()->json([
            'message' => 'Calendar deleted successfully'
        ]);
    }
}