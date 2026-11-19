<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('pack_id')->nullable()->after('id')->constrained('packs')->nullOnDelete();
            $table->string('slug', 170)->nullable()->unique()->after('name');
            $table->string('image_path', 500)->nullable();
            $table->json('gallery')->nullable();
            $table->longText('full_description')->nullable();
            $table->json('features')->nullable();
            $table->json('specifications')->nullable();
            $table->boolean('is_active_on_web')->default(false);
            $table->string('demo_url', 500)->nullable();

            $table->index('is_active_on_web');
        });

        foreach (DB::table('products')->get(['id', 'name']) as $row) {
            DB::table('products')->where('id', $row->id)->update(['slug' => Str::slug($row->name).'-'.$row->id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_id');
            $table->dropIndex(['is_active_on_web']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'image_path', 'gallery', 'full_description', 'features', 'specifications', 'is_active_on_web', 'demo_url']);
        });

        Schema::dropIfExists('packs');
    }
};
