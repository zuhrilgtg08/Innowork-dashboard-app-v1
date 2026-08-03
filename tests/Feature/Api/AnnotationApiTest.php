<?php

namespace Tests\Feature\Api;

use App\Models\Annotation;
use App\Models\Detection;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnotationApiTest extends TestCase
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

    public function test_guest_cannot_read_the_queue(): void
    {
        $this->getJson('/api/annotations/queue')->assertStatus(401);
    }

    public function test_it_lists_unlabelled_detections_with_a_frame(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);
        // Already annotated — must not show up in the queue.
        $labelled = Detection::factory()->create(['status' => 'passed', 'frame_path' => 'frames/b.jpg']);
        Annotation::create([
            'detection_id' => $labelled->id,
            'product_id' => $labelled->product_id,
            'image_path' => 'frames/b.jpg',
            'label' => 'passed',
            'status' => 'approved',
            'source' => 'ai',
        ]);
        // No frame and not a failure/workflow state — not review-worthy.
        Detection::factory()->create(['status' => 'passed', 'frame_path' => null]);

        $response = $this->getJson('/api/annotations/queue')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($detection->id, $ids);
        $this->assertNotContains($labelled->id, $ids);
    }

    public function test_queue_filters_by_status(): void
    {
        $this->actingAsRole();

        Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);
        Detection::factory()->create(['status' => 'scratched', 'frame_path' => 'frames/b.jpg']);

        $response = $this->getJson('/api/annotations/queue?status=damaged')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('damaged', $response->json('data.0.status'));
    }

    public function test_stats_counts_pending_and_labelled(): void
    {
        $this->actingAsRole();

        $pending = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);
        $labelled = Detection::factory()->create(['status' => 'passed', 'frame_path' => 'frames/b.jpg']);
        Annotation::create([
            'detection_id' => $labelled->id,
            'product_id' => $labelled->product_id,
            'image_path' => 'frames/b.jpg',
            'label' => 'passed',
            'status' => 'approved',
            'source' => 'ai',
        ]);

        $this->getJson('/api/annotations/stats')
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.labelled', 1);
    }

    public function test_it_approves_the_ai_label_as_ground_truth(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Label disetujui & masuk dataset.');

        $this->assertDatabaseHas('annotations', [
            'detection_id' => $detection->id,
            'label' => 'damaged',
            'status' => 'approved',
            'source' => 'ai',
        ]);
    }

    public function test_it_rejects_approving_a_non_trainable_status(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'returned', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('annotations', ['detection_id' => $detection->id]);
    }

    public function test_it_rejects_approving_without_an_image(): void
    {
        $this->actingAsRole();

        $product = Product::factory()->create(['image' => null]);
        $detection = Detection::factory()->create([
            'status' => 'damaged',
            'frame_path' => null,
            'product_id' => $product->id,
        ]);

        $this->postJson("/api/annotations/{$detection->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_relabels_to_a_different_trainable_class(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/relabel", ['label' => 'scratched'])
            ->assertOk();

        $this->assertDatabaseHas('annotations', [
            'detection_id' => $detection->id,
            'label' => 'scratched',
            'source' => 'human',
        ]);
    }

    public function test_relabel_rejects_a_non_trainable_class(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/relabel", ['label' => 'returned'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');
    }

    public function test_relabel_updates_an_existing_annotation_instead_of_duplicating(): void
    {
        $this->actingAsRole();

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/approve")->assertOk();
        $this->postJson("/api/annotations/{$detection->id}/relabel", ['label' => 'scratched'])->assertOk();

        $this->assertSame(1, Annotation::where('detection_id', $detection->id)->count());
        $this->assertSame('scratched', Annotation::where('detection_id', $detection->id)->first()->label);
    }

    public function test_a_role_without_annotation_access_is_denied(): void
    {
        $user = $this->actingAsRole('viewer');

        $this->getJson('/api/annotations/queue')->assertStatus(403);
    }

    public function test_a_read_only_role_cannot_approve(): void
    {
        // supervisor_qc has 'w' by default, so temporarily drop it to 'r'.
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)->where('module', 'Annotation')->update(['access' => 'r']);

        $detection = Detection::factory()->create(['status' => 'damaged', 'frame_path' => 'frames/a.jpg']);

        $this->postJson("/api/annotations/{$detection->id}/approve")->assertStatus(403);
    }
}
