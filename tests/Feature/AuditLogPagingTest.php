<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The audit trail viewer's paging: a page at a time, newest first, a rows-per-page choice that
// keeps the filters, and a footer that is there even when everything fits on one page.
class AuditLogPagingTest extends TestCase
{
    use RefreshDatabase;

    private function entries(int $count): void
    {
        $rows = [];

        // "Entry-001" ... zero padded, so one entry's text is never inside another's.
        for ($number = 1; $number <= $count; $number++) {
            $rows[] = [
                'event' => 'action',
                'action' => 'test.entry',
                'description' => sprintf('Entry-%03d', $number),
                'outcome' => 'success',
                'created_at' => now(),
            ];
        }

        DB::table('audit_logs')->insert($rows);
    }

    private function viewer()
    {
        return $this->actingAs($this->userWith('view audit-logs'));
    }

    public function test_the_list_is_a_page_of_25_newest_first_with_a_numbered_pager(): void
    {
        $this->entries(60);

        $this->viewer()->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Showing 1–25 of 60')
            ->assertSee('Entry-060')->assertSee('Entry-036')
            ->assertDontSee('Entry-035')
            ->assertSee('aria-label="Go to page 3"', false);

        $this->viewer()->get(route('audit-logs.index', ['page' => 2]))
            ->assertSee('Showing 26–50 of 60')
            ->assertSee('Entry-035')->assertSee('Entry-011')
            ->assertDontSee('Entry-036')->assertDontSee('Entry-010');

        $this->viewer()->get(route('audit-logs.index', ['page' => 3]))
            ->assertSee('Showing 51–60 of 60')
            ->assertSee('Entry-010')->assertSee('Entry-001')
            ->assertDontSee('Entry-011');
    }

    public function test_the_reader_can_pick_how_many_rows_a_page_holds(): void
    {
        $this->entries(60);

        $this->viewer()->get(route('audit-logs.index', ['per_page' => 10]))
            ->assertSee('Showing 1–10 of 60')
            ->assertSee('Entry-051')->assertDontSee('Entry-050')
            ->assertSee('aria-label="Go to page 6"', false);

        $this->viewer()->get(route('audit-logs.index', ['per_page' => 100]))
            ->assertSee('Showing 1–60 of 60')
            ->assertSee('Entry-060')->assertSee('Entry-001')
            // One page: Previous / Next are there but nothing to go to.
            ->assertDontSee('aria-label="Go to page 2"', false);
    }

    public function test_only_the_offered_sizes_are_accepted(): void
    {
        $this->entries(3);

        foreach ([7, 0, -5, 1000, 'all', '25; drop table audit_logs'] as $bad) {
            $this->viewer()->get(route('audit-logs.index', ['per_page' => $bad]))
                ->assertSessionHasErrors('per_page');
        }

        $this->assertSame(3, DB::table('audit_logs')->count());
        $this->assertSame([10, 25, 50, 100], AuditLog::PAGE_SIZES);
    }

    public function test_the_size_links_keep_the_filters_and_go_back_to_page_one(): void
    {
        $this->entries(60);

        $html = $this->viewer()->get(route('audit-logs.index', ['q' => 'Entry', 'event' => 'action', 'page' => 2]))
            ->assertOk()
            ->getContent();

        // The 50-rows link: same search and type, the new size, and no `page` (page 2 of 25 is not page 2 of 50).
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*aria-label="50 rows per page"/', $html, $found));
        parse_str((string) parse_url(html_entity_decode($found[1]), PHP_URL_QUERY), $query);

        $this->assertSame('Entry', $query['q']);
        $this->assertSame('action', $query['event']);
        $this->assertSame('50', $query['per_page']);
        $this->assertArrayNotHasKey('page', $query);

        // The page links keep the filters and the size too.
        $html = $this->viewer()->get(route('audit-logs.index', ['q' => 'Entry', 'per_page' => 10]))->getContent();
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*aria-label="Go to page 2"/', $html, $found));
        parse_str((string) parse_url(html_entity_decode($found[1]), PHP_URL_QUERY), $query);

        $this->assertSame('Entry', $query['q']);
        $this->assertSame('10', $query['per_page']);
        $this->assertSame('2', $query['page']);
    }

    public function test_the_current_size_is_marked(): void
    {
        $this->entries(5);

        // 25 until another is picked, then that one; the others are links.
        $this->viewer()->get(route('audit-logs.index'))
            ->assertSeeInOrder(['aria-current="true"', '>25<'], false)
            ->assertSee('aria-label="10 rows per page"', false)
            ->assertDontSee('aria-label="25 rows per page"', false);

        $this->viewer()->get(route('audit-logs.index', ['per_page' => 50]))
            ->assertSeeInOrder(['aria-current="true"', '>50<'], false)
            ->assertDontSee('aria-label="50 rows per page"', false)
            ->assertSee('aria-label="25 rows per page"', false);
    }

    public function test_the_footer_is_there_for_a_single_page_and_not_for_an_empty_list(): void
    {
        $this->entries(3);

        $this->viewer()->get(route('audit-logs.index'))
            ->assertSee('Showing 1–3 of 3')
            ->assertSee('Rows per page');

        DB::table('audit_logs')->delete();

        $this->viewer()->get(route('audit-logs.index'))
            ->assertSee('No entries match.')
            ->assertDontSee('Showing')
            ->assertDontSee('Rows per page');
    }

    public function test_a_page_past_the_end_lands_on_the_last_one(): void
    {
        $this->entries(60);

        $location = $this->viewer()->get(route('audit-logs.index', ['page' => 99, 'per_page' => 25]))
            ->assertRedirect()
            ->headers->get('Location');

        // The last page, same size (the order of the parameters does not matter).
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertEquals(['page' => '3', 'per_page' => '25'], array_intersect_key($query, ['page' => 1, 'per_page' => 1]));

        // Narrowing the search shrinks the list: the page the reader was on is gone, the search stays.
        $location = $this->viewer()->get(route('audit-logs.index', ['page' => 3, 'q' => 'Entry-05']))
            ->assertRedirect()
            ->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1', $query['page']);
        $this->assertSame('Entry-05', $query['q']);
    }

    public function test_the_csv_export_ignores_the_page_size_and_still_needs_the_permission(): void
    {
        $this->entries(30);

        $response = $this->viewer()->get(route('audit-logs.export', ['per_page' => 10]));
        $response->assertOk();

        // All 30 entries, not 10: paging is for reading, the export is the whole filtered list.
        // (The export writes an entry of its own, "taking the log out is itself an entry", so the
        // file has that row too: count only the ones made here.)
        $this->assertSame(30, substr_count($response->streamedContent(), 'Entry-0'));

        $this->actingAs($this->userWith('view users'))->get(route('audit-logs.export'))->assertForbidden();
    }
}
