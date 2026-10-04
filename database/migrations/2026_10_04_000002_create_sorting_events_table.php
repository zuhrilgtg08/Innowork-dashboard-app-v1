use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sorting_events', function (Builder $table): void {
            $table->id();
            $table->char('event_uuid', 36)->unique();
            $table->foreignId('detection_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'error'])->default('pending');
            $table->enum('color', ['HIJAU', 'KUNING', 'MERAH'])->notNull();
            $table->enum('destination', ['BOWL_GREEN', 'BOWL_YELLOW', 'BOWL_RED'])->notNull;
            $table->decimal('confidence', 5, 2)->notNull();
            $table->json('command_payload')->nullable();
            $table->string('source', 50)->default('ml_service');
            $table->boolean('processed')->default(0);
            $table->timestamps();

            $table->index('detection_id');
            $table->index('status');
            $table->index('color');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorting_events');
    }
};