<?php

use App\Support\Wilayas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the customer typed last time, so the next request only needs the product choice.
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->string('company', 150)->nullable()->after('phone');
            $table->string('contact_name', 150)->nullable()->after('company');
            $table->string('job_title', 150)->nullable()->after('contact_name');
            $table->string('wilaya', 100)->nullable()->after('job_title');
            $table->string('city', 100)->nullable()->after('wilaya');
            $table->string('business_activity', 150)->nullable()->after('city');
            $table->timestamp('onboarded_at')->nullable()->after('last_login_at');
        });

        // Existing accounts: reuse their latest request (the wilaya becomes "16 - Alger" when recognisable).
        foreach (DB::table('portal_accounts')->pluck('id') as $id) {
            $last = DB::table('portal_requests')->where('portal_account_id', $id)->orderByDesc('id')->first();

            if (! $last) {
                continue;
            }

            DB::table('portal_accounts')->where('id', $id)->update([
                'company' => $last->company, 'contact_name' => $last->contact_name, 'job_title' => $last->job_title,
                'wilaya' => Wilayas::canonical($last->wilaya), 'city' => $last->city, 'business_activity' => $last->business_activity,
                'onboarded_at' => $last->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->dropColumn(['company', 'contact_name', 'job_title', 'wilaya', 'city', 'business_activity', 'onboarded_at']);
        });
    }
};
