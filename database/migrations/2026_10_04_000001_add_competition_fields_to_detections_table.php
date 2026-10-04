use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('detections', function (Builder $table): void {
            $table->enum('color', ['HIJAU', 'KUNING', 'MERAH'])->nullable()->after('status');
            $table->decimal('center_x', 10, 2)->nullable()->after('color');
            $table->decimal('center_y', 10, 2)->nullable()->after('center_x');
            $table->boolean('in_pick_zone')->default(0)->after('center_y');
            $table->boolean('processed')->default(0)->after('in_pick_zone');
            $table->uuid('competition_event_id')->nullable()->after('processed');
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Builder $table): void {
            $table->dropColumn(['color', 'center_x', 'center_y', 'in_pick_zone', 'processed', 'competition_event_id']);
        });
    }
};