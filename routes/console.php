<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Fleet-metrics heartbeat safety net. The heartbeat is a self-rescheduling
// Horizon job, but a queue/Redis hiccup or a box restart can drop the pending
// tick. Re-seeding every five minutes is idempotent (the job's unique lock
// blocks duplicates) and revives a dead loop well inside the staleness window,
// so this box returns to ONLINE on its own after any restart — no manual kick.
Schedule::command('kraite:fleet-report --seed')->everyFiveMinutes()->withoutOverlapping();
