<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\Website;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandManagerTest extends TestCase
{
    use RefreshDatabase;

    private function lead(Website $site, array $input = []): Lead
    {
        return Lead::find(app(LeadIntake::class)->ingest($site->brand_id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail(), ...$input])['lead_id']);
    }

    public function test_admins_appoint_one_manager_per_brand(): void
    {
        $brand = $this->brand();
        $otherBrand = $this->brand();
        $admin = $this->user(Role::Admin, [$brand]);
        $first = $this->user(Role::BrandManager, [], ['admin_id' => $admin->id]);
        $second = $this->user(Role::BrandManager, [], ['admin_id' => $admin->id]);
        $rep = $this->user(Role::FrontSales, [$brand]);

        $this->actingAs($admin);
        $this->patchJson("/api/admin/brands/{$brand->id}/manager", ['user_id' => $first->id])->assertOk();
        $this->assertSame([$brand->id], $first->fresh()->brandIds());
        $this->getJson('/api/admin/brands')->assertOk()
            ->assertJsonPath('brands.0.managers.0.id', $first->id)
            ->assertJsonCount(2, 'manager_options');

        // The new manager replaces the old one, who loses the brand; the team stays
        $this->patchJson("/api/admin/brands/{$brand->id}/manager", ['user_id' => $second->id])->assertOk();
        $this->assertSame([], $first->fresh()->brandIds());
        $this->assertSame([$brand->id], $second->fresh()->brandIds());
        $this->assertSame([$brand->id], $rep->fresh()->brandIds());

        // Only Brand Managers can be appointed, and only to the admin's own brands
        $this->patchJson("/api/admin/brands/{$brand->id}/manager", ['user_id' => $rep->id])->assertUnprocessable();
        $this->patchJson("/api/admin/brands/{$otherBrand->id}/manager", ['user_id' => $first->id])->assertNotFound();

        $this->patchJson("/api/admin/brands/{$brand->id}/manager", ['user_id' => null])->assertOk();
        $this->assertSame([], $second->fresh()->brandIds());

        // Appointing is for Admins and Super Admins
        $this->actingAs($second);
        $this->patchJson("/api/admin/brands/{$brand->id}/manager", ['user_id' => $second->id])->assertForbidden();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->patchJson("/api/admin/brands/{$otherBrand->id}/manager", ['user_id' => $second->id])->assertOk();
    }

    public function test_an_admin_can_add_a_brand_manager_to_the_team(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $id = $this->postJson('/api/admin/users', [
            'email' => 'robin@test.local', 'name' => 'Robin', 'password' => 'password123',
            'role' => 'BRAND_MANAGER', 'brand_ids' => [$brand->id],
        ])->assertCreated()->json('id');

        $this->getJson('/api/admin/brands')->assertJsonPath('brands.0.managers.0.id', $id);
    }

    public function test_a_manager_runs_their_brand_and_nothing_else(): void
    {
        $brand = $this->brand();
        $otherBrand = $this->brand();
        $site = $this->website($brand);
        $otherSite = $this->website($otherBrand);
        $rep = $this->user(Role::FrontSales, [$brand]);
        $otherRep = $this->user(Role::FrontSales, [$otherBrand]);
        $manager = $this->user(Role::BrandManager, [$brand]);
        $mine = $this->lead($site);
        $theirs = $this->lead($otherSite);

        $this->actingAs($manager);

        // Every lead of the brand, none from outside it
        $this->getJson('/api/leads')->assertOk()->assertJsonCount(1, 'leads')->assertJsonPath('leads.0.id', $mine->id);
        $this->getJson("/api/leads/{$theirs->id}")->assertNotFound();

        // Works the brand: assigns, invoices, takes payments, sees reports and websites
        $this->patchJson("/api/leads/{$mine->id}", ['assignee_id' => $rep->id])->assertOk();
        $this->patchJson("/api/leads/{$mine->id}", ['assignee_id' => $otherRep->id])->assertUnprocessable();
        $invoice = $this->postJson('/api/invoices', ['lead_id' => $mine->id, 'amount' => 100])->assertCreated()->json('id');
        $this->postJson("/api/invoices/{$invoice}/payments", ['amount' => 100, 'method' => 'cash'])->assertCreated();
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'invoices');
        $this->getJson('/api/reports/sales')->assertOk()->assertJsonCount(1, 'by_brand')->assertJsonPath('by_brand.0.id', $brand->id);
        $this->getJson('/api/websites')->assertOk()->assertJsonCount(1, 'websites');
        $this->patchJson('/api/admin/brands', ['brand_id' => $brand->id, 'auto_assign_enabled' => true])->assertOk();
        $this->patchJson('/api/admin/brands', ['brand_id' => $otherBrand->id, 'auto_assign_enabled' => true])->assertForbidden();

        // Sees the brand's team, read-only
        $team = $this->getJson('/api/admin/users')->assertOk();
        $this->assertEqualsCanonicalizing([$rep->id, $manager->id], array_column($team->json('users'), 'id'));
        $this->assertFalse(collect($team->json('users'))->contains('editable', true));
        $this->assertSame([], $team->json('roles'));
        $this->postJson('/api/admin/users', ['email' => 'x@test.local', 'name' => 'X', 'password' => 'password123', 'role' => 'FRONT_SALES'])->assertForbidden();
        $this->patchJson("/api/admin/users/{$rep->id}", ['active' => false])->assertForbidden();

        // Brands themselves and company finance stay with the Super Admin and Finance
        $this->postJson('/api/admin/brands', ['name' => 'New', 'slug' => 'new'])->assertForbidden();
        $this->deleteJson("/api/admin/brands/{$brand->id}")->assertForbidden();
        $this->getJson('/api/finance')->assertForbidden();
    }

    public function test_the_team_screen_loads_for_a_manager(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::BrandManager, [$brand]), 'web');

        foreach (['/dashboard', '/leads', '/chats', '/invoices', '/reports', '/websites', '/team', '/brands'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/finance')->assertRedirect('/dashboard');
    }

    public function test_the_team_page_shows_a_manager_only_their_brand(): void
    {
        $brand = $this->brand();
        $otherBrand = $this->brand();
        $rep = $this->user(Role::FrontSales, [$brand, $otherBrand]);
        $manager = $this->user(Role::BrandManager, [$brand]);
        $superAdmin = $this->user(Role::SuperAdmin);

        $this->lead($this->website($brand))->update(['assignee_id' => $rep->id]);
        $this->lead($this->website($otherBrand))->update(['assignee_id' => $rep->id]);
        $this->lead($this->website($brand))->update(['assignee_id' => $rep->id, 'promoted_at' => null]);

        $row = fn ($response) => collect($response->assertOk()->json('users'))->firstWhere('id', $rep->id);

        $this->actingAs($manager);
        $seen = $row($this->getJson('/api/admin/users'));
        $this->assertSame([$brand->id], array_column($seen['brands'], 'id'));
        $this->assertSame(1, $seen['open_leads'], 'The other brand and the chat-only lead are not counted');
        $this->assertSame(1, $seen['leads_30d']);

        $this->actingAs($superAdmin);
        $seen = $row($this->getJson('/api/admin/users'));
        $this->assertCount(2, $seen['brands']);
        $this->assertSame(2, $seen['open_leads'], 'A chat-only lead never counts: it is not on the Leads page');
    }

    public function test_finance_keeps_seeing_every_brand_in_reports(): void
    {
        $this->brand();
        $this->brand();
        $this->assertSame(2, Brand::count());
        $this->actingAs($this->user(Role::Finance));
        $this->getJson('/api/reports/sales')->assertOk()->assertJsonCount(2, 'options.brands');
    }
}
