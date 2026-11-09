<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Prospect;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProspectService
{
    private const PROFILE_FIELDS = [
        'name', 'contact_person', 'phone', 'email', 'address', 'city',
        'business_activity', 'source', 'source_other', 'previous_software', 'notes',
    ];

    public function __construct(private readonly StageTransitionService $transitions) {}

    /**
     * Creates the prospect, its product links and the initial stage history
     * entry (from_stage_id = null) atomically.
     */
    public function create(array $data): Prospect
    {
        return DB::transaction(function () use ($data) {
            $stage = $this->transitions->resolveTargetStage(
                isset($data['sales_stage_id']) ? (int) $data['sales_stage_id'] : null
            );
            [$reason, $note] = $this->transitions->resolveLossInformation($stage, $data);
            $productIds = $this->assignableProductIds($data['product_ids'] ?? []);

            $prospect = new Prospect(Arr::only($data, self::PROFILE_FIELDS));
            $this->normaliseSource($prospect);
            $this->transitions->applyStage($prospect, $stage, $reason, $note);
            $prospect->save();

            $prospect->products()->sync($productIds);
            $this->transitions->recordHistory($prospect, null, $stage, $reason, $note);

            return $prospect->load(Prospect::defaultRelations());
        });
    }

    /**
     * Updates profile fields; when product_ids is present the product list is
     * replaced (missing ids are detached, new ones attached, no duplicates).
     */
    public function update(Prospect $prospect, array $data): Prospect
    {
        return DB::transaction(function () use ($prospect, $data) {
            $prospect->fill(Arr::only($data, self::PROFILE_FIELDS));
            $this->normaliseSource($prospect);

            if (array_key_exists('product_ids', $data)) {
                $current = $prospect->products()->pluck('products.id')->all();
                $productIds = $this->assignableProductIds($data['product_ids'] ?? [], $current);

                $changes = $prospect->products()->sync($productIds);
                if (array_filter($changes)) {
                    $prospect->touch();
                }
            }

            $prospect->save();

            return $prospect->load(Prospect::defaultRelations());
        });
    }

    /** source_other only makes sense for source = "other"; blank text is stored as null. */
    private function normaliseSource(Prospect $prospect): void
    {
        foreach (['business_activity', 'source', 'source_other', 'previous_software'] as $field) {
            if (is_string($prospect->{$field}) && trim($prospect->{$field}) === '') {
                $prospect->{$field} = null;
            }
        }

        if ($prospect->source !== 'other') {
            $prospect->source_other = null;
        }
    }

    /** Soft delete: activities, follow-ups and stage history are kept. */
    public function delete(Prospect $prospect): void
    {
        $prospect->delete();
    }

    /**
     * New associations require an active product. Products already linked to
     * the prospect may stay linked even if they were deactivated since.
     *
     * @param  array<int|string>  $productIds
     * @param  array<int>  $alreadyLinked
     * @return array<int>
     */
    private function assignableProductIds(array $productIds, array $alreadyLinked = []): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $errors = [];

        foreach ($productIds as $index => $id) {
            $product = $products->get($id);

            if (! $product) {
                $errors["product_ids.{$index}"] = ['The selected product does not exist.'];
            } elseif (! $product->is_active && ! in_array($id, $alreadyLinked, true)) {
                $errors["product_ids.{$index}"] = ["The product \"{$product->name}\" is inactive and cannot be associated."];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $productIds;
    }
}
