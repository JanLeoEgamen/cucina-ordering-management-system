<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Dashboard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The dashboard's sections as tabs: which tabs a person gets, which panel starts open, and the dot
// that keeps a problem on a closed tab from being missed. (What each widget shows is tested in
// DashboardWidgetTest, DashboardDailyWidgetsTest and DashboardInsightsTest.)
class DashboardTabsTest extends TestCase
{
    use RefreshDatabase;

    private function page(User $user): DOMXPath
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $document = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($before);

        return new DOMXPath($document);
    }

    /** @return DOMElement[] */
    private function all(DOMXPath $page, string $query): array
    {
        return iterator_to_array($page->query($query), false);
    }

    private function tabs(DOMXPath $page): array
    {
        return array_map(fn (DOMElement $tab) => $tab->getAttribute('id'), $this->all($page, '//button[@role="tab"]'));
    }

    private function headings(DOMXPath $page): array
    {
        return array_map(fn (DOMElement $heading) => trim($heading->textContent), $this->all($page, '//h3'));
    }

    public function test_the_sections_are_tabs_with_the_first_one_open(): void
    {
        // With no data at all every section still has a card that is always drawn.
        $page = $this->page($this->superAdmin());

        $this->assertSame(['dashboard-tab-counter', 'dashboard-tab-mine', 'dashboard-tab-checks'], $this->tabs($page));
        $this->assertSame(
            ['true', 'false', 'false'],
            array_map(fn (DOMElement $tab) => $tab->getAttribute('aria-selected'), $this->all($page, '//button[@role="tab"]'))
        );

        $panels = $this->all($page, '//section[@role="tabpanel"]');

        $this->assertSame(
            ['dashboard-panel-counter', 'dashboard-panel-mine', 'dashboard-panel-checks'],
            array_map(fn (DOMElement $panel) => $panel->getAttribute('id'), $panels)
        );
        $this->assertSame([false, true, true], array_map(fn (DOMElement $panel) => $panel->hasAttribute('x-cloak'), $panels));

        foreach (['counter', 'mine', 'checks'] as $index => $key) {
            $this->assertSame("tab === '{$key}'", $panels[$index]->getAttribute('x-show'));
            $this->assertSame('dashboard-tab-' . $key, $panels[$index]->getAttribute('aria-labelledby'));
        }

        // The tab names the section, so the heading is not said twice.
        $this->assertSame([], array_values(array_intersect(['At the counter', 'For you', 'Checks'], $this->headings($page))));
    }

    public function test_what_is_urgent_stays_outside_the_tabs(): void
    {
        $page = $this->page($this->superAdmin());

        $this->assertCount(0, $this->all($page, '//section[@role="tabpanel"]//*[normalize-space(text())="Needs attention"]'));
        $this->assertNotCount(0, $this->all($page, '//*[normalize-space(text())="Needs attention"]'));
    }

    public function test_only_the_sections_a_person_has_become_tabs(): void
    {
        $page = $this->page($this->userWith('view menu', 'view my orders', 'view dashboard-my-orders', 'view dashboard-system-health'));

        $this->assertSame(['dashboard-tab-mine', 'dashboard-tab-checks'], $this->tabs($page));
        // The first tab this person HAS is the open one.
        $this->assertCount(0, $this->all($page, '//section[@id="dashboard-panel-mine"][@x-cloak]'));
        $this->assertCount(1, $this->all($page, '//section[@id="dashboard-panel-checks"][@x-cloak]'));
        $this->assertCount(0, $this->all($page, '//*[@id="dashboard-panel-counter"]'));
    }

    public function test_one_section_is_drawn_without_tabs(): void
    {
        $page = $this->page($this->userWith('view menu', 'view my orders', 'view dashboard-my-orders'));

        $this->assertSame([], $this->tabs($page));
        $this->assertCount(0, $this->all($page, '//*[@role="tablist" or @role="tabpanel"]'));
        $this->assertContains('For you', $this->headings($page));
        $this->assertCount(0, $this->all($page, '//section[@x-cloak or @x-show]'));
    }

    public function test_a_stuck_job_puts_a_dot_on_the_checks_tab_only(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->superAdmin();

        DB::table('jobs')->insert([
            'queue' => 'notifications',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'created_at' => now()->getTimestamp(),
            'available_at' => now()->subMinutes(30)->getTimestamp(),
        ]);

        $dotted = array_map(
            fn (DOMElement $dot) => $dot->parentNode->getAttribute('id'),
            $this->all($this->page($admin), '//*[@data-tab-alert]')
        );

        $this->assertSame(['dashboard-tab-checks'], $dotted);
    }

    public function test_a_section_asks_for_a_look_only_when_something_waits(): void
    {
        $widgets = fn (string $key, array $data) => [$key => ['data' => $data]];
        $needs = fn (array $given) => Dashboard::sectionsNeedingALook($given);

        $this->assertSame([], $needs([]));
        $this->assertSame([], $needs($widgets('system-health', ['mode' => 'database', 'failed' => 0, 'stuck' => 0])));
        $this->assertSame([], $needs($widgets('system-health', ['mode' => 'sync'])));
        $this->assertSame(['checks'], $needs($widgets('system-health', ['mode' => 'database', 'failed' => 1, 'stuck' => 0])));
        $this->assertSame(['checks'], $needs($widgets('system-health', ['mode' => 'database', 'failed' => 0, 'stuck' => 2])));
        $this->assertSame(['checks'], $needs($widgets('system-health', ['mode' => 'unknown'])));
        $this->assertSame([], $needs($widgets('security', ['enabled' => true, 'failed' => 3, 'lockouts' => 0])));
        $this->assertSame(['checks'], $needs($widgets('security', ['enabled' => true, 'failed' => 9, 'lockouts' => 1])));

        $this->assertSame([], $needs($widgets('my-orders', ['orders' => [['status' => 'pending']]])));
        $this->assertSame(['mine'], $needs($widgets('my-orders', ['orders' => [['status' => 'pending'], ['status' => 'ready']]])));
        $this->assertSame(['mine'], $needs($widgets('my-orders', ['orders' => [['status' => 'rejected']]])));
    }
}
