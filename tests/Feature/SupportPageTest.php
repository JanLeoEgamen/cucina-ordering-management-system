<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The public Help and Support page (GET /support) and its System Configuration card: a Markdown help
// text (the built-in one until an admin saves their own) and the ways to reach the shop.
class SupportPageTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $support, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->superAdmin())->putJson(
            route('settings.update'),
            $support + ['session_start_time' => '09:00', 'session_end_time' => '17:00']
        );
    }

    /* -------- the public page -------- */

    public function test_a_visitor_reads_the_built_in_help_with_the_app_name(): void
    {
        Setting::setAppName('Casa Verde');

        // Not signed in: no redirect to login.
        $this->get(route('support'))
            ->assertOk()
            ->assertSee('Help and Support')
            ->assertSee('How do I order?')
            ->assertSee('Forgot your password?')
            ->assertSee('Casa Verde')
            ->assertDontSee('{app_name}', false)
            // Nothing is configured: no contact block.
            ->assertDontSee('Contact us')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('tel:', false);
    }

    public function test_a_user_who_has_not_finished_their_profile_can_still_read_it(): void
    {
        $this->actingAs(User::factory()->profileIncomplete()->create())
            ->get(route('support'))->assertOk()->assertSee('How do I order?');
    }

    public function test_the_contact_details_show_only_when_filled_in(): void
    {
        $this->save(['support_email' => 'help@casaverde.test'])->assertOk();

        $page = $this->get(route('support'))->assertOk();
        $page->assertSee('Contact us')->assertSee('href="mailto:help@casaverde.test"', false);
        $page->assertDontSee('tel:', false)->assertDontSee('Hours');

        $this->save(['support_phone' => '+63 (917) 123-4567', 'support_hours' => 'Mon to Fri, 8:00 to 17:00'])->assertOk();

        // The tel: link carries digits and the plus only; the person sees what was typed.
        $this->get(route('support'))
            ->assertSee('href="tel:+639171234567"', false)
            ->assertSee('+63 (917) 123-4567')
            ->assertSee('Mon to Fri, 8:00 to 17:00')
            ->assertSee('href="mailto:help@casaverde.test"', false);

        // Emptying all three removes the block.
        $this->save(['support_email' => '', 'support_phone' => '', 'support_hours' => ''])->assertOk();

        $this->get(route('support'))->assertDontSee('Contact us');
    }

    public function test_the_hours_are_escaped(): void
    {
        $this->save(['support_hours' => '<script>alert(1)</script> daily'])->assertOk();

        $this->get(route('support'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; daily', false);
    }

    public function test_the_saved_text_replaces_the_built_in_one_and_is_safe_markdown(): void
    {
        Setting::setAppName('Casa Verde');

        $this->save(['support_content' => "## Open late\n\nAsk **anyone** at {app_name}.\n\n<img src=x onerror=alert(1)>\n\n[go](javascript:alert(2))"])
            ->assertOk();

        $this->get(route('support'))
            ->assertOk()
            ->assertSee('<h2>Open late</h2>', false)
            ->assertSee('<strong>anyone</strong>', false)
            ->assertSee('Casa Verde')
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('href="javascript:', false)
            ->assertDontSee('How do I order?');
    }

    /* -------- System Configuration -------- */

    public function test_the_configuration_page_has_the_support_card(): void
    {
        $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="support_email"', false)
            ->assertSee('name="support_phone"', false)
            ->assertSee('name="support_hours"', false)
            ->assertSee('name="support_content"', false)
            ->assertSee('**How do I order?**', false)
            ->assertSee(route('support'), false);

        $this->save(['support_content' => 'My help.', 'support_email' => 'me@example.com'])->assertOk();

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertSee('My help.')
            ->assertSee('value="me@example.com"', false)
            ->assertDontSee('**How do I order?**', false);
    }

    public function test_saving_the_support_page_needs_edit_settings(): void
    {
        $this->save(['support_content' => 'Sneaky.'], $this->userWith('view settings'))->assertForbidden();

        $this->assertFalse(Setting::support()['custom']);
    }

    public function test_the_support_fields_are_validated(): void
    {
        $this->save(['support_email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('support_email');
        $this->save(['support_phone' => 'call me maybe'])->assertStatus(422)->assertJsonValidationErrors('support_phone');
        $this->save(['support_phone' => str_repeat('1', 41)])->assertStatus(422)->assertJsonValidationErrors('support_phone');
        $this->save(['support_hours' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors('support_hours');
        $this->save(['support_content' => str_repeat('a', Setting::LEGAL_MAX_LENGTH + 1)])->assertStatus(422)->assertJsonValidationErrors('support_content');

        // A refused save changes nothing.
        $this->assertSame('', Setting::support()['email']);
    }

    public function test_an_empty_text_or_the_built_in_text_goes_back_to_following_the_built_in_help(): void
    {
        $this->save(['support_content' => 'Custom.'])->assertOk();
        $this->assertTrue(Setting::support()['custom']);

        $this->save(['support_content' => ''])->assertOk();
        $this->assertFalse(Setting::support()['custom']);
        $this->get(route('support'))->assertSee('How do I order?');

        // The browser sends CRLF line breaks; the untouched built-in text is stored as "follow it".
        $this->save(['support_content' => 'Custom.'])->assertOk();
        $this->save(['support_content' => str_replace("\n", "\r\n", Setting::defaultSupportContent())])->assertOk();

        $this->assertFalse(Setting::support()['custom']);
        $this->assertSame('', Setting::get(Setting::SUPPORT_CONTENT));
    }

    public function test_a_save_that_leaves_the_fields_out_changes_nothing(): void
    {
        $this->save(['support_content' => 'Keep me.', 'support_email' => 'keep@example.com', 'support_phone' => '12345', 'support_hours' => 'Always'])->assertOk();

        $this->save(['session_end_time' => '18:00'])->assertOk();
        $this->assertSame(['Keep me.', 'keep@example.com', '12345', 'Always'], array_values(array_diff_key(Setting::support(), ['custom' => 1])));

        // Only one field sent: the others stay.
        $this->save(['support_phone' => '67890'])->assertOk();
        $support = Setting::support();
        $this->assertSame('67890', $support['phone']);
        $this->assertSame('keep@example.com', $support['email']);
        $this->assertSame('Keep me.', $support['content']);
    }

    /* -------- links to it -------- */

    public function test_the_page_is_linked_from_the_public_pages_and_the_account_menu(): void
    {
        $link = 'href="' . route('support') . '"';

        $this->get(route('welcome'))->assertOk()->assertSee($link, false);
        $this->get(route('login'))->assertOk()->assertSee($link, false);
        $this->get(route('privacy-policy'))->assertOk()->assertSee($link, false);
        $this->get(route('terms-of-service'))->assertOk()->assertSee($link, false);
        $this->get(route('support'))->assertOk()->assertSee('href="' . route('privacy-policy') . '"', false);

        $this->actingAs($this->userWith('view settings'))->get(route('dashboard'))->assertOk()->assertSee($link, false);
    }
}
