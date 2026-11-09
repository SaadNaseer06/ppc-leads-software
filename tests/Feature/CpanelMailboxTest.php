<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Brand;
use App\Models\CpanelAccount;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\MailboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/** Mailboxes created, reset and deleted through cPanel's API, across several cPanel accounts */
class CpanelMailboxTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $rep;

    /** @var list<array{string, array}> the cPanel calls made: function and params */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand(['name' => 'Hub']);
        $this->rep = $this->user(Role::FrontSales, [$this->brand], ['name' => 'Riley Chen']);
        $this->mock(MailboxService::class, function (MockInterface $m) {
            $m->shouldReceive('problem')->andReturn(null)->byDefault();
            $m->shouldReceive('startFromNow');
        });
    }

    /** A fake cPanel: tokens other than "good-token" are turned down, and add_pop fails for "taken" */
    private function fakeCpanel(array $domains = ['hub.test', 'hub-mail.test']): void
    {
        Http::fake(function (Request $request) use ($domains) {
            if (! str_ends_with($request->header('Authorization')[0] ?? '', ':good-token')) {
                return Http::response('', 401);
            }
            $fn = basename(parse_url($request->url(), PHP_URL_PATH));
            $this->calls[] = [$fn, $request->data()];
            if ($fn === 'add_pop' && $request->data()['email'] === 'taken') {
                return Http::response(['status' => 0, 'errors' => ['The account taken@hub.test already exists!'], 'data' => null]);
            }

            return Http::response(['result' => ['status' => 1, 'errors' => null, 'data' => $fn === 'list_mail_domains' ? array_map(fn ($d) => ['domain' => $d], $domains) : null]]);
        });
    }

    private function account(string $host = 'cp1.host.test', array $domains = ['hub.test']): CpanelAccount
    {
        return CpanelAccount::create(['host' => $host, 'username' => 'hubuser', 'token' => 'good-token', 'domains' => $domains]);
    }

    private function create(array $input = [])
    {
        return $this->postJson('/api/admin/mailboxes/create-in-cpanel', [
            'brand_id' => $this->brand->id, 'user_id' => $this->rep->id, 'local_part' => 'riley', 'domain' => 'hub.test', ...$input,
        ]);
    }

    public function test_a_super_admin_connects_a_cpanel_account_and_its_domains_are_read(): void
    {
        $this->fakeCpanel();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->postJson('/api/admin/cpanel-accounts', ['host' => 'CP1.host.test', 'username' => 'hubuser', 'token' => 'good-token'])
            ->assertCreated()
            ->assertJsonPath('account.host', 'cp1.host.test')
            ->assertJsonPath('account.domains', ['hub.test', 'hub-mail.test'])
            ->assertJsonMissingPath('account.token');

        $this->assertStringNotContainsString('good-token', (string) DB::table('cpanel_accounts')->value('token'));
        $this->getJson('/api/admin/mailboxes')->assertOk()->assertJsonPath('cpanel_accounts.0.domains.1', 'hub-mail.test')->assertDontSee('good-token');
    }

    public function test_a_wrong_token_or_server_is_explained_and_nothing_is_saved(): void
    {
        $this->fakeCpanel();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->postJson('/api/admin/cpanel-accounts', ['host' => 'cp1.host.test', 'username' => 'hubuser', 'token' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'turned the API token down'));
        $this->postJson('/api/admin/cpanel-accounts', ['host' => 'https://cp1.host.test/', 'username' => 'hubuser', 'token' => 'good-token'])
            ->assertStatus(422)->assertJsonValidationErrors('host');

        $this->assertDatabaseCount('cpanel_accounts', 0);
    }

    public function test_a_mailbox_is_created_in_the_cpanel_account_that_hosts_its_domain(): void
    {
        $this->fakeCpanel();
        $this->account('cp1.host.test', ['other.test']);
        $hub = $this->account('cp2.host.test', ['hub.test']);
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->create(['quota' => 2048, 'from_name' => 'Riley · Hub'])->assertCreated()
            ->assertJsonPath('mailbox.address', 'riley@hub.test')
            ->assertJsonPath('mailbox.managed', true)
            ->assertJsonPath('warning', null);

        [$fn, $params] = $this->calls[0];
        $this->assertSame('add_pop', $fn);
        $this->assertSame(['riley', 'hub.test', '2048'], [$params['email'], $params['domain'], (string) $params['quota']]);
        $this->assertGreaterThanOrEqual(32, strlen($params['password']));

        $mailbox = Mailbox::sole();
        $this->assertSame([$hub->id, 'cp2.host.test', $this->rep->id], [$mailbox->cpanel_account_id, $mailbox->host, $mailbox->user_id]);
        // The app knows the password, and nobody was shown it
        $this->assertSame($params['password'], $mailbox->password);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://cp2.host.test:2083/execute/Email/add_pop'));
    }

    public function test_creating_is_refused_for_a_domain_no_account_hosts_or_a_bad_owner(): void
    {
        $this->fakeCpanel();
        $this->account();
        $this->actingAs($this->user(Role::SuperAdmin));
        $outsider = $this->user(Role::FrontSales, [$this->brand(['name' => 'Other'])]);

        $this->create(['domain' => 'elsewhere.test'])->assertStatus(422)->assertJsonPath('error', 'None of the connected cPanel accounts hosts elsewhere.test.');
        $this->create(['user_id' => $outsider->id])->assertStatus(422);
        $this->create(['local_part' => 'riley chen'])->assertStatus(422)->assertJsonValidationErrors('local_part');
        $this->create(['local_part' => 'taken'])->assertStatus(422)->assertJsonPath('error', 'cPanel said: The account taken@hub.test already exists!');

        $this->assertSame([], array_filter($this->calls, fn ($c) => $c[0] === 'add_pop' && $c[1]['email'] !== 'taken'));
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_a_new_mailbox_that_cant_log_in_yet_is_kept_with_a_warning(): void
    {
        $this->fakeCpanel();
        $this->account();
        $this->mock(MailboxService::class, fn (MockInterface $m) => $m->shouldReceive('problem')->andReturn('Login failed'));
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->create()->assertCreated()->assertJsonPath('warning', 'Login failed');
        $this->assertSame('Login failed', Mailbox::sole()->error);
    }

    public function test_reset_password_changes_it_in_cpanel_and_the_app_together(): void
    {
        $this->fakeCpanel();
        $this->account();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->create()->assertCreated();
        $before = Mailbox::sole()->password;

        $this->postJson('/api/admin/mailboxes/'.Mailbox::sole()->id.'/reset-password')->assertOk();

        [$fn, $params] = end($this->calls);
        $this->assertSame('passwd_pop', $fn);
        $this->assertNotSame($before, $params['password']);
        $this->assertSame($params['password'], Mailbox::sole()->password);
    }

    public function test_disconnecting_keeps_the_mailbox_in_cpanel_unless_asked_to_delete_it(): void
    {
        $this->fakeCpanel();
        $this->account();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->create()->assertCreated();
        $this->deleteJson('/api/admin/mailboxes/'.Mailbox::sole()->id)->assertOk();
        $this->assertNotContains('delete_pop', array_column($this->calls, 0));

        $this->create()->assertCreated();
        $this->deleteJson('/api/admin/mailboxes/'.Mailbox::sole()->id.'?delete_from_cpanel=1')->assertOk();
        $this->assertSame(['delete_pop', ['email' => 'riley', 'domain' => 'hub.test']], end($this->calls));
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_removing_a_cpanel_account_leaves_its_mailboxes_connected(): void
    {
        $this->fakeCpanel();
        $account = $this->account();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->create()->assertCreated();

        $this->deleteJson("/api/admin/cpanel-accounts/{$account->id}")->assertOk();

        $mailbox = Mailbox::sole();
        $this->assertNull($mailbox->cpanel_account_id);
        $this->getJson('/api/admin/mailboxes')->assertJsonPath('mailboxes.0.managed', false);
        $this->postJson("/api/admin/mailboxes/{$mailbox->id}/reset-password")->assertStatus(422);
    }

    public function test_refresh_picks_up_a_domain_added_in_cpanel_later(): void
    {
        $this->fakeCpanel(['hub.test', 'new-brand.test']);
        $account = $this->account();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->postJson("/api/admin/cpanel-accounts/{$account->id}/refresh")->assertOk()->assertJsonPath('account.domains', ['hub.test', 'new-brand.test']);
        $this->assertSame($account->id, CpanelAccount::forDomain('NEW-brand.test')?->id);
    }

    public function test_only_a_super_admin_touches_cpanel(): void
    {
        $this->fakeCpanel();
        $account = $this->account();

        foreach ([Role::Admin, Role::BrandManager, Role::FrontSales] as $role) {
            $this->actingAs($this->user($role, [$this->brand]));
            $this->postJson('/api/admin/cpanel-accounts', ['host' => 'cp9.host.test', 'username' => 'x', 'token' => 'good-token'])->assertForbidden();
            $this->create()->assertForbidden();
            $this->deleteJson("/api/admin/cpanel-accounts/{$account->id}")->assertForbidden();
        }
        Http::assertNothingSent();
    }
}
