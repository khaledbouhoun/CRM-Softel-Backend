<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentKind;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConfigEntityResource;
use App\Http\Responses\ApiResponse;
use App\Models\AppointmentType;
use App\Models\Holiday;
use App\Models\InterventionPriority;
use App\Models\InterventionStatus;
use App\Models\InterventionStatusTransition;
use App\Models\InterventionType;
use App\Models\MessageTemplate;
use App\Models\ProductResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One controller for every settings list that follows the same rule: create,
 * edit and DEACTIVATE (never delete), because history references these rows.
 */
class ConfigEntityController extends Controller
{
    /** @return array{model: class-string<Model>, rules: array<string, array>, order: list<string>} */
    private function definition(string $entity): array
    {
        $color = ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'];
        $common = ['is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'between:0,65535']];

        return match ($entity) {
            'appointment-types' => ['model' => AppointmentType::class, 'order' => ['sort_order', 'id'], 'rules' => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:appointment_types,code'],
                'name' => ['required', 'string', 'max:120'], 'group' => ['required', 'in:demo,meeting,training,accompaniment'],
                'default_duration_minutes' => ['sometimes', 'integer', 'between:5,1440'], 'color' => $color,
                'customer_bookable' => ['sometimes', 'boolean'], ...$common]],
            'holidays' => ['model' => Holiday::class, 'order' => ['date'], 'rules' => [
                'date' => ['required', 'date', 'unique:holidays,date'], 'name' => ['required', 'string', 'max:150'], 'is_active' => $common['is_active']]],
            'intervention-types' => ['model' => InterventionType::class, 'order' => ['sort_order', 'id'], 'rules' => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:intervention_types,code'],
                'name' => ['required', 'string', 'max:120'], 'source' => ['required', 'in:customer,employee,both'], 'color' => $color, ...$common]],
            'intervention-statuses' => ['model' => InterventionStatus::class, 'order' => ['sort_order', 'id'], 'rules' => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:intervention_statuses,code'],
                'name' => ['required', 'string', 'max:120'], 'customer_label' => ['required', 'string', 'max:120'], 'color' => $color,
                'is_initial' => ['sometimes', 'boolean'], 'is_closed' => ['sometimes', 'boolean'],
                'awaits_customer' => ['sometimes', 'boolean'], 'is_delivered' => ['sometimes', 'boolean'], ...$common]],
            'intervention-transitions' => ['model' => InterventionStatusTransition::class, 'order' => ['from_status_id', 'to_status_id'], 'rules' => [
                'from_status_id' => ['required', 'integer', 'exists:intervention_statuses,id'],
                'to_status_id' => ['required', 'integer', 'different:from_status_id', 'exists:intervention_statuses,id'],
                'customer_allowed' => ['sometimes', 'boolean'], 'is_active' => $common['is_active']]],
            'intervention-priorities' => ['model' => InterventionPriority::class, 'order' => ['sort_order', 'id'], 'rules' => [
                'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:intervention_priorities,code'],
                'name' => ['required', 'string', 'max:120'], 'color' => $color,
                'response_target_hours' => ['sometimes', 'nullable', 'integer', 'min:1'], ...$common]],
            'message-templates' => ['model' => MessageTemplate::class, 'order' => ['code', 'locale'], 'rules' => [
                'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'], 'locale' => ['required', 'in:ar,en,fr'],
                'body' => ['required', 'string', 'max:2000'], 'is_active' => $common['is_active']]],
            'product-resources' => ['model' => ProductResource::class, 'order' => ['product_id', 'sort_order', 'id'], 'rules' => [
                'product_id' => ['required', 'integer', 'exists:products,id'], 'title' => ['required', 'string', 'max:200'],
                'kind' => ['sometimes', 'in:pdf,video,link,other'], 'url' => ['required', 'url:http,https', 'max:500'],
                'customer_visible' => ['sometimes', 'boolean'], 'is_training_material' => ['sometimes', 'boolean'], ...$common]],
            default => abort(404),
        };
    }

    /** Appointment types are the fixed list in AppointmentKind: they cannot be created or deactivated. */
    private function guardFixedTypes(string $entity): void
    {
        if ($entity === 'appointment-types') {
            throw new BusinessRuleException(
                'Appointment types are fixed ('.implode(', ', AppointmentKind::values()).') and cannot be created or removed.',
                'APPOINTMENT_TYPES_FIXED', [], 422,
            );
        }
    }

    /** Only presentation settings (duration, color, bookable, order) may change; identity and activation may not. */
    private function guardFixedTypeUpdate(Request $request, Model $row): void
    {
        $errors = [];
        foreach (['code', 'name', 'group', 'is_active'] as $field) {
            if ($request->has($field) && (string) $request->input($field) !== (string) $row->{$field} && $request->input($field) !== (bool) $row->{$field}) {
                $errors[$field] = ["The {$field} of a fixed appointment type cannot be changed."];
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException('Appointment types are fixed.', 'APPOINTMENT_TYPES_FIXED', $errors, 422);
        }
    }

    public function index(string $entity): JsonResponse
    {
        $def = $this->definition($entity);
        $query = $def['model']::query();
        foreach ($def['order'] as $column) {
            $query->orderBy($column);
        }

        return ApiResponse::collection($query->get(), ConfigEntityResource::class, 'Settings retrieved successfully.');
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $this->guardFixedTypes($entity);
        $def = $this->definition($entity);
        $data = $request->validate($def['rules']);

        if ($entity === 'intervention-transitions') {
            $existing = InterventionStatusTransition::query()->where('from_status_id', $data['from_status_id'])->where('to_status_id', $data['to_status_id'])->first();
            if ($existing) {
                $existing->update($data + ['is_active' => true]);

                return ApiResponse::success(new ConfigEntityResource($existing), 'Transition enabled.');
            }
        }

        return ApiResponse::created(new ConfigEntityResource($def['model']::query()->create($data)), 'Saved.');
    }

    public function update(Request $request, string $entity, int $id): JsonResponse
    {
        $def = $this->definition($entity);
        $row = $def['model']::query()->findOrFail($id);
        if ($entity === 'appointment-types') {
            $this->guardFixedTypeUpdate($request, $row);
        }
        $rules = collect($def['rules'])->map(function (array $rule, string $field) use ($row) {
            $rule = array_values(array_filter($rule, fn ($r) => $r !== 'required'));
            $rule = array_map(fn ($r) => is_string($r) && str_starts_with($r, 'unique:') ? Rule::unique($row->getTable(), $r === 'unique:holidays,date' ? 'date' : explode(',', $r)[1])->ignore($row->id) : $r, $rule);

            return ['sometimes', ...$rule];
        })->all();

        $row->update($request->validate($rules));

        return ApiResponse::success(new ConfigEntityResource($row->refresh()), 'Saved.');
    }

    /** Deactivates: referenced configuration is preserved. */
    public function destroy(string $entity, int $id): JsonResponse
    {
        $this->guardFixedTypes($entity);
        $row = $this->definition($entity)['model']::query()->findOrFail($id);
        $row->update(['is_active' => false]);

        return ApiResponse::success(new ConfigEntityResource($row->refresh()), 'Deactivated (kept for history).');
    }
}
