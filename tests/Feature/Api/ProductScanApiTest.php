<?php

namespace Tests\Feature\Api;

use App\Models\Detection;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductScanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRolePermissions(): void
    {
        foreach (RolePermission::defaults() as $role => $modules) {
            foreach ($modules as $module => $access) {
                RolePermission::updateOrCreate(
                    ['role' => $role, 'module' => $module],
                    ['access' => $access],
                );
            }
        }
    }

    private function actingAsRole(string $role = 'admin'): User
    {
        $this->seedRolePermissions();
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_scan(): void
    {
        $this->postJson('/api/products/scan', ['qr_value' => 'x'])->assertStatus(401);
    }

    public function test_it_resolves_a_product_from_the_public_scan_url(): void
    {
        $this->actingAsRole();
        $product = Product::factory()->create();

        $this->postJson('/api/products/scan', ['qr_value' => $product->qrPayload()])
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.product.code', $product->code);
    }

    public function test_it_resolves_a_bare_token(): void
    {
        $this->actingAsRole();
        $product = Product::factory()->create();

        $this->postJson('/api/products/scan', ['qr_value' => $product->qr_token])
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_it_resolves_the_legacy_payload_format(): void
    {
        $this->actingAsRole();
        $product = Product::factory()->create();

        // Codes printed before the switch to public URLs must still scan.
        $legacy = "SORTVISION|{$product->code}|{$product->sku}";

        $this->postJson('/api/products/scan', ['qr_value' => $legacy])
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_it_returns_the_latest_qc_verdict_with_the_product(): void
    {
        $this->actingAsRole();
        $product = Product::factory()->create();

        Detection::factory()->create([
            'product_id' => $product->id,
            'status' => 'passed',
            'detected_at' => now()->subHour(),
        ]);
        $newest = Detection::factory()->create([
            'product_id' => $product->id,
            'status' => 'damaged',
            'detected_at' => now(),
        ]);

        $this->postJson('/api/products/scan', ['qr_value' => $product->qrPayload()])
            ->assertOk()
            ->assertJsonPath('data.latest_detection.id', $newest->id)
            ->assertJsonPath('data.latest_detection.status', 'damaged')
            ->assertJsonPath('data.latest_detection.status_label', 'Damaged');
    }

    public function test_a_product_never_scanned_yet_has_a_null_verdict(): void
    {
        $this->actingAsRole();
        $product = Product::factory()->create();

        $this->postJson('/api/products/scan', ['qr_value' => $product->qrPayload()])
            ->assertOk()
            ->assertJsonPath('data.latest_detection', null);
    }

    public function test_an_unknown_code_returns_404(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/products/scan', ['qr_value' => 'https://example.test/p/nope'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'QR tidak dikenali. Produk tidak ditemukan.');
    }

    public function test_it_requires_a_qr_value(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/products/scan', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr_value');
    }

    public function test_a_role_without_product_access_cannot_scan(): void
    {
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)
            ->where('module', 'Product')
            ->update(['access' => '-']);

        $this->postJson('/api/products/scan', ['qr_value' => 'x'])->assertStatus(403);
    }

    public function test_the_scan_route_is_not_swallowed_by_the_product_wildcard(): void
    {
        // POST products/scan must hit scan(), not be parsed as an id.
        $this->actingAsRole();
        $product = Product::factory()->create();

        $this->postJson('/api/products/scan', ['qr_value' => $product->qr_token])
            ->assertOk()
            ->assertJsonStructure(['data' => ['product', 'latest_detection']]);
    }
}
