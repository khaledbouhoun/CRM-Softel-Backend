<?php

namespace Tests;

use App\Models\LossReason;
use App\Models\SalesStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const API = '/api/v1';

    protected SalesStage $newStage;

    protected SalesStage $demoStage;

    protected SalesStage $wonStage;

    protected SalesStage $lostStage;

    protected LossReason $priceReason;

    protected LossReason $otherReason;

    /**
     * Test pipeline: two open stages, one won, one lost; one plain loss reason
     * and one that requires a note.
     */
    protected function createPipeline(): void
    {
        $this->newStage = SalesStage::factory()->open()->create(['name' => 'New', 'sort_order' => 1]);
        $this->demoStage = SalesStage::factory()->open()->create(['name' => 'Demo', 'sort_order' => 2]);
        $this->wonStage = SalesStage::factory()->won()->create(['name' => 'Won', 'sort_order' => 3]);
        $this->lostStage = SalesStage::factory()->lost()->create(['name' => 'Lost', 'sort_order' => 4]);

        $this->priceReason = LossReason::factory()->create(['name' => 'Too expensive', 'sort_order' => 1]);
        $this->otherReason = LossReason::factory()->requiresNote()->create(['name' => 'Other', 'sort_order' => 2]);
    }

    /** Freeze the clock at a local business time (application timezone). */
    protected function freezeAt(string $localDateTime): Carbon
    {
        $now = Carbon::parse($localDateTime, config('app.timezone'));
        $this->travelTo($now);

        return $now;
    }
}
