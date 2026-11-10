<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReferenceDataController extends ApiController
{
    public function index(): JsonResponse
    {
        $locations = Location::query()->orderBy('area_name')->orderBy('name');
        if ($this->currentUser()->role !== 'admin') {
            $locations->where('is_active', true);
        }

        return response()->json([
            'success' => true,
            'categories' => Category::query()->orderBy('name')
                ->get(['category_id', 'name', 'description', 'severity_weight', 'recommended_team_id']),
            'teams' => Team::query()->orderBy('name')->get(['team_id', 'name']),
            'locations' => $locations
                ->get(['location_id', 'name', 'area_name', 'is_active']),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        return $this->save($request, $resource);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $model = $this->findModel($resource, $id);
        if (! $model) {
            return $this->error('Reference item not found.', 404);
        }

        return $this->save($request, $resource, $model);
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $model = $this->findModel($resource, $id);
        if (! $model) {
            return $this->error('Reference item not found.', 404);
        }
        if ($model->reports()->exists() || ($model instanceof Team && $model->categories()->exists())) {
            return $this->error('This item is referenced by reports. Deactivate it instead of deleting it.', 409);
        }

        $actor = $this->currentUser();
        DB::transaction(function () use ($model, $resource, $id, $actor): void {
            $model->delete();
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'delete_reference_data',
                'details' => ['resource' => $resource, 'id' => $id],
            ]);
        });

        return response()->json(['success' => true]);
    }

    private function save(Request $request, string $resource, ?Model $model = null): JsonResponse
    {
        $model ??= match ($resource) {
            'categories' => new Category,
            'teams' => new Team,
            'locations' => new Location,
            default => null,
        };
        if (! $model) {
            return $this->error('Unknown reference data type.', 404);
        }

        $id = $model->exists ? $model->getKey() : null;
        $rules = match ($resource) {
            'categories' => [
                'name' => ['required', 'string', 'max:100', Rule::unique('CATEGORY', 'name')->ignore($id, 'category_id')],
                'description' => ['nullable', 'string', 'max:1000'],
                'severity_weight' => ['required', 'integer', 'between:1,5'],
                'recommended_team_id' => ['nullable', 'integer', Rule::exists('TEAM', 'team_id')],
            ],
            'teams' => [
                'name' => ['required', 'string', 'max:100', Rule::unique('TEAM', 'name')->ignore($id, 'team_id')],
            ],
            'locations' => [
                'name' => ['required', 'string', 'max:150'],
                'area_name' => ['required', 'string', 'max:150'],
                'is_active' => ['required', 'boolean'],
            ],
        };
        if ($resource === 'locations') {
            $rules['name'][] = Rule::unique('LOCATION', 'name')
                ->where(fn ($query) => $query->where('area_name', $request->input('area_name')))
                ->ignore($id, 'location_id');
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $actor = $this->currentUser();
        DB::transaction(function () use ($model, $validator, $actor, $resource): void {
            $model->fill($validator->validated())->save();
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => $model->wasRecentlyCreated ? 'create_reference_data' : 'update_reference_data',
                'details' => [
                    'resource' => $resource,
                    'id' => $model->getKey(),
                    'values' => $validator->validated(),
                ],
            ]);
        });

        return response()->json(['success' => true]);
    }

    private function findModel(string $resource, int $id): ?Model
    {
        return match ($resource) {
            'categories' => Category::query()->find($id),
            'teams' => Team::query()->find($id),
            'locations' => Location::query()->find($id),
            default => null,
        };
    }
}
