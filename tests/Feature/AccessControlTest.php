<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Website;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function lead(Website $site, array $input = []): Lead
    {
        $id = app(LeadIntake::class)->ingest($site->brand_id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail(), ...$input])['lead_id'];

        return Lead::find($id);
    }

    public function test_login_issues_a_token_and_rejects_bad_or_inactive_accounts(): void
    {
        $user = $this->user(Role::Admin, [], ['email' => 'boss@test.local']);
        $token = $this->postJson('/api/auth/login', ['email' => 'BOSS@test.local', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.can.assign_leads', true)->json('token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/auth/login', ['email' => 'boss@test.local', 'password' => 'nope'])->assertUnauthorized();
        $user->update(['active' => false]);
        $this->postJson('/api/auth/login', ['email' => 'boss@test.local', 'password' => 'password123'])->assertUnauthorized();
        $this->app['auth']->forgetGuards(); // a real next request resolves the user afresh
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sales_reps_only_see_and_touch_their_own_leads(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $rep = $this->user(Role::FrontSales, [$brand]);
        $other = $this->user(Role::FrontSales, [$brand]);
        $mine = $this->lead($site, ['assignee_id' => $rep->id]);
        $theirs = $this->lead($site, ['assignee_id' => $other->id]);

        $this->actingAs($rep);
        $this->getJson('/api/leads')->assertOk()->assertJsonCount(1, 'leads')->assertJsonPath('leads.0.id', $mine->id);
        $this->getJson("/api/leads/{$theirs->id}")->assertNotFound();
        $this->postJson('/api/leads/bulk', ['action' => 'assign', 'ids' => [$mine->id], 'assignee_id' => null])->assertForbidden();
        $this->postJson('/api/leads/bulk', ['action' => 'status', 'ids' => [$theirs->id], 'status' => 'lost'])->assertNotFound();
        $this->get('/api/leads/export')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admins_are_scoped_to_their_brands(): void
    {
        $mineBrand = $this->brand();
        $otherBrand = $this->brand();
        $admin = $this->user(Role::Admin, [$mineBrand]);
        $visible = $this->lead($this->website($mineBrand));
        $hidden = $this->lead($this->website($otherBrand));
        $outsider = $this->user(Role::FrontSales, [$otherBrand]);

        $this->actingAs($admin);
        $this->getJson('/api/leads')->assertJsonCount(1, 'leads');
        $this->getJson("/api/leads/{$hidden->id}")->assertNotFound();
        $this->patchJson("/api/leads/{$visible->id}", ['assignee_id' => $outsider->id])
            ->assertStatus(422)->assertJsonPath('error', "Assignee does not have access to every selected lead's brand");
        $this->postJson('/api/admin/users', ['email' => 'x@test.local', 'name' => 'X', 'password' => 'password123', 'role' => 'ADMIN'])
            ->assertForbidden();
    }

    public function test_deactivating_a_user_releases_their_open_leads_and_revokes_access(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $admin = $this->user(Role::Admin, [$brand]);
        $rep = $this->user(Role::FrontSales, [$brand], ['admin_id' => $admin->id]);
        $open = $this->lead($site, ['assignee_id' => $rep->id]);
        $closed = $this->lead($site, ['assignee_id' => $rep->id]);
        $closed->update(['status' => 'converted']);
        $token = $rep->createToken('t')->plainTextToken;

        $this->actingAs($admin)->patchJson("/api/admin/users/{$rep->id}", ['active' => false])
            ->assertOk()->assertJson(['released_leads' => 1]);

        $this->assertNull($open->fresh()->assignee_id);
        $this->assertSame($rep->id, $closed->fresh()->assignee_id);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", ['active' => false])->assertStatus(422);
    }

    public function test_converting_hands_the_lead_to_up_sales_with_a_timeline(): void
    {
        $brand = $this->brand();
        $rep = $this->user(Role::FrontSales, [$brand]);
        $up = $this->user(Role::UpSales, [$brand]);
        $lead = $this->lead($this->website($brand), ['assignee_id' => $rep->id]);

        $this->actingAs($rep)->patchJson("/api/leads/{$lead->id}", ['status' => 'converted'])->assertOk();
        $this->actingAs($rep)->postJson("/api/leads/{$lead->id}/notes", ['body' => 'Signed!'])->assertNotFound();

        $this->assertSame([$up->id, 'converted'], [$lead->fresh()->assignee_id, $lead->fresh()->status]);
        $this->assertSame(['created', 'assigned', 'status', 'assigned'], $lead->activities()->orderBy('id')->pluck('type')->all());
    }
}
