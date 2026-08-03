<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    public function test_it_lists_categories_with_a_product_count(): void
    {
        Category::factory()->create(['name' => 'Dairy']);
        $this->actingAsAdmin();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'is_active', 'sort_order', 'products_count']], 'meta']);
    }

    public function test_it_filters_by_search_and_active_flag(): void
    {
        Category::factory()->create(['name' => 'Dairy', 'is_active' => true]);
        Category::factory()->create(['name' => 'Frozen', 'is_active' => false]);
        $this->actingAsAdmin();

        $this->assertSame(1, $this->getJson('/api/categories?search=Dairy')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/categories?is_active=0')->assertOk()->json('meta.total'));
    }

    public function test_it_creates_a_category_and_derives_the_slug(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'Food & Beverage',
            'sort_order' => 3,
        ])->assertCreated()->assertJsonPath('data.slug', 'food-beverage');
    }

    public function test_it_enforces_a_unique_name_except_for_itself(): void
    {
        $existing = Category::factory()->create(['name' => 'Dairy']);
        $this->actingAsAdmin();

        $this->postJson('/api/categories', ['name' => 'Dairy', 'sort_order' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Editing the same row keeping its name must pass.
        $this->putJson('/api/categories/'.$existing->id, ['name' => 'Dairy', 'sort_order' => 1])
            ->assertOk();
    }

    public function test_it_deletes_a_category(): void
    {
        $category = Category::factory()->create();
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/'.$category->id)->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
