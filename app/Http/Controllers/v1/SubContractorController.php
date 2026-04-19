<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SubContractorRequest;
use App\Http\Resources\v1\SubContractorResource;
use App\Models\SubContractors;
use App\Models\SubContractorsWorkers;
use App\Models\SubcontractorWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SubContractorController extends Controller
{
    public function index(): JsonResponse
    {
        $subcontractors = SubContractors::with([
            'siteSubcontractor.site',
            'workers',
        ])->latest()->get();

        return response()->json([
            'success' => true,
            'data' => SubContractorResource::collection($subcontractors)
        ]);
    }

    public function store(SubContractorRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['subcontractor_id'] = (string) Str::uuid();

        $worker = SubContractors::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Worker created successfully.',
            'data' => $worker
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $worker = SubContractors::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $worker
        ]);
    }

    public function update(SubContractorRequest $request, string $id): JsonResponse
    {
        $worker = SubContractors::findOrFail($id);

        $worker->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Worker updated successfully.',
            'data' => $worker
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $worker = SubContractors::findOrFail($id);

        $worker->delete();

        return response()->json([
            'success' => true,
            'message' => 'Worker deleted successfully.'
        ]);
    }
}
