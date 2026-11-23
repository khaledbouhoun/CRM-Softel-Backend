<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\EmployeeSession;
use App\Models\Prospect;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    private function heartbeat(string $id = 'sara@PC-1', string $user = 'sara'): void
    {
        $this->postJson(self::API.'/system/heartbeat', ['employee_id' => $id, 'username' => $user, 'device_name' => 'PC-1'])->assertOk();
    }

    public function test_new_workstations_default_to_sales_and_available(): void
    {
        $this->heartbeat();

        $row = $this->getJson(self::API.'/system/employees')->assertOk()->json('data.employees.0');

        $this->assertSame('sales', $row['role']);
        $this->assertSame('available', $row['presence_status']);
        $this->assertNull($row['custom_name']);
        $this->assertSame(['activities_count' => 0, 'interventions_count' => 0, 'appointments_count' => 0], $row['today_stats']);
    }

    public function test_today_stats_count_only_that_employees_work_done_today(): void
    {
        $this->heartbeat();
        $this->heartbeat('ali@PC-2', 'ali');
        $prospect = Prospect::factory()->create();

        $this->postJson(self::API."/prospects/{$prospect->id}/activities", ['type' => 'call', 'description' => 'Called the client'], ['X-Employee-Name' => 'sara'])->assertCreated();
        $this->postJson(self::API."/prospects/{$prospect->id}/activities", ['type' => 'call', 'description' => 'Called again'], ['X-Employee-Name' => 'sara'])->assertCreated();
        $this->postJson(self::API."/prospects/{$prospect->id}/activities", ['type' => 'call', 'description' => 'Ali call'], ['X-Employee-Name' => 'ali'])->assertCreated();
        Activity::factory()->create(['created_by' => 'sara', 'created_at' => now()->subDays(2)]);

        $data = $this->getJson(self::API.'/system/employees')->assertOk()->json('data');
        $byUser = collect($data['employees'])->keyBy('username');

        $this->assertSame(2, $byUser['sara']['today_stats']['activities_count']);
        $this->assertSame(1, $byUser['ali']['today_stats']['activities_count']);
        $this->assertSame(3, $data['summary']['team_actions_today']);
    }

    public function test_nickname_and_role_can_be_updated_and_cleared(): void
    {
        $this->heartbeat();

        $this->putJson(self::API.'/system/employees/sara@PC-1', ['custom_name' => '  Reception Desk ', 'role' => 'support'])
            ->assertOk()->assertJsonPath('data.custom_name', 'Reception Desk')->assertJsonPath('data.role', 'support');

        $this->putJson(self::API.'/system/employees/sara@PC-1', ['custom_name' => ''])
            ->assertOk()->assertJsonPath('data.custom_name', null)->assertJsonPath('data.role', 'support');

        $this->putJson(self::API.'/system/employees/sara@PC-1', ['role' => 'janitor'])->assertUnprocessable();
        $this->putJson(self::API.'/system/employees/ghost@PC', ['role' => 'sales'])->assertNotFound();
    }

    public function test_presence_is_updated_and_validated(): void
    {
        $this->heartbeat();

        $this->postJson(self::API.'/system/employees/presence', ['employee_id' => 'sara@PC-1', 'presence_status' => 'busy', 'status_note' => 'Demo with a client'])
            ->assertOk()->assertJsonPath('data.presence_status', 'busy');
        $this->assertSame('Demo with a client', EmployeeSession::first()->status_note);

        $this->postJson(self::API.'/system/employees/presence', ['employee_id' => 'sara@PC-1', 'presence_status' => 'break'])->assertOk();
        $this->assertNull(EmployeeSession::first()->status_note);

        $this->postJson(self::API.'/system/employees/presence', ['employee_id' => 'sara@PC-1', 'presence_status' => 'sleeping'])->assertUnprocessable();
        $this->postJson(self::API.'/system/employees/presence', ['employee_id' => 'ghost', 'presence_status' => 'busy'])->assertNotFound();
    }

    public function test_heartbeat_keeps_nickname_role_and_presence(): void
    {
        $this->heartbeat();
        $this->putJson(self::API.'/system/employees/sara@PC-1', ['custom_name' => 'Sales 1', 'role' => 'training'])->assertOk();
        $this->postJson(self::API.'/system/employees/presence', ['employee_id' => 'sara@PC-1', 'presence_status' => 'away'])->assertOk();

        $this->heartbeat();

        $s = EmployeeSession::first();
        $this->assertSame(['Sales 1', 'training', 'away'], [$s->custom_name, $s->role, $s->presence_status]);
    }
}
