<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLocationContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_states_returns_only_active_states_with_active_cities_count(): void
    {
        $activeState = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
            'status' => 'active',
        ]);

        $inactiveState = State::query()->create([
            'name' => 'Qom',
            'slug' => 'qom',
            'code' => 'QOM',
            'status' => 'inactive',
        ]);

        City::query()->create([
            'state_id' => $activeState->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
            'status' => 'active',
        ]);

        City::query()->create([
            'state_id' => $activeState->id,
            'name' => 'Rey',
            'slug' => 'rey',
            'code' => 'THR-2',
            'status' => 'inactive',
        ]);

        City::query()->create([
            'state_id' => $inactiveState->id,
            'name' => 'Qom',
            'slug' => 'qom-city',
            'code' => 'QOM-1',
            'status' => 'active',
        ]);

        $this->getJson('/api/home/states')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $activeState->id)
            ->assertJsonPath('0.cities_count', 1);
    }

    public function test_home_states_supports_q_search(): void
    {
        State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
            'status' => 'active',
        ]);

        State::query()->create([
            'name' => 'Alborz',
            'slug' => 'alborz',
            'code' => 'ALB',
            'status' => 'active',
        ]);

        $this->getJson('/api/home/states?q=teh')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'tehran');
    }

    public function test_home_cities_filters_by_state_and_active_status(): void
    {
        $tehran = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
            'status' => 'active',
        ]);

        $alborz = State::query()->create([
            'name' => 'Alborz',
            'slug' => 'alborz',
            'code' => 'ALB',
            'status' => 'active',
        ]);

        $inactiveState = State::query()->create([
            'name' => 'Qom',
            'slug' => 'qom',
            'code' => 'QOM',
            'status' => 'inactive',
        ]);

        $tehranCity = City::query()->create([
            'state_id' => $tehran->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
            'status' => 'active',
        ]);

        City::query()->create([
            'state_id' => $tehran->id,
            'name' => 'Rey',
            'slug' => 'rey',
            'code' => 'THR-2',
            'status' => 'inactive',
        ]);

        City::query()->create([
            'state_id' => $alborz->id,
            'name' => 'Karaj',
            'slug' => 'karaj',
            'code' => 'ALB-1',
            'status' => 'active',
        ]);

        City::query()->create([
            'state_id' => $inactiveState->id,
            'name' => 'Qom',
            'slug' => 'qom-city',
            'code' => 'QOM-1',
            'status' => 'active',
        ]);

        $this->getJson("/api/home/cities?state_id={$tehran->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $tehranCity->id)
            ->assertJsonPath('0.state_id', $tehran->id);
    }

    public function test_home_cities_supports_q_search(): void
    {
        $state = State::query()->create([
            'name' => 'Tehran',
            'slug' => 'tehran',
            'code' => 'THR',
            'status' => 'active',
        ]);

        City::query()->create([
            'state_id' => $state->id,
            'name' => 'Tehran',
            'slug' => 'tehran-city',
            'code' => 'THR-1',
            'status' => 'active',
        ]);

        City::query()->create([
            'state_id' => $state->id,
            'name' => 'Damavand',
            'slug' => 'damavand',
            'code' => 'THR-2',
            'status' => 'active',
        ]);

        $this->getJson('/api/home/cities?q=dam')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'damavand');
    }
}
