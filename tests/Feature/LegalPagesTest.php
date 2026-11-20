<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// The public legal pages: the privacy policy (GET /privacy-policy) and the terms of service (GET /terms-of-service), and
// their System Configuration cards. The text is a
// Markdown setting; the built-in text stands in until an admin saves their own.
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $privacy, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->superAdmin())->putJson(
            route('settings.update'),
            $privacy + ['session_start_time' => '09:00', 'session_end_time' => '17:00']
        );
    }

    /* -------- the public page -------- */

    public function test_anyone_can_read_the_built_in_policy_with_the_app_name(): void
    {
        Setting::setAppName('Casa Verde');

        // Not signed in: no redirect to login.
        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('What we collect')
            ->assertSee('Casa Verde')
            ->assertDontSee('{app_name}', false)
            // Nothing is saved yet, so there is no "last updated" and no contact block.
            ->assertDontSee('Last updated')
            ->assertDontSee('mailto:', false);
    }

    public function test_a_user_who_has_not_finished_their_profile_can_still_read_it(): void
    {
        $this->actingAs(User::factory()->profileIncomplete()->create())
            ->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('What we collect');
    }

    public function test_the_saved_text_replaces_the_built_in_one_and_is_markdown(): void
    {
        Setting::setAppName('Casa Verde');

        $this->save(['privacy_policy_content' => "## Our promise\n\nWe keep **very little** about {app_name} guests.\n\n- names\n- emails"])
            ->assertOk();

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('<h2>Our promise</h2>', false)
            ->assertSee('<strong>very little</strong>', false)
            ->assertSee('<li>names</li>', false)
            ->assertSee('Casa Verde guests')
            ->assertDontSee('What we collect');
    }

    public function test_html_and_script_links_in_the_text_are_not_run(): void
    {
        $this->save(['privacy_policy_content' => "<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))"])
            ->assertOk();

        $page = $this->get(route('privacy-policy'))->assertOk();

        $page->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_the_app_name_cannot_break_out_of_the_text(): void
    {
        Setting::setAppName('A <b>&</b> B');

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('A &lt;b&gt;&amp;&lt;/b&gt; B', false)
            ->assertDontSee('<b>&</b>', false);
    }

    public function test_the_contact_email_is_shown_as_a_link_only_when_set(): void
    {
        $this->save(['privacy_policy_contact_email' => 'privacy@casaverde.test'])->assertOk();

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('href="mailto:privacy@casaverde.test"', false);

        $this->save(['privacy_policy_contact_email' => ''])->assertOk();

        $this->get(route('privacy-policy'))->assertOk()->assertDontSee('mailto:', false);
    }

    public function test_last_updated_moves_with_the_policy_and_not_with_other_settings(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $this->save(['privacy_policy_content' => 'First version.'])->assertOk();

        $this->get(route('privacy-policy'))->assertSee('Last updated March 5, 2026');

        // Saving the same form on a later day, with the same policy, does not touch the date.
        Carbon::setTestNow('2026-04-09 10:00:00');
        $this->save(['privacy_policy_content' => 'First version.', 'session_end_time' => '18:00'])->assertOk();

        $this->get(route('privacy-policy'))->assertSee('Last updated March 5, 2026');

        // A real edit does.
        $this->save(['privacy_policy_content' => 'Second version.'])->assertOk();

        $this->get(route('privacy-policy'))->assertSee('Last updated April 9, 2026');

        Carbon::setTestNow();
    }

    /* -------- System Configuration -------- */

    public function test_the_configuration_page_shows_the_built_in_text_to_edit(): void
    {
        $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="privacy_policy_content"', false)
            ->assertSee('name="privacy_policy_contact_email"', false)
            ->assertSee('## What we collect', false)
            ->assertSee('built-in text');
    }

    public function test_the_configuration_page_shows_the_saved_text_and_address(): void
    {
        $this->save(['privacy_policy_content' => 'My own words.', 'privacy_policy_contact_email' => 'me@example.com'])->assertOk();

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('My own words.')
            ->assertSee('value="me@example.com"', false)
            ->assertSee('This is your own text')
            ->assertDontSee('## What we collect', false);
    }

    public function test_saving_the_policy_needs_edit_settings(): void
    {
        $this->save(['privacy_policy_content' => 'Sneaky.'], $this->userWith('view settings'))->assertForbidden();

        $this->assertSame('', Setting::get(Setting::PRIVACY_POLICY_CONTENT));
        $this->assertFalse(Setting::legalPage('privacy-policy')['custom']);
    }

    public function test_an_empty_text_or_the_built_in_text_goes_back_to_following_the_built_in_policy(): void
    {
        $this->save(['privacy_policy_content' => 'Custom.'])->assertOk();
        $this->assertTrue(Setting::legalPage('privacy-policy')['custom']);

        $this->save(['privacy_policy_content' => ''])->assertOk();
        $this->assertFalse(Setting::legalPage('privacy-policy')['custom']);
        $this->get(route('privacy-policy'))->assertSee('What we collect');

        // The browser sends line breaks as CRLF; the form is saved with the built-in text untouched.
        $this->save(['privacy_policy_content' => 'Custom.'])->assertOk();
        $this->save(['privacy_policy_content' => str_replace("\n", "\r\n", Setting::defaultLegalContent('privacy-policy'))])->assertOk();

        $this->assertFalse(Setting::legalPage('privacy-policy')['custom']);
        $this->assertSame('', Setting::get(Setting::PRIVACY_POLICY_CONTENT));
    }

    public function test_a_save_that_leaves_the_policy_out_changes_nothing(): void
    {
        $this->save(['privacy_policy_content' => 'Keep me.', 'privacy_policy_contact_email' => 'keep@example.com'])->assertOk();

        $this->save(['session_end_time' => '18:00'])->assertOk();
        $this->assertSame('Keep me.', Setting::legalPage('privacy-policy')['content']);
        $this->assertSame('keep@example.com', Setting::legalPage('privacy-policy')['contact_email']);

        // Only one of the two sent: the other stays.
        $this->save(['privacy_policy_contact_email' => 'new@example.com'])->assertOk();
        $this->assertSame('Keep me.', Setting::legalPage('privacy-policy')['content']);
        $this->assertSame('new@example.com', Setting::legalPage('privacy-policy')['contact_email']);
    }

    public function test_the_policy_is_validated(): void
    {
        $this->save(['privacy_policy_contact_email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('privacy_policy_contact_email');

        $this->save(['privacy_policy_content' => str_repeat('a', Setting::LEGAL_MAX_LENGTH + 1)])
            ->assertStatus(422)->assertJsonValidationErrors('privacy_policy_content');

        // The longest allowed text fits the column, even with four-byte characters.
        $this->save(['privacy_policy_content' => str_repeat('😀', Setting::LEGAL_MAX_LENGTH)])->assertOk();
        $this->assertSame(Setting::LEGAL_MAX_LENGTH, mb_strlen(Setting::legalPage('privacy-policy')['content']));
    }

    /* -------- terms of service: its own page, own text -------- */

    public function test_the_terms_of_service_page_is_separate_from_the_privacy_policy(): void
    {
        Setting::setAppName('Casa Verde');

        // Public, with its own built-in text about ordering, not about personal data.
        $this->get(route('terms-of-service'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Cancelling an order')
            ->assertSee('Casa Verde')
            ->assertDontSee('{app_name}', false)
            ->assertDontSee('What we collect');

        $this->get(route('privacy-policy'))->assertOk()->assertSee('What we collect')->assertDontSee('Cancelling an order');
    }

    public function test_each_page_keeps_its_own_text_email_and_date(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $this->save(['terms_of_service_content' => '## House rules', 'terms_of_service_contact_email' => 'rules@casaverde.test'])->assertOk();

        // The privacy policy is untouched: built-in text, no date, no contact.
        $privacy = Setting::legalPage('privacy-policy');
        $this->assertFalse($privacy['custom']);
        $this->assertNull($privacy['updated_at']);
        $this->assertSame('', $privacy['contact_email']);

        $this->get(route('terms-of-service'))
            ->assertSee('<h2>House rules</h2>', false)
            ->assertSee('Last updated March 5, 2026')
            ->assertSee('href="mailto:rules@casaverde.test"', false);

        $this->get(route('privacy-policy'))->assertDontSee('Last updated')->assertDontSee('mailto:', false);

        Carbon::setTestNow('2026-04-09 10:00:00');
        $this->save(['privacy_policy_content' => 'Privacy words.'])->assertOk();

        $this->get(route('privacy-policy'))->assertSee('Last updated April 9, 2026');
        $this->get(route('terms-of-service'))->assertSee('Last updated March 5, 2026');

        Carbon::setTestNow();
    }

    public function test_the_terms_of_service_card_is_on_the_configuration_page_and_editable_only_with_edit_settings(): void
    {
        $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="terms_of_service_content"', false)
            ->assertSee('name="terms_of_service_contact_email"', false)
            ->assertSee('## Cancelling an order', false);

        $this->save(['terms_of_service_content' => 'Sneaky.'], $this->userWith('view settings'))->assertForbidden();
        $this->assertFalse(Setting::legalPage('terms-of-service')['custom']);
    }

    public function test_the_terms_of_service_are_validated_and_reset_like_the_privacy_policy(): void
    {
        $this->save(['terms_of_service_contact_email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('terms_of_service_contact_email');
        $this->save(['terms_of_service_content' => str_repeat('a', Setting::LEGAL_MAX_LENGTH + 1)])->assertStatus(422)->assertJsonValidationErrors('terms_of_service_content');

        $this->save(['terms_of_service_content' => 'Mine.'])->assertOk();
        $this->assertTrue(Setting::legalPage('terms-of-service')['custom']);

        $this->save(['terms_of_service_content' => ''])->assertOk();
        $this->assertFalse(Setting::legalPage('terms-of-service')['custom']);
        $this->get(route('terms-of-service'))->assertSee('Cancelling an order');
    }

    /* -------- links to them -------- */

    public function test_the_pages_are_linked_from_the_public_pages_and_the_account_menu(): void
    {
        foreach (['privacy-policy', 'terms-of-service'] as $page) {
            $link = 'href="' . route($page) . '"';

            $this->get(route('welcome'))->assertOk()->assertSee($link, false);
            $this->get(route('login'))->assertOk()->assertSee($link, false);
            $this->get(route('register'))->assertOk()->assertSee($link, false)->assertSee('By creating an account');

            $this->actingAs($this->userWith('view settings'))->get(route('dashboard'))->assertOk()->assertSee($link, false);
            auth()->logout();
        }
    }
}
