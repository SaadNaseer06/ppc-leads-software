<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which ad campaign each lead came from. `leads.campaign` holds the ad platform's campaign ID
 * (Google adds gad_campaignid to every ad click by itself), or the utm_campaign tag when no ID
 * came. Managers name each one once in `ad_campaigns`. Chats keep the visit that came from the
 * ad, so a visitor who browses on before chatting still counts for the campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('campaign', 150)->nullable()->after('utm_content');
            // Covers the leads list filtered to one campaign, with every column it filters on
            $table->index(['campaign', 'created_at', 'id', 'brand_id', 'status', 'assignee_id', 'promoted_at'], 'leads_campaign_timeline_idx');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->text('landing_page')->nullable()->after('page_url');
            $table->text('referrer')->nullable()->after('landing_page');
        });

        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('campaign', 150);
            $table->string('name', 150);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['brand_id', 'campaign']);
        });

        DB::table('leads')->whereNotNull('utm_campaign')->update(['campaign' => DB::raw('LEFT(utm_campaign, 150)')]);

        // Leads that arrived with Google's campaign ID in their URL: the ID is the better key
        DB::table('leads')->select(['id', 'landing_page', 'page_url'])
            ->where(fn ($q) => $q->where('landing_page', 'like', '%gad_campaignid=%')->orWhere('page_url', 'like', '%gad_campaignid=%'))
            ->chunkById(1000, function ($leads) {
                foreach ($leads as $lead) {
                    foreach ([$lead->landing_page, $lead->page_url] as $url) {
                        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $params);
                        $id = is_string($params['gad_campaignid'] ?? null) ? trim($params['gad_campaignid']) : '';
                        if ($id !== '') {
                            DB::table('leads')->where('id', $lead->id)->update(['campaign' => mb_substr($id, 0, 150)]);
                            break;
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['landing_page', 'referrer']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_campaign_timeline_idx');
            $table->dropColumn('campaign');
        });
    }
};
