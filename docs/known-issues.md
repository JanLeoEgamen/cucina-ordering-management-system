# Known issues, gaps & roadmap

Living list. Add a row when you find a problem you do not fix in the same change; delete the row (and log it in
[changelog.md](changelog.md)) when it is fixed. Security items (S1–S17) live in [security.md](security.md).
Priority: **P0** fix before any real use · **P1** before go-live · **P2** next sprint · **P3** hygiene. Last review: 2026-11-13.

## Functional gaps

| ID | Pri | Gap | Evidence |
|---|---|---|---|
| F2 | **P2** | Self-registered users now get the Customer role (S7), but accounts registered **before** that have no role and still get 403 on the menu; the role is only given when it exists (seeded). | `User::giveCustomerRole` |
| F3 | **P2** | Only one `live` session is honoured; overlapping sessions are not prevented at create/edit. | `OrderSession::live()` returns the first match. |
| F6 | **P3** | `README.md` and the `composer.json` description are the Laravel skeleton's. | `README.md`, `composer.json` |
| F7 | **P3** | **Parked by business rule (2026-11-13):** a placed order is the customer's and is prepared; every customer picks up and there are no returns or no-show records, so nothing should expire or auto-cancel today. Revisit if no-shows appear. The one case worth automating later: an order whose payment was **rejected** still holds its plates until staff cancel it. **Pending orders hold stock forever.** An unpaid bank / e-wallet order (or a cash order nobody collects) keeps its plates reserved until staff cancel it, and nothing expires when the session ends. Fix: a scheduled command (`orders:expire`) that cancels pending orders with an unverified payment after N minutes, through the same cancel code path (stock released, payment rejected "expired"); same for pending orders of an ended session. (= S5) | `OrderController::cancel`, no scheduler entry |
| F10 | **P2** | Customers cannot cancel their own pending order (only staff with `cancel orders`). | `OrderController::cancel` |
| F11 | **P2** | An order total can be 0.00 (the seeded menu has 0.00 placeholder prices); a bank order with proof for 0.00 makes no sense. Fix: refuse `total <= 0` or hide dishes without a price from the menu. | `MenuSeeder`, `placeOrder` |
| F12 | **P3** | Order numbers are `ORD-YYYYMMDD-NNNN` read as "last + 1": the retry on the unique index makes collisions harmless, but past 9 999 orders a day the 4-digit suffix parse breaks. Fix: a counter table or a longer suffix. | `Order::nextOrderNumber` |
| F14 | **P2** | **No history of a resent payment.** When a customer sends a new proof, the rejected payment is overwritten: staff see an ordinary pending payment, with no sign it was rejected before, by whom, why, or how many times; the old picture is deleted. A customer can resend forever. Fix: a `payment_attempts` table (proof, reference, reviewer, reason, decided_at) written on every decision / resend (= S11/S12), a "resent" flag or count in the payments list, and a cap on resends (e.g. 3) before only staff can reopen it. | `Payment::resubmit`, `OrderController::reopenPayment` |
| F15 | **P2** | **Notifications are in-app only.** No email / SMS / push: a customer who is not on the site (or has the tab closed) sees the bell only on their next visit. The text is also stored in the language of the moment it was written (the app is English only today). Fix: add a mail channel in `OrderNotifier::deliver` (queue it: mail is slow), keyed off the same events; store a `type` + parameters instead of text if the app ever gets a second language. | `OrderNotifier`, `UserNotification` |
| F16 | **P3** | Notifications that could still be added: *a session just went live* (needs a scheduler tick, since going live is a clock event, not a click), *a payment decision was undone*, *stock released by a cancel*, and a per-user choice of which events to receive (today a watcher gets all of them). | `OrderNotifier`, `routes/console.php` |
| F18 | **P2** | **Notifications depend on a queue worker.** Without a process running `queue:work --queue=notifications,default` nobody is notified (orders are unaffected; the jobs wait in `jobs`). It is not supervised or monitored yet (no Supervisor / systemd unit, no alert on queue depth or `failed_jobs`; see Q4). The bell polls every ~8 s per open tab; for hundreds of simultaneous users revisit (see F13). | `config/notifications.php`, `OrderNotifier`, `composer dev` |
| F19 | **P3** | **Notification emails are best effort.** The email job is not retried (a retry would mail everyone twice), so a mail server that is down at that moment loses those emails (logged as `Notification email could not be sent`); the in-app notification is unaffected. There is one global switch: no per-user opt-out and no per-event choice, and the mail is plain text + button, not translated per user. |
| F17 | **P3** | The `notifications:prune` job (and with it the table size) depends on the scheduler: locally `php artisan schedule:work`, on a server the one-line cron `* * * * * php artisan schedule:run`. Without it nothing breaks, the table just grows. | `routes/console.php` |
| F13 | **P3** | The live stock is a poll (one cheap query per open menu every ~5 s). Fine for a canteen; for hundreds of simultaneous customers move to Laravel Reverb / SSE or cache the feed for 1–2 s on a Redis cache. | `OrderController::stock`, `menu.js` |

## Quality / process gaps

| ID | Pri | Gap |
|---|---|---|
| Q1 | **P1** | No tests for users, roles, payment methods, order sessions, menu/orders/my-orders — including privilege escalation (S1/S2), 403s, and the oversell guard. |
| Q2 | **P1** | The Stop hook that enforces doc sync is not installed (`.claude/settings.json` write was denied; snippet in `docs/harness.md`). |
| Q3 | **P2** | No CI pipeline (tests, `npm run build`, `composer audit`, `npm audit`). |
| Q4 | **P2** | No backup/restore, deployment or production runbook. |
| Q5 | **P3** | `tests/TestCase.php` comment says 16 permissions; real count is 27. |
| Q6 | **P3** | No code-style config; Pint disagrees with the house style, so formatting is manual. |
| Q7 | **P3** | `OrdersPageTest > the feed lists orders of every session with their session and customer` failed once in a full run (2026-11-13) and passed in the next full run and alone; cause not found (it touches nothing the queue / realtime change did). Watch for it. |

## Suggested order of work

1. **S1, S2, S3** (P0) with tests.
2. **S5 + S11** (stock caps / expiry, decision history), then **S4** (private proofs) and **S7/F2** (customer role).
3. **S6, S8, Q1, Q2**, then P2 and P3.
