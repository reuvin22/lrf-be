<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\SubContractorWorkerRequest;
use App\Models\SubContractorsWorkers;
use App\Models\SubContractorWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubContractorWorkersController extends Controller
{
    public function index()
    {
        $workers = SubContractorsWorkers::all();
        return response()->json(['data' => $workers]);
    }

    public function store(SubContractorWorkerRequest $request)
    {
        $data = $request->validated();
        $data['worker_id'] = Str::uuid();
        $worker = SubContractorsWorkers::create($data);

        return response()->json([
            'message' => 'Worker created successfully',
            'data' => $worker,
        ], 201);
    }

    public function show($id)
    {
        $worker = SubContractorsWorkers::find($id);

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        return response()->json(['data' => $worker]);
    }

    public function update(SubContractorWorkerRequest $request, $id)
    {
        $worker = SubContractorsWorkers::find($id);

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $validated = $request->validated();

        $worker->update($validated);

        return response()->json([
            'message' => 'Worker updated successfully',
            'data' => $worker,
        ]);
    }

    public function destroy($id)
    {
        $worker = SubContractorsWorkers::find($id);

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $worker->delete();

        return response()->json(['message' => 'Worker deleted successfully']);
    }
}
