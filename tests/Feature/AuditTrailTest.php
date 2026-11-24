<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The audit trail: what gets written (changes, refusals, sign ins, page views), what is left
// out (guests, polls, secrets), that a failure in it never reaches the person, and the
// read-only viewer behind `view audit-logs`.
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    // The newest entry, decoded.
    private function last(): object
    {
        $row = DB::table('audit_logs')->orderByDesc('id')->first();

        $this->assertNotNull($row, 'no audit entry was written');

        $row->changes = $row->changes ? json_decode($row->changes, true) : null;
        $row->input = $row->input ? json_decode($row->input, true) : null;

        return $row;
    }

    private function rows(): int
    {
        return DB::table('audit_logs')->count();
    }

    private function customerRoleId(): int
    {
        $this->seedPermissions();

        return (int) Role::where('name', Role::CUSTOMER)->value('id');
    }

    /* -------- changes -------- */

    public function test_a_change_is_logged_with_who_what_and_the_before_and_after(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);

        $this->actingAs($admin)->putJson(route('users.update', $target->id), [
            'first_name' => 'New',
            'last_name' => 'Name',
            'company_id' => $target->company_id,
            'employee_id' => 'E-1001',
            'email' => $target->email,
            'roles' => [$this->customerRoleId()],
        ])->assertOk();

        $entry = $this->last();

        $this->assertSame('action', $entry->event);
        $this->assertSame('users.update', $entry->action);
        $this->assertSame('Edited a user', $entry->description);
        $this->assertSame($admin->id, (int) $entry->user_id);
        $this->assertSame($admin->email, $entry->user_email);
        $this->assertSame('users', $entry->subject_type);
        $this->assertSame($target->id, (int) $entry->subject_id);
        $this->assertSame('New Name', $entry->subject_label);
        $this->assertSame('success', $entry->outcome);
        $this->assertSame(200, (int) $entry->status);
        $this->assertSame('PUT', $entry->method);
        $this->assertSame(['Old', 'New'], $entry->changes['first_name']);
        $this->assertArrayNotHasKey('last_name', $entry->changes);
        $this->assertArrayNotHasKey('updated_at', $entry->changes);
    }

    public function test_passwords_are_never_stored(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson(route('users.store'), [
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'company_id' => Company::factory()->create()->company_id,
            'employee_id' => 'E-1001',
            'email' => 'pat@example.com',
            'password' => 'Sup3r-secret-pw',
            'password_confirmation' => 'Sup3r-secret-pw',
            'roles' => [$this->customerRoleId()],
        ])->assertOk();

        $entry = $this->last();

        $this->assertSame('users.store', $entry->action);
        $this->assertSame('Pat', $entry->input['first_name']);
        $this->assertSame('[hidden]', $entry->input['password']);
        $this->assertSame('[hidden]', $entry->input['password_confirmation']);
        $this->assertStringNotContainsString('Sup3r-secret-pw', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_a_password_hash_change_is_not_in_the_diff(): void
    {
        $admin = $this->superAdmin();
        // Already has the employee id the request sends, so only the password differs.
        $target = User::factory()->create(['employee_id' => 'E-1001']);

        $this->actingAs($admin)->putJson(route('users.update', $target->id), [
            'first_name' => $target->first_name,
            'last_name' => $target->last_name,
            'company_id' => $target->company_id,
            'employee_id' => 'E-1001',
            'email' => $target->email,
            'password' => 'An0ther-pw-123',
            'password_confirmation' => 'An0ther-pw-123',
            'roles' => [$this->customerRoleId()],
        ])->assertOk();

        $this->assertNull($this->last()->changes);
    }

    public function test_a_deleted_record_keeps_its_label_and_a_hard_deleted_one_says_so(): void
    {
        $admin = $this->superAdmin();
        $roleId = Role::createRole(['name' => 'Temporary Role'], []);

        $this->actingAs($admin)->deleteJson(route('roles.destroy', $roleId))->assertOk();

        $entry = $this->last();

        $this->assertSame('roles.destroy', $entry->action);
        $this->assertSame('Temporary Role', $entry->subject_label);
        $this->assertSame(['present', 'deleted'], $entry->changes['_record']);

        $target = User::factory()->create(['first_name' => 'Gone', 'last_name' => 'Soon']);

        $this->actingAs($admin)->deleteJson(route('users.destroy', $target->id))->assertOk();

        $entry = $this->last();

        $this->assertSame('Gone Soon', $entry->subject_label);
        $this->assertArrayHasKey('deleted_at', $entry->changes);
        $this->assertNull($entry->changes['deleted_at'][0]);
    }

    /* -------- refusals -------- */

    public function test_a_refused_request_is_logged_as_denied(): void
    {
        $actor = $this->userWith('view users');

        $this->actingAs($actor)->postJson(route('users.store'), [])->assertForbidden();

        $entry = $this->last();

        $this->assertSame('users.store', $entry->action);
        $this->assertSame('denied', $entry->outcome);
        $this->assertSame(403, (int) $entry->status);
        $this->assertSame($actor->id, (int) $entry->user_id);
    }

    public function test_a_rule_that_said_no_is_logged_with_the_message(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAs($admin)->deleteJson(route('users.destroy', $other->id))->assertStatus(422);

        $entry = $this->last();

        $this->assertSame('failed', $entry->outcome);
        $this->assertSame('A Super Admin account cannot be deleted.', $entry->message);
    }

    public function test_a_form_post_that_failed_validation_is_a_failure_not_a_success(): void
    {
        $admin = $this->superAdmin();

        // No JSON header: Laravel answers 302 back to the form with the errors flashed.
        $this->actingAs($admin)->post(route('users.store'), [])->assertRedirect();

        $entry = $this->last();

        $this->assertSame('failed', $entry->outcome);
        $this->assertNotEmpty($entry->message);
    }

    /* -------- sign in / out -------- */

    public function test_sign_in_sign_out_and_a_failed_sign_in_are_logged(): void
    {
        $user = User::factory()->create(['email' => 'cook@example.com']);

        $this->post('/login', ['email' => 'cook@example.com', 'password' => 'wrong-password']);

        $entry = $this->last();
        $this->assertSame('auth', $entry->event);
        $this->assertSame('auth.failed', $entry->action);
        $this->assertSame('failed', $entry->outcome);
        $this->assertSame('cook@example.com', $entry->user_email);
        $this->assertStringNotContainsString('wrong-password', json_encode(DB::table('audit_logs')->get()));

        $this->post('/login', ['email' => 'cook@example.com', 'password' => 'password']);

        $entry = $this->last();
        $this->assertSame('auth.login', $entry->action);
        $this->assertSame('Signed in', $entry->description);
        $this->assertSame($user->id, (int) $entry->user_id);

        $this->post('/logout');

        $this->assertSame('auth.logout', $this->last()->action);
        // Signing out is ONE entry (the auth event), not a second one from the request.
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'like', '%logout%')->count());
    }

    public function test_a_guest_request_is_not_logged(): void
    {
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();

        $this->assertSame(0, $this->rows());
    }

    /* -------- page views -------- */

    public function test_with_views_switched_on_a_page_view_is_logged_but_a_table_feed_or_a_poll_is_not(): void
    {
        config(['audit.log_views' => true]);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/users')->assertOk();

        $entry = $this->last();
        $this->assertSame('view', $entry->event);
        $this->assertSame('users.index', $entry->action);
        $this->assertSame('Viewed Users', $entry->description);
        $this->assertNull($entry->input);

        $before = $this->rows();

        $this->actingAs($admin)->getJson('/users', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->actingAs($admin)->getJson(route('notifications.feed'))->assertOk();

        $this->assertSame($before, $this->rows());
    }

    public function test_navigation_is_not_logged_by_default_but_changes_and_sign_ins_are(): void
    {
        $this->assertFalse(config('audit.log_views'));

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/users')->assertOk();
        $this->assertSame(0, $this->rows());

        $this->actingAs($admin)->deleteJson(route('users.destroy', User::factory()->create()->id))->assertOk();
        $this->assertSame(1, $this->rows());

        // Signing in and out do not depend on the views switch.
        User::factory()->create(['email' => 'cook@example.com']);
        // actingAs() left the admin signed in, and /login is for guests.
        auth()->logout();
        DB::table('audit_logs')->delete();

        $this->post('/login', ['email' => 'cook@example.com', 'password' => 'password']);
        $this->post('/logout');

        $this->assertSame(['auth.login', 'auth.logout'], DB::table('audit_logs')->where('event', 'auth')->orderBy('id')->pluck('action')->all());
    }

    public function test_the_whole_trail_can_be_switched_off(): void
    {
        config(['audit.enabled' => false]);

        $this->actingAs($this->superAdmin())->get('/users')->assertOk();
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'x']);

        $this->assertSame(0, $this->rows());
    }

    public function test_a_super_admin_self_delete_attempt_is_logged_as_failed(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->delete('/profile', ['password' => 'password'])->assertRedirect(route('profile.edit'));

        $entry = $this->last();

        $this->assertSame('profile.destroy', $entry->action);
        $this->assertSame('failed', $entry->outcome);
        $this->assertSame('[hidden]', $entry->input['password']);
    }

    /* -------- it never gets in the way -------- */

    public function test_a_failure_in_the_audit_trail_never_reaches_the_person(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        Schema::drop('audit_logs');

        $this->actingAs($admin)->deleteJson(route('users.destroy', $target->id))->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    /* -------- the viewer -------- */

    public function test_the_viewer_needs_its_permission(): void
    {
        $entryId = DB::table('audit_logs')->insertGetId([
            'event' => 'action', 'action' => 'x', 'description' => 'x', 'outcome' => 'success', 'created_at' => now(),
        ]);

        $nobody = $this->userWith('view users');

        $this->actingAs($nobody)->get(route('audit-logs.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('audit-logs.show', $entryId))->assertForbidden();
        $this->actingAs($nobody)->get(route('audit-logs.export'))->assertForbidden();

        $auditor = $this->userWith('view audit-logs');

        $this->actingAs($auditor)->get(route('audit-logs.index'))->assertOk();
        $this->actingAs($auditor)->get(route('audit-logs.show', $entryId))->assertOk();
    }

    public function test_the_list_shows_entries_and_filters_narrow_it(): void
    {
        $auditor = $this->userWith('view audit-logs');

        AuditLog::record(['user_id' => 11, 'user_name' => 'Ana Cook', 'event' => 'action', 'action' => 'dishes.update', 'description' => 'Edited a dish', 'subject_label' => 'Adobo']);
        AuditLog::record(['user_id' => 12, 'user_name' => 'Ben Guest', 'event' => 'auth', 'action' => 'auth.failed', 'description' => 'Failed sign in', 'outcome' => 'failed']);
        DB::table('audit_logs')->where('action', 'auth.failed')->update(['created_at' => now()->subDays(10)]);

        $this->actingAs($auditor)->get(route('audit-logs.index'))
            ->assertOk()->assertSee('Edited a dish')->assertSee('Failed sign in');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['event' => 'auth']))
            ->assertOk()->assertSee('Failed sign in')->assertDontSee('Edited a dish');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['outcome' => 'failed']))
            ->assertOk()->assertSee('Failed sign in')->assertDontSee('Edited a dish');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['user' => 11]))
            ->assertOk()->assertSee('Edited a dish')->assertDontSee('Failed sign in');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['q' => 'adobo']))
            ->assertOk()->assertSee('Edited a dish')->assertDontSee('Failed sign in');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['from' => now()->subDays(2)->format('Y-m-d')]))
            ->assertOk()->assertSee('Edited a dish')->assertDontSee('Failed sign in');

        $this->actingAs($auditor)->get(route('audit-logs.index', ['to' => now()->subDays(5)->format('Y-m-d')]))
            ->assertOk()->assertSee('Failed sign in')->assertDontSee('Edited a dish');
    }

    public function test_a_bad_filter_is_a_validation_error(): void
    {
        $this->actingAs($this->userWith('view audit-logs'))
            ->get(route('audit-logs.index', ['event' => 'nonsense']))
            ->assertSessionHasErrors('event');
    }

    public function test_one_entry_shows_what_changed_and_what_was_sent(): void
    {
        $auditor = $this->userWith('view audit-logs');

        AuditLog::record([
            'event' => 'action', 'action' => 'dishes.update', 'description' => 'Edited a dish', 'subject_label' => 'Adobo',
            'changes' => ['price' => ['50.00', '60.00']], 'input' => ['dish_name' => 'Adobo <b>'],
        ]);

        $id = DB::table('audit_logs')->max('id');

        $this->actingAs($auditor)->get(route('audit-logs.show', $id))
            ->assertOk()
            ->assertSee('50.00')->assertSee('60.00')
            // Stored text is escaped, never printed as markup.
            ->assertSee('Adobo &lt;b&gt;', false)
            ->assertDontSee('Adobo <b>', false);

        $this->actingAs($auditor)->get(route('audit-logs.show', 999999))->assertNotFound();
    }

    public function test_there_is_no_way_to_change_or_delete_an_entry(): void
    {
        $auditor = $this->superAdmin();
        $id = DB::table('audit_logs')->insertGetId([
            'event' => 'action', 'action' => 'x', 'description' => 'x', 'outcome' => 'success', 'created_at' => now(),
        ]);

        $this->actingAs($auditor)->delete('/audit-logs/' . $id)->assertStatus(405);
        $this->actingAs($auditor)->put('/audit-logs/' . $id)->assertStatus(405);
        $this->actingAs($auditor)->post('/audit-logs')->assertStatus(405);

        $this->assertTrue(DB::table('audit_logs')->where('id', $id)->exists());
    }

    public function test_the_export_is_a_csv_that_cannot_run_a_formula_and_is_itself_logged(): void
    {
        $auditor = $this->userWith('view audit-logs');

        AuditLog::record(['event' => 'action', 'action' => 'x', 'description' => '=HYPERLINK("http://evil")']);
        AuditLog::record(['event' => 'auth', 'action' => 'auth.login', 'description' => 'Signed in']);

        $response = $this->actingAs($auditor)->get(route('audit-logs.export', ['event' => 'action']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('id,created_at,user_name', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Signed in', $csv);

        $entry = $this->last();
        $this->assertSame('audit-logs.export', $entry->action);
        $this->assertSame('action', $entry->event);
    }

    /* -------- retention -------- */

    public function test_prune_deletes_only_what_is_older_than_the_window_and_says_so(): void
    {
        AuditLog::record(['event' => 'action', 'action' => 'old', 'description' => 'Old']);
        AuditLog::record(['event' => 'action', 'action' => 'new', 'description' => 'New']);
        DB::table('audit_logs')->where('action', 'old')->update(['created_at' => now()->subDays(40)]);

        $this->artisan('audit:prune', ['--days' => 30])->assertSuccessful();

        $this->assertFalse(DB::table('audit_logs')->where('action', 'old')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'new')->exists());

        $system = $this->last();
        $this->assertSame('system', $system->event);
        $this->assertSame('audit.prune', $system->action);
    }

    public function test_a_retention_of_zero_keeps_everything(): void
    {
        config(['audit.retention_days' => 0]);

        AuditLog::record(['event' => 'action', 'action' => 'old', 'description' => 'Old']);
        DB::table('audit_logs')->update(['created_at' => now()->subYears(5)]);

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertSame(1, $this->rows());
    }
}
