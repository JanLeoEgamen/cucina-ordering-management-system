<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The app's name and logo, set in System Configuration and shown on every page, error page and
// email (Setting::brand()).
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private const BUILT_IN_MARK = 'viewBox="0 0 48 48"';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['session_start_time' => '08:30', 'session_end_time' => '13:00'];
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->superAdmin())->put(route('settings.update'), $this->payload($overrides), ['Accept' => 'application/json']);
    }

    // A page as a visitor who is not signed in (save() signs in as an admin, and /login would then redirect away).
    private function guestGet(string $uri)
    {
        auth()->forgetGuards();

        return $this->get($uri);
    }

    private function logoPath(): string
    {
        return (string) Setting::get(Setting::APP_LOGO);
    }

    public function test_until_something_is_saved_the_app_is_called_cucina_with_the_built_in_mark(): void
    {
        $this->assertSame('Cucina', Setting::appName());
        $this->assertNull(Setting::appLogoUrl());

        $this->guestGet('/login')
            ->assertOk()
            ->assertSee('<title>Cucina</title>', false)
            ->assertSee(self::BUILT_IN_MARK, false);
    }

    public function test_the_saved_name_is_trimmed_and_shown_on_every_kind_of_page(): void
    {
        $this->save(['app_name' => '  Casa Verde  '])->assertOk();

        $this->assertSame('Casa Verde', Setting::appName());

        // Signed out pages, the app's sidebar and title, and an error page.
        $this->guestGet('/login')->assertSee('<title>Casa Verde</title>', false)->assertDontSee('<title>Cucina</title>', false);
        $this->guestGet(route('welcome'))->assertSee('Casa Verde &middot;', false)->assertSee('Casa Verde serves in ordering sessions', false);
        $this->actingAs($this->superAdmin())->get('/profile')->assertSee('<title>Casa Verde</title>', false)->assertSee('>Casa Verde</span>', false);
        $this->guestGet('/no-such-page')->assertNotFound()->assertSee('Casa Verde', false);
    }

    public function test_the_name_is_escaped_wherever_it_is_printed(): void
    {
        $this->save(['app_name' => '<b>Bold</b> & "co"'])->assertOk();

        $this->guestGet('/login')
            ->assertDontSee('<b>Bold</b>', false)
            ->assertSee('&lt;b&gt;Bold&lt;/b&gt;', false);
    }

    public function test_an_empty_or_too_long_name_is_refused_and_a_missing_one_changes_nothing(): void
    {
        $this->save(['app_name' => 'Casa Verde'])->assertOk();

        $this->save(['app_name' => ''])->assertStatus(422)->assertJsonValidationErrors('app_name');
        $this->save(['app_name' => '   '])->assertStatus(422)->assertJsonValidationErrors('app_name');
        $this->save(['app_name' => str_repeat('x', 61)])->assertStatus(422)->assertJsonValidationErrors('app_name');
        $this->assertSame('Casa Verde', Setting::appName());

        // A caller that does not send the field (an older client) leaves it as it is.
        $this->save()->assertOk();
        $this->assertSame('Casa Verde', Setting::appName());

        // 60 characters is fine.
        $this->save(['app_name' => str_repeat('x', 60)])->assertOk();
    }

    public function test_only_edit_settings_may_change_the_brand(): void
    {
        $this->actingAs($this->userWith('view settings'))
            ->put(route('settings.update'), $this->payload([
                'app_name' => 'Hijack',
                'app_logo' => UploadedFile::fake()->image('logo.png'),
            ]), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame('Cucina', Setting::appName());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_uploaded_logo_is_stored_and_replaces_the_built_in_mark_everywhere_but_the_dish_tiles(): void
    {
        $this->save(['app_logo' => UploadedFile::fake()->image('logo.png', 80, 80)])->assertOk();

        $path = $this->logoPath();
        $this->assertStringStartsWith('branding/', $path);
        Storage::disk('public')->assertExists($path);

        $url = Storage::disk('public')->url($path);
        $this->guestGet('/login')->assertSee('<img src="' . $url . '"', false)->assertDontSee(self::BUILT_IN_MARK, false)
            // Softly rounded corners, a square crop that fills the box.
            ->assertSee('aspect-square rounded-lg object-cover', false);
        $this->actingAs($this->superAdmin())->get('/profile')->assertSee('<img src="' . $url . '"', false);

        // The page that sets it shows it in its preview too.
        $this->actingAs($this->superAdmin())->get(route('settings.index'))->assertSee($url, false)->assertSee('id="remove-app-logo"', false);
    }

    public function test_a_new_logo_deletes_the_old_file_and_removing_it_goes_back_to_the_built_in_mark(): void
    {
        $this->save(['app_logo' => UploadedFile::fake()->image('one.png')])->assertOk();
        $first = $this->logoPath();

        $this->save(['app_logo' => UploadedFile::fake()->image('two.jpg')])->assertOk();
        $second = $this->logoPath();

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->save(['remove_app_logo' => '1'])->assertOk();

        $this->assertSame('', $this->logoPath());
        Storage::disk('public')->assertMissing($second);
        $this->guestGet('/login')->assertSee(self::BUILT_IN_MARK, false);
    }

    public function test_saving_without_a_logo_field_keeps_the_logo(): void
    {
        $this->save(['app_logo' => UploadedFile::fake()->image('one.png')])->assertOk();
        $path = $this->logoPath();

        $this->save(['app_name' => 'Other'])->assertOk();

        $this->assertSame($path, $this->logoPath());
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_new_file_wins_over_the_remove_box(): void
    {
        $this->save(['app_logo' => UploadedFile::fake()->image('one.png')])->assertOk();

        $this->save(['app_logo' => UploadedFile::fake()->image('two.png'), 'remove_app_logo' => '1'])->assertOk();

        $this->assertNotSame('', $this->logoPath());
        Storage::disk('public')->assertExists($this->logoPath());
        $this->assertCount(1, Storage::disk('public')->allFiles('branding'));
    }

    public function test_only_pictures_up_to_2_mb_are_accepted_and_a_refusal_stores_nothing(): void
    {
        $refused = [
            'a pdf' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
            // An svg can carry scripts and is served from this site's own address.
            'an svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'a gif' => UploadedFile::fake()->image('logo.gif'),
            'too large' => UploadedFile::fake()->image('big.png')->size(2500),
        ];

        foreach ($refused as $label => $file) {
            $this->save(['app_name' => 'Should not stick', 'app_logo' => $file])
                ->assertStatus(422)
                ->assertJsonValidationErrors('app_logo');

            $this->assertSame('', $this->logoPath(), $label);
        }

        // Nothing saved, nothing stored: the whole save is validated first.
        $this->assertSame('Cucina', Setting::appName());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_logo_whose_file_has_gone_reads_as_the_built_in_mark(): void
    {
        $this->save(['app_logo' => UploadedFile::fake()->image('one.png')])->assertOk();
        Storage::disk('public')->delete($this->logoPath());

        $this->assertNull(Setting::appLogoUrl());
        $this->guestGet('/login')->assertOk()->assertSee(self::BUILT_IN_MARK, false);
    }

    public function test_a_page_never_fails_because_the_brand_cannot_be_read(): void
    {
        // An error page asks for the brand too: a database that is down must not make it a second error.
        Schema::drop('settings');

        $this->guestGet('/no-such-page')->assertNotFound()->assertSee('Cucina', false);
    }

    public function test_the_brand_is_read_once_per_web_request(): void
    {
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, '"settings"')) {
                $queries++;
            }
        });

        $this->actingAs($this->superAdmin())->get('/profile')->assertOk();

        // The sidebar, the phone bar, the title: many places, but one query for the two keys.
        $this->assertSame(1, $queries);
    }

    public function test_notification_emails_carry_the_saved_name(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        Setting::setAppName('Casa Verde');

        (new \App\Jobs\SendNotificationEmails([
            ['user_id' => 1, 'email' => 'jane@example.com', 'title' => 'Order placed', 'body' => 'Thanks', 'url' => '/my-orders'],
        ]))->handle();

        // Both the footer line of the message and the header of Laravel's mail layout.
        $this->assertSame('Casa Verde', config('app.name'));
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NotificationMail::class, function (\App\Mail\NotificationMail $mail) {
            $mail->assertSeeInHtml('Casa Verde');

            return true;
        });
    }

    public function test_the_settings_page_offers_the_fields_to_those_who_may_see_it(): void
    {
        Setting::setAppName('Casa Verde');

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('value="Casa Verde"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('id="app-logo"', false)
            // No custom logo yet: nothing to remove.
            ->assertDontSee('id="remove-app-logo"', false);
    }
}
