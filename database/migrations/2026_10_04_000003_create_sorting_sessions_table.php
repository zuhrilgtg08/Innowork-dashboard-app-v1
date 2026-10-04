use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sorting_sessions', function (Builder $table): void {
            $table->id();
            $table->string('session_key', 50)->unique();
            $table->integer('green_count')->default(0);
            $table->integer('yellow_count')->default(0);
            $table->integer('red_count')->default(0);
            $table->integer('total_objects')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();

            $table->index('session_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorting_sessions');
    }
};