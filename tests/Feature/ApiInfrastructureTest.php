<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiInfrastructureTest extends TestCase
{
    public function test_health_and_lookups(): void
    {
        $this->createPipeline();

        $this->getJson(self::API.'/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'ok')
            ->assertJsonPath('data.timezone', 'Africa/Algiers');

        $this->getJson(self::API.'/lookups')
            ->assertOk()
            ->assertJsonPath('data.stage_types', ['open', 'won', 'lost'])
            ->assertJsonPath('data.activity_types', ['call', 'meeting', 'message', 'other'])
            ->assertJsonPath('data.follow_up_statuses', ['pending', 'completed', 'cancelled'])
            ->assertJsonCount(4, 'data.sales_stages')
            ->assertJsonCount(2, 'data.loss_reasons');
    }

    public function test_requests_from_unauthorized_networks_are_refused(): void
    {
        config(['crm.allowed_networks' => ['192.168.1.0/24']]);

        $this->getJson(self::API.'/health', ['REMOTE_ADDR' => '127.0.0.1'])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson(self::API.'/health')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'NETWORK_NOT_ALLOWED');
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.25'])->getJson(self::API.'/health')->assertOk();
    }

    public function test_unknown_routes_and_non_numeric_ids_return_json_404(): void
    {
        $this->getJson(self::API.'/nope')->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->getJson(self::API.'/prospects/abc')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_html_clients_also_receive_json_errors(): void
    {
        $this->get(self::API.'/prospects/999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }
}
