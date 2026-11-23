<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// `php artisan perf:check` runs on the production server, so it must be harmless: it works, and it
// leaves no scratch table behind.
class PerfCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_and_drops_its_scratch_table(): void
    {
        $this->artisan('perf:check', ['--n' => 3])->assertExitCode(0);

        $left = collect(Schema::getTables())->pluck('name')->filter(fn ($name) => str_starts_with($name, 'zz_perf_check_'));

        $this->assertCount(0, $left);
    }
}
