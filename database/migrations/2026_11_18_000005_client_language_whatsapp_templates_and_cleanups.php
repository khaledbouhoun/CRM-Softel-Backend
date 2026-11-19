<?php

use App\Services\MessageTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferred language of the client (customers are converted prospects, so one column covers both).
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('language', 2)->default('fr')->after('email');
        });

        // A signed-up customer keeps the language chosen on the website (portal_accounts.locale).
        foreach (DB::table('portal_accounts')->whereNotNull('prospect_id')->whereIn('locale', ['ar', 'fr', 'en'])->get(['prospect_id', 'locale']) as $account) {
            DB::table('prospects')->where('id', $account->prospect_id)->update(['language' => $account->locale]);
        }

        // Confirmed appointments get their WhatsApp link (it was only produced when the flag was set by hand).
        DB::table('appointments')->where('status', 'confirmed')->update(['send_whatsapp' => true]);

        // A template for every appointment kind in every language.
        MessageTemplateDefaults::install();

        // Module 2 follow-ups: releases replace the "release" ticket type; only employees reopen closed tickets.
        DB::table('intervention_types')->where('code', 'release')->update(['is_active' => false]);
        $closed = DB::table('intervention_statuses')->where('is_closed', true)->pluck('id');
        DB::table('intervention_status_transitions')->whereIn('from_status_id', $closed)->update(['customer_allowed' => false]);
    }

    public function down(): void
    {
        DB::table('intervention_types')->where('code', 'release')->update(['is_active' => true]);

        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
