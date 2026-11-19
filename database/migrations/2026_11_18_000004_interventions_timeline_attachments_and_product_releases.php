<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Timeline events: a status change is a message of kind "status_change"/"reopened".
        Schema::table('intervention_messages', function (Blueprint $table) {
            $table->string('kind', 20)->default('message')->after('visibility'); // message | status_change | reopened
            $table->foreignId('from_status_id')->nullable()->after('status_id')->constrained('intervention_statuses')->restrictOnDelete();
            $table->foreignId('portal_account_id')->nullable()->after('author_name')->constrained('portal_accounts')->nullOnDelete();
        });

        DB::table('intervention_messages')->whereNotNull('status_id')->update(['kind' => 'status_change']);

        // Files can belong to one reply.
        Schema::table('intervention_attachments', function (Blueprint $table) {
            $table->foreignId('message_id')->nullable()->after('intervention_id')->constrained('intervention_messages')->nullOnDelete();
            $table->string('uploaded_by_type', 10)->default('customer')->after('visibility'); // customer | employee
            $table->string('uploaded_by_name', 150)->nullable()->after('uploaded_by_type');
        });

        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable();
        });

        // Product updates now live in product releases, not in support tickets.
        DB::table('intervention_types')->where('code', 'update')->update(['is_active' => false]);

        Schema::create('product_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('version_number', 50);
            $table->text('changelog')->nullable();
            $table->string('download_url', 500)->nullable();
            $table->string('installer_path', 500)->nullable();
            $table->string('installer_name', 255)->nullable();
            $table->string('installer_mime', 100)->nullable();
            $table->unsignedBigInteger('installer_size')->nullable();
            $table->date('release_date');
            $table->string('audience', 10)->default('all'); // all | owned | specific
            $table->boolean('is_published')->default(true);
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'version_number']);
            $table->index(['is_published', 'release_date']);
            $table->index('audience');
        });

        Schema::create('product_release_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_release_id')->constrained('product_releases')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->unique(['product_release_id', 'prospect_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_release_customers');
        Schema::dropIfExists('product_releases');

        DB::table('intervention_types')->where('code', 'update')->update(['is_active' => true]);

        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });

        Schema::table('intervention_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_id');
            $table->dropColumn(['uploaded_by_type', 'uploaded_by_name']);
        });

        Schema::table('intervention_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('portal_account_id');
            $table->dropConstrainedForeignId('from_status_id');
            $table->dropColumn('kind');
        });
    }
};
