/**
 * CornerCAD — raise the Action Scheduler watchdog above the cron interval.
 *
 * Action Scheduler marks any action still in-progress after `failure_period`
 * seconds as failed, on the assumption it has hung. The default is 300s.
 *
 * cornercad.com runs WP-Cron from a cPanel system cron every 5 minutes — also
 * 300s. So a job runner doing legitimate long work gets killed by the very next
 * tick's watchdog, and its claim leaks. That happened on 2026-08-20: a full
 * 160-product Square sync produced seven consecutive wc_square_job_runner
 * failures between 05:00 and 05:45 UTC, one per cron tick, each logged as
 * "in-progress for at least 300 seconds without completing", each leaving an
 * orphaned row in awF_actionscheduler_claims.
 *
 * Orphaned claims are not cosmetic: they count against Action Scheduler's
 * concurrency limit (default 5). Enough of them deadlock the whole queue, which
 * is exactly what took the store's queue down earlier with 477 of them.
 *
 * 900s is chosen to sit clear of the 5-minute cron cadence while still
 * reclaiming a genuinely hung action within fifteen minutes. This is safe here
 * specifically because cron runs through WP-CLI, which has no request timeout —
 * a batch running eight minutes is real work, not a hang. It would NOT be safe
 * on a loopback/web-triggered cron, where LiteSpeed kills the request at ~80s
 * and anything still "running" past that really is dead.
 *
 * Mirrored in git at snippets/wpcode-action-scheduler-failure-period.php
 */

add_filter(
	'action_scheduler_failure_period',
	function ( $seconds ) {
		return 900;
	}
);
