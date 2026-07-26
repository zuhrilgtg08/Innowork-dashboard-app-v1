<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_it_lists_products_newest_first(): void
    {
        $older = Product::factory()->create(['created_at' => now()->subDay()]);
        $newer = Product::factory()->create(['created_at' => now()]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'code', 'name', 'sku', 'status', 'status_label', 'stock', 'image_url', 'qr_url', 'public_url']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_it_filters_by_search_status_and_category(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['name' => 'Susu Segar', 'status' => 'active', 'category_id' => $category->id]);
        Product::factory()->create(['name' => 'Keju Tua', 'status' => 'archived', 'category_id' => null]);

        $this->actingAsAdmin();

        $this->assertSame(1, $this->getJson('/api/products?search=Susu')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/products?status=archived')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/products?category_id='.$category->id)->assertOk()->json('meta.total'));
    }

    public function test_it_rejects_an_unknown_status_filter(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/products?status=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_creates_a_product_and_generates_code_sku_and_qr(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/products', [
            'name' => 'Susu Segar',
            'status' => 'active',
            'stock' => 12,
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^PRD-\d{5}$/', $response->json('data.code'));
        // "Susu Segar" → initials SS.
        $this->assertStringStartsWith('SS-', $response->json('data.sku'));

        $product = Product::first();
        $this->assertNotEmpty($product->qr_token);
        $this->assertNotEmpty($product->qr_path);
        Storage::disk('public')->assertExists($product->qr_path);
    }

    public function test_it_validates_on_create(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/products', ['stock' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status', 'stock']);
    }

    public function test_it_stores_an_uploaded_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $this->post('/api/products', [
            'name' => 'Keju',
            'status' => 'active',
            'stock' => 1,
            'image' => UploadedFile::fake()->image('keju.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Storage::disk('public')->assertExists(Product::first()->image);
    }

    public function test_it_updates_a_product_without_reissuing_code_or_sku(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['name' => 'Lama', 'status' => 'active']);
        $originalCode = $product->code;
        $originalSku = $product->sku;

        $this->actingAsAdmin();

        $this->putJson('/api/products/'.$product->id, [
            'name' => 'Baru',
            'status' => 'inactive',
            'stock' => 5,
        ])->assertOk()->assertJsonPath('data.name', 'Baru');

        $product->refresh();
        $this->assertSame($originalCode, $product->code);
        $this->assertSame($originalSku, $product->sku);
        $this->assertSame('inactive', $product->status);
    }

    public function test_it_deletes_a_product_and_its_files(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $product->regenerateQr();
        $product->update(['image' => UploadedFile::fake()->image('x.jpg')->store('products', 'public')]);

        $qrPath = $product->fresh()->qr_path;
        $imagePath = $product->fresh()->image;

        $this->actingAsAdmin();

        $this->deleteJson('/api/products/'.$product->id)->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($qrPath);
        Storage::disk('public')->assertMissing($imagePath);
    }

    public function test_it_returns_404_for_a_missing_product(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/products/999999')->assertStatus(404);
    }

    public function test_guest_cannot_access_products(): void
    {
        $this->getJson('/api/products')->assertStatus(401);
    }
}
