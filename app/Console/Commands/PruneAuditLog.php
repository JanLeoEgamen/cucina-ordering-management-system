<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Console\Command;

// The audit trail's only delete: entries older than the retention window (AUDIT_RETENTION_DAYS,
// 365 by default; 0 keeps everything). The run is itself logged, so a gap in the history
// always has an entry saying who cut it.
class PruneAuditLog extends Command
{
    protected $signature = 'audit:prune {--days= : Delete entries older than this many days (default: AUDIT_RETENTION_DAYS)}';

    protected $description = 'Delete audit trail entries older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('audit.retention_days');

        if ($days <= 0) {
            $this->info('Retention is 0: the audit trail is kept for ever, nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = AuditLog::prune($days);

        $this->info($deleted . ' audit entr' . ($deleted === 1 ? 'y' : 'ies') . ' older than ' . $days . ' days deleted.');

        if ($deleted > 0) {
            Audit::system('audit.prune', 'Deleted ' . $deleted . ' audit entries older than ' . $days . ' days');
        }

        return self::SUCCESS;
    }
}
