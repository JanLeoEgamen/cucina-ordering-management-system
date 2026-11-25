<?php

namespace Tests\Feature;

use App\Jobs\SendGoogleChatMessages;
use App\Jobs\SendNotificationEmails;
use App\Mail\NotificationMail;
use App\Models\Dish;
use App\Models\DishType;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// New dish alerts: the users picked in System Configuration -> Notifications are told when a dish
// is added to the Dish Masterlist with the Add Dish form (not by the Excel import, not by an edit).
// The test queue is `sync`, so the job runs inside the request.
class NewDishAlertTest extends TestCase
{
    use RefreshDatabase;

    private ?string $keyFile = null;

    protected function tearDown(): void
    {
        if ($this->keyFile && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        gc_collect_cycles();

        parent::tearDown();
    }

    private function rows(User $user, ?string $type = OrderNotifier::DISH_ADDED)
    {
        return DB::table('user_notifications')
            ->where('user_id', $user->id)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'dish_types' => [DishType::factory()->create(['type_name' => 'Mains'])->dish_type_id],
            'dish_name' => 'Kare-Kare',
            'price' => '120',
            'is_active' => '1',
        ], $overrides);
    }

    public function test_the_picked_users_are_told_when_a_dish_is_added(): void
    {
        $admin = $this->superAdmin();
        $picked = $this->userWith('view dishes');
        $notPicked = $this->userWith('view dishes');
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($admin)->post(route('dishes.store'), $this->payload())->assertOk();

        $row = $this->rows($picked)->first();

        $this->assertNotNull($row);
        $this->assertSame('New dish added: Kare-Kare', $row->title);
        $this->assertStringContainsString('₱120.00', $row->body);
        $this->assertStringContainsString('Mains', $row->body);
        $this->assertStringContainsString('added by ' . $admin->name, $row->body);
        $this->assertSame('/dishes', $row->url);
        $this->assertNull($row->read_at);
        $this->assertCount(0, $this->rows($notPicked, null));
    }

    public function test_the_person_who_added_the_dish_is_not_told(): void
    {
        $admin = $this->superAdmin();
        $other = $this->userWith();
        Setting::setNewDishWatchers([$admin->id, $other->id]);

        $this->actingAs($admin)->post(route('dishes.store'), $this->payload())->assertOk();

        $this->assertCount(0, $this->rows($admin, null));
        $this->assertCount(1, $this->rows($other));
    }

    public function test_nobody_is_told_when_nobody_is_picked(): void
    {
        $this->userWith();

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        $this->assertSame(0, DB::table('user_notifications')->where('type', OrderNotifier::DISH_ADDED)->count());
    }

    public function test_the_ordinary_watchers_are_not_told_about_new_dishes(): void
    {
        $watcher = $this->userWith();
        Setting::setNotificationWatchers([$watcher->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        $this->assertCount(0, $this->rows($watcher, null));
    }

    public function test_a_user_without_view_dishes_is_told_without_a_link(): void
    {
        $picked = $this->userWith('view menu');
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        $this->assertNull($this->rows($picked)->first()->url);
    }

    public function test_a_dish_added_switched_off_still_tells_them_and_says_so(): void
    {
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload(['is_active' => '0']))->assertOk();

        $this->assertStringContainsString('switched off', $this->rows($picked)->first()->body);
    }

    public function test_a_refused_dish_tells_nobody(): void
    {
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->postJson(route('dishes.store'), $this->payload(['dish_name' => '']))->assertStatus(422);

        $this->assertCount(0, $this->rows($picked, null));
    }

    public function test_editing_a_dish_tells_nobody(): void
    {
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);
        $dish = Dish::factory()->create();

        $this->actingAs($this->superAdmin())->put(route('dishes.update', $dish->dish_id), $this->payload(['dish_name' => 'Renamed']))->assertOk();

        $this->assertCount(0, $this->rows($picked, null));
    }

    public function test_the_excel_import_tells_nobody(): void
    {
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);
        DishType::factory()->create(['type_name' => 'Mains']);

        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Dishes')->fromArray([
            ['Dish Name *', 'Dish Types *', 'Price *', 'Description', 'Calories', 'Main Ingredients', 'Comes With Rice', 'Available'],
            ['Imported Adobo', 'Mains', 50, '', '', '', '', 'Yes'],
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'dish-import');
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        $this->actingAs($this->superAdmin())
            ->postJson(route('dishes.import'), ['file' => UploadedFile::fake()->createWithContent('dishes.xlsx', $bytes)])
            ->assertOk();

        $this->assertSame(1, DB::table('dishes')->where('dish_name', 'Imported Adobo')->count());
        $this->assertCount(0, $this->rows($picked, null));
    }

    /* ---- email and Google Chat: switches of their own ------------------- */

    public function test_the_alert_is_in_app_only_until_its_own_switches_are_on(): void
    {
        Mail::fake();
        Bus::fake([SendNotificationEmails::class, SendGoogleChatMessages::class]);
        $this->fakeChatKey();
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->assertFalse(Setting::newDishEmail());
        $this->assertFalse(Setting::newDishGoogleChat());

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        $this->assertCount(1, $this->rows($picked));
        Bus::assertNotDispatched(SendNotificationEmails::class);
        Bus::assertNotDispatched(SendGoogleChatMessages::class);
    }

    public function test_the_email_switch_of_the_alert_sends_the_email_in_the_bells_words(): void
    {
        Mail::fake();
        Setting::setNewDishChannels(true, false);
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($picked->email)
            && $mail->heading === 'New dish added: Kare-Kare');
    }

    public function test_the_order_notification_email_switch_does_not_email_the_alert(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Mail::assertNothingSent();
        $this->assertCount(1, $this->rows($picked));
    }

    public function test_the_alert_email_is_sent_even_when_order_emails_are_off(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(false);
        Setting::setNewDishChannels(true, null);
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_the_google_chat_switch_of_the_alert_sends_the_chat_message_only_to_the_picked(): void
    {
        Bus::fake([SendGoogleChatMessages::class]);
        $this->fakeChatKey();
        Setting::setNewDishChannels(false, true);
        $picked = $this->userWith();
        $other = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Bus::assertDispatched(SendGoogleChatMessages::class, fn (SendGoogleChatMessages $job) => count($job->messages) === 1
            && $job->messages[0]['user_id'] === $picked->id
            && $job->messages[0]['type'] === OrderNotifier::DISH_ADDED
            && $job->messages[0]['title'] === 'New dish added: Kare-Kare');
        $this->assertCount(0, $this->rows($other, null));
    }

    public function test_the_order_notification_chat_switch_does_not_chat_the_alert(): void
    {
        Bus::fake([SendGoogleChatMessages::class]);
        $this->fakeChatKey();
        Setting::setGoogleChatNotifications(true);
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Bus::assertNotDispatched(SendGoogleChatMessages::class);
    }

    public function test_the_alert_chat_switch_does_nothing_without_the_chat_key(): void
    {
        Bus::fake([SendGoogleChatMessages::class]);
        config(['services.google_chat.credentials' => null]);
        Setting::setNewDishChannels(false, true);
        $picked = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())->post(route('dishes.store'), $this->payload())->assertOk();

        Bus::assertNotDispatched(SendGoogleChatMessages::class);
        $this->assertCount(1, $this->rows($picked));
    }

    public function test_the_alert_switches_do_not_turn_on_the_order_notification_ones(): void
    {
        Setting::setNewDishChannels(true, true);
        $this->assertFalse(Setting::emailNotifications());
        $this->assertFalse(Setting::googleChatNotifications());
    }

    // The Chat app counts as set up when its key file is readable (the job itself is faked).
    private function fakeChatKey(): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'chatkey');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'chat@example.iam.gserviceaccount.com', 'private_key' => 'not-a-real-key']));
        config(['services.google_chat.credentials' => $this->keyFile]);
    }

    public function test_the_page_shows_the_two_switches_in_their_state(): void
    {
        Setting::setNewDishChannels(true, false);

        $html = $this->actingAs($this->superAdmin())->get(route('settings.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="new_dish_email"[^>]*checked/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="new_dish_google_chat"[^>]*checked/s', $html);
    }

    public function test_the_switches_are_saved_and_left_alone_when_not_sent(): void
    {
        $this->actingAs($this->superAdmin());

        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_email' => 1, 'new_dish_google_chat' => 0]))->assertOk();
        $this->assertTrue(Setting::newDishEmail());
        $this->assertFalse(Setting::newDishGoogleChat());

        // A caller that sends neither changes nothing.
        $this->putJson(route('settings.update'), $this->settingsPayload())->assertOk();
        $this->assertTrue(Setting::newDishEmail());
        $this->assertFalse(Setting::newDishGoogleChat());

        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_email' => 0, 'new_dish_google_chat' => 1]))->assertOk();
        $this->assertFalse(Setting::newDishEmail());
        $this->assertTrue(Setting::newDishGoogleChat());

        // The switches of the order notifications are not touched by them.
        $this->assertFalse(Setting::emailNotifications());
        $this->assertFalse(Setting::googleChatNotifications());
    }

    public function test_only_editors_can_change_the_switches(): void
    {
        $this->actingAs($this->userWith('view settings'))
            ->putJson(route('settings.update'), $this->settingsPayload(['new_dish_email' => 1]))
            ->assertForbidden();

        $this->assertFalse(Setting::newDishEmail());
    }

    /* ---- the setting --------------------------------------------------- */

    private function settingsPayload(array $extra = []): array
    {
        return ['session_start_time' => '09:00', 'session_end_time' => '17:00'] + $extra;
    }

    public function test_the_page_offers_the_users_and_marks_the_picked_ones(): void
    {
        $picked = $this->userWith();
        $other = $this->userWith();
        Setting::setNewDishWatchers([$picked->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('id="new-dish-watchers"', false)
            ->assertSee('New dish alerts')
            ->assertSee('value="' . $picked->id . '" selected', false)
            ->assertDontSee('value="' . $other->id . '" selected', false);
    }

    public function test_the_list_is_saved_changed_and_emptied_apart_from_the_watchers(): void
    {
        $a = $this->userWith();
        $b = $this->userWith();
        Setting::setNotificationWatchers([$a->id]);
        $this->actingAs($this->superAdmin());

        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_watchers' => ['', (string) $a->id, (string) $b->id]]))->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Setting::newDishWatchers());
        $this->assertSame([$a->id], Setting::notificationWatchers());

        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_watchers' => ['']]))->assertOk();
        $this->assertSame([], Setting::newDishWatchers());
    }

    public function test_a_caller_that_sends_no_list_changes_nothing(): void
    {
        $a = $this->userWith();
        Setting::setNewDishWatchers([$a->id]);

        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $this->settingsPayload())->assertOk();

        $this->assertSame([$a->id], Setting::newDishWatchers());
    }

    public function test_an_unknown_or_deleted_user_cannot_be_picked(): void
    {
        $gone = $this->userWith();
        $gone->delete();
        $this->actingAs($this->superAdmin());

        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_watchers' => [999999]]))
            ->assertStatus(422)->assertJsonValidationErrors('new_dish_watchers.0');
        $this->putJson(route('settings.update'), $this->settingsPayload(['new_dish_watchers' => [$gone->id]]))
            ->assertStatus(422);

        $this->assertSame([], Setting::newDishWatchers());
    }

    public function test_only_editors_can_change_the_list(): void
    {
        $viewer = $this->userWith('view settings');
        $target = $this->userWith();

        $this->actingAs($viewer)
            ->putJson(route('settings.update'), $this->settingsPayload(['new_dish_watchers' => [$target->id]]))
            ->assertForbidden();

        $this->assertSame([], Setting::newDishWatchers());
    }

    public function test_a_damaged_value_reads_as_nobody(): void
    {
        Setting::set(Setting::NEW_DISH_WATCHERS, '{not json');
        $this->assertSame([], Setting::newDishWatchers());
    }
}
