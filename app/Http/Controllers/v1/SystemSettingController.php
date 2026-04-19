<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SystemSettingRequest;
use App\Models\SystemSettings;
use Illuminate\Support\Str;

class SystemSettingController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => SystemSettings::all()
        ]);
    }

    public function store(SystemSettingRequest $request)
    {
        $validated = $request->validated();
        $validated['system_settings_id'] = (string) Str::uuid();
        $setting = SystemSettings::create($validated);
        
        return response()->json([
            'message' => 'Setting created successfully',
            'data' => $setting
        ], 201);
    }

    public function show($id)
    {
        $setting = SystemSettings::find($id);

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found'
            ], 404);
        }

        return response()->json([
            'data' => $setting
        ]);
    }

    public function update(SystemSettingRequest $request, $id)
    {
        $setting = SystemSettings::find($id);

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found'
            ], 404);
        }

        $setting->update($request->validated());

        return response()->json([
            'message' => 'Setting updated successfully',
            'data' => $setting
        ]);
    }

    public function destroy($id)
    {
        $setting = SystemSettings::find($id);

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found'
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully'
        ]);
    }
}