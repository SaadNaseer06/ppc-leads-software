<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Lead;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_and_out_with_a_session(): void
    {
        $this->user(Role::Admin, [], ['email' => 'boss@test.local']);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('showDemo', true));

        $this->post('/login', ['email' => 'boss@test.local', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'BOSS@test.local', 'password' => 'password123'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->get('/login')->assertRedirect('/dashboard');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_every_screen_renders_for_a_super_admin(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $leadId = app(LeadIntake::class)->ingest($brand->id, $site, LeadSource::Form, ['email' => 'a@example.com'])['lead_id'];
        $this->actingAs($this->user(Role::SuperAdmin), 'web');

        $screens = [
            '/dashboard' => ['Dashboard', 'dashboard.stats'],
            '/leads' => ['Leads/Index', 'page.leads'],
            "/leads/{$leadId}" => ['Leads/Show', 'activities'],
            '/chats' => ['Chats', 'chats.conversations'],
            '/websites' => ['Websites/Index', 'websites'],
            "/websites/{$site->id}" => ['Websites/Show', 'website.embed_snippet'],
            '/team' => ['Team/Index', 'users'],
            '/brands' => ['Brands/Index', 'brands'],
            '/invoices' => ['Invoices', 'invoices'],
            '/finance' => ['Finance/Index', 'transactions'],
            '/reports' => ['Reports', 'reports'],
        ];
        foreach ($screens as $url => [$component, $prop]) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component)
                ->has($prop)
                ->where('auth.user.can.assign_leads', true)
                ->has('live.unassigned'));
        }
    }

    public function test_role_and_brand_rules_apply_to_screens(): void
    {
        $mine = $this->brand();
        $other = $this->brand();
        $hidden = app(LeadIntake::class)->ingest($other->id, $this->website($other), LeadSource::Form, ['email' => 'h@example.com'])['lead_id'];
        $this->actingAs($this->user(Role::Admin, [$mine]), 'web');

        $this->get('/finance')->assertRedirect('/dashboard');
        $this->get("/leads/{$hidden}")->assertNotFound()->assertInertia(fn (Assert $p) => $p->component('NotFound'));
        $this->get('/leads?status=new&q=nobody')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('query.status', 'new')->where('page.total', 0));
    }

    public function test_deactivation_ends_an_open_session(): void
    {
        $rep = $this->user(Role::FrontSales, [$this->brand()]);
        $this->actingAs($rep, 'web')->get('/dashboard')->assertOk();

        $rep->update(['active' => false]);
        $this->get('/leads')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertSame(0, Lead::count());
    }
}
