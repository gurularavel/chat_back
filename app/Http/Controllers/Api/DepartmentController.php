<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::with('members:id')->orderBy('sort')->orderBy('id')->get());
    }

    public function store(Request $request): DepartmentResource
    {
        $data = $this->validated($request);
        $department = DB::transaction(function () use ($data) {
            $department = Department::create(Arr::except($data, 'user_ids'));
            $department->members()->sync($data['user_ids'] ?? []);

            return $department;
        });
        AuditLog::record('department.created', $department);

        return new DepartmentResource($department->load('members:id'));
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $data = $this->validated($request, creating: false);
        DB::transaction(function () use ($department, $data) {
            $department->update(Arr::except($data, 'user_ids'));
            if (array_key_exists('user_ids', $data)) {
                $department->members()->sync($data['user_ids'] ?? []);
            }
        });
        AuditLog::record('department.updated', $department);

        return new DepartmentResource($department->load('members:id'));
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();
        AuditLog::record('department.deleted', $department);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'nullable', 'array'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        if (! empty($data['user_ids'])) {
            $members = $workspace->members()->whereIn('users.id', $data['user_ids'])->pluck('users.id');
            abort_if($members->count() !== count($data['user_ids']), 422, 'Unknown workspace member.');
        }

        return $data;
    }
}
