<?php

namespace Tests\Feature\Hardware;

use App\Models\User;
use Database\Seeders\CpuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpuTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_search_returns_401(): void
    {
        $this->getJson('/api/hardware/cpus')->assertStatus(401);
    }

    public function test_authenticated_empty_search_returns_top_20_by_single_thread_mark_desc(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus');

        $response->assertOk();
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertLessThanOrEqual(20, count($data));
        $this->assertGreaterThan(0, count($data));

        $marks = array_column($data, 'single_thread_mark');
        $sorted = $marks;
        rsort($sorted);
        $this->assertSame($sorted, $marks, 'Results must be sorted single_thread_mark DESC');

        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('manufacturer', $first);
        $this->assertArrayHasKey('single_thread_mark', $first);
        $this->assertArrayHasKey('tier', $first);
        $this->assertArrayHasKey('released_year', $first);
    }

    public function test_search_filters_by_case_insensitive_substring_match(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus?search=ryzen');

        $response->assertOk();
        $names = array_column($response->json(), 'name');

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertStringContainsStringIgnoringCase('ryzen', $name);
        }
    }

    public function test_search_containing_wildcard_characters_is_escaped(): void
    {
        $this->seed(CpuSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/hardware/cpus?search=%25');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_search_over_100_chars_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/hardware/cpus?search='.str_repeat('a', 101))
            ->assertStatus(422);
    }
}
