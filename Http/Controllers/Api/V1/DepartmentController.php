<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Department;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $departments = Department::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        return $this->successResponse([
            'departments' => $departments->items(),
            'query' => ['q' => $q],
        ], 'OK', 200, [
            'pagination' => $this->paginationMeta($departments),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:inventory_departments'],
            'code' => ['nullable', 'string', 'max:100', 'unique:inventory_departments'],
        ]);

        $department = Department::create($data);

        return $this->successResponse([
            'department' => $this->mapDepartment($department),
        ], 'Department created.', 201);
    }

    public function show(Request $request, Department $department)
    {
        return $this->successResponse([
            'department' => $this->mapDepartment($department),
            'user_count' => $department->users()->count(),
        ]);
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
        ]);

        $fillable = [];
        if (isset($data['name'])) {
            $fillable['name'] = $data['name'];
        }
        if (isset($data['code'])) {
            $fillable['code'] = $data['code'];
        }

        if (!$fillable) {
            return $this->successResponse([
                'department' => $this->mapDepartment($department),
            ], 'No changes made.');
        }

        $department->update($fillable);

        return $this->successResponse([
            'department' => $this->mapDepartment($department),
        ], 'Department updated.');
    }

    public function destroy(Request $request, Department $department)
    {
        // Check if department has users
        if ($department->users()->exists()) {
            return $this->errorResponse(
                'Cannot delete department with active users. Reassign users first.',
                422
            );
        }

        $department->delete();

        return $this->successResponse([], 'Department deleted.');
    }

    private function mapDepartment(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => (string) $department->name,
            'code' => (string) ($department->code ?? ''),
            'created_at' => optional($department->created_at)->toIso8601String(),
            'updated_at' => optional($department->updated_at)->toIso8601String(),
        ];
    }
}
