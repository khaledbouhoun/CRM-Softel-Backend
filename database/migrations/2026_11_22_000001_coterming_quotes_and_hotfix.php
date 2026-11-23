<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renewal requests become quote requests: the customer states years / workstations / dossiers, staff quote the price.
 * Statuses: pending_quote -> quoted -> paid_cash (or rejected). Also flags private hotfix files on interventions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending_quote')->change();
            $table->unsignedBigInteger('amount_dzd')->nullable()->change();   // set by staff when the proforma is issued
            $table->string('reference', 30)->nullable()->change();            // generated with the proforma
            $table->unsignedSmallInteger('workstations_count')->default(1)->after('years');
            $table->unsignedSmallInteger('dossiers_count')->default(1)->after('workstations_count');
            $table->text('notes')->nullable()->after('dossiers_count');
            $table->dateTime('quoted_at')->nullable()->after('confirmed_by');
            $table->string('quoted_by', 100)->nullable()->after('quoted_at');
        });

        // Requests created under the old fixed-price flow already carry a price: they are quoted / paid.
        DB::table('renewal_requests')->where('status', 'pending')->update(['status' => 'quoted']);
        DB::table('renewal_requests')->where('status', 'confirmed')->update(['status' => 'paid_cash']);

        Schema::table('intervention_attachments', function (Blueprint $table) {
            $table->boolean('is_hotfix')->default(false)->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('intervention_attachments', fn (Blueprint $table) => $table->dropColumn('is_hotfix'));

        DB::table('renewal_requests')->where('status', 'quoted')->update(['status' => 'pending']);
        DB::table('renewal_requests')->where('status', 'paid_cash')->update(['status' => 'confirmed']);
        DB::table('renewal_requests')->whereIn('status', ['pending_quote', 'rejected'])->delete();

        Schema::table('renewal_requests', function (Blueprint $table) {
            $table->dropColumn(['workstations_count', 'dossiers_count', 'notes', 'quoted_at', 'quoted_by']);
        });
    }
};
