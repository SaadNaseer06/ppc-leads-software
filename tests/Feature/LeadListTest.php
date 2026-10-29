<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Support\LeadText;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/** Uses truncation, not transactions: MySQL only updates FULLTEXT indexes on commit */
class LeadListTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        // Truncation runs before each test; also clean up after, so committed rows
        // don't leak into the transaction-based test classes that run later
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function seedLeads(int $count, array $brands, array $attrs = []): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $brand = $brands[$i % count($brands)];
            $name = "Person {$i}";
            $email = "person{$i}@example.com";
            // Many identical timestamps: (created_at, id) must still page without gaps
            $created = now()->subMinutes(intdiv($i, 3));
            $rows[] = [
                'brand_id' => $brand->id, 'name' => $name, 'email' => $email, 'source' => 'form', 'status' => 'new',
                'channel' => 'ppc', 'search_text' => LeadText::searchText($name, $email, null),
                'created_at' => $created, 'updated_at' => $created, 'last_activity_at' => $created, ...$attrs,
            ];
        }
        Lead::insert($rows);
    }

    /** Walk every page forward, then back again, and check nothing is skipped or repeated */
    /** @param list<int>|null $brandIds brands the user can see (null = all) */
    private function walk(int $expected, ?array $brandIds = null): void
    {
        $seen = [];
        $pages = [];
        $cursor = null;
        do {
            $page = $this->getJson('/api/leads?size=25'.($cursor ? "&cursor={$cursor}&dir=next" : ''))->assertOk()->json();
            $pages[] = $page;
            array_push($seen, ...array_column($page['leads'], 'id'));
            $cursor = $page['next_cursor'];
        } while ($cursor);

        $this->assertCount($expected, $seen);
        $this->assertSame($expected, count(array_unique($seen)));
        $this->assertSame(Lead::when($brandIds, fn ($q) => $q->whereIn('brand_id', $brandIds))
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all(), $seen);

        // Paging back from the last page returns exactly the previous page
        $back = $this->getJson('/api/leads?size=25&cursor='.end($pages)['prev_cursor'].'&dir=prev')->json();
        $this->assertSame(array_column($pages[count($pages) - 2]['leads'], 'id'), array_column($back['leads'], 'id'));
    }

    public function test_keyset_pagination_for_a_super_admin(): void
    {
        $this->seedLeads(103, [$this->brand()]);
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->walk(103);
    }

    public function test_keyset_pagination_merges_brands_for_a_multi_brand_admin(): void
    {
        $a = $this->brand();
        $b = $this->brand();
        $c = $this->brand();
        $this->seedLeads(90, [$a, $b, $c]);
        $this->actingAs($this->user(Role::Admin, [$a, $b]));
        $this->walk(60, [$a->id, $b->id]);
    }

    public function test_search_by_name_email_and_phone_and_capped_counts(): void
    {
        $brand = $this->brand();
        $this->seedLeads(20, [$brand]);
        Lead::create([
            'brand_id' => $brand->id, 'name' => 'Olivia Rossi', 'email' => 'olivia.rossi@example.org', 'phone' => '+1 (555) 777-8888',
            'source' => 'form', 'search_text' => LeadText::searchText('Olivia Rossi', 'olivia.rossi@example.org', '+1 (555) 777-8888'),
        ]);
        $this->actingAs($this->user(Role::SuperAdmin));

        foreach (['olivia rossi', 'olivia.rossi@example.org', '5557778888', '777-8888', 'Oli'] as $term) {
            $this->getJson('/api/leads?q='.urlencode($term))->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('leads.0.name', 'Olivia Rossi');
        }

        config(['ppc.count_cap' => 10]);
        $this->getJson('/api/leads')->assertJsonPath('total', 10)->assertJsonPath('total_capped', true);
    }

    public function test_dashboard_totals_match_the_data(): void
    {
        $brand = $this->brand();
        $this->seedLeads(30, [$brand]);
        Lead::limit(5)->update(['status' => 'spam']);
        Lead::where('status', 'new')->limit(4)->update(['status' => 'converted']);
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $stats = $this->getJson('/api/dashboard?range=7')->assertOk()->json('stats');
        $this->assertSame([25, 4, 25, 25], [$stats['total'], $stats['converted'], $stats['ppc'], array_sum(array_column($stats['series'], 'count'))]);
        $this->assertCount(7, $stats['series']);
        $this->assertSame(5, collect($stats['statuses'])->firstWhere('status', 'spam')['count']);
    }
}
