# Known issues, gaps & roadmap

Living list. Add a row when you find a problem you do not fix in the same change; delete the row (and log it in
[changelog.md](changelog.md)) when it is fixed. Security items (S1–S17) live in [security.md](security.md).
Priority: **P0** fix before any real use · **P1** before go-live · **P2** next sprint · **P3** hygiene. Last review: 2026-11-11.

## Functional gaps

| ID | Pri | Gap | Evidence |
|---|---|---|---|
| F2 | **P1** | Self-registered users get no role, so they cannot reach the menu or order (see S7). | `RegisteredUserController` |
| F3 | **P2** | Only one `live` session is honoured; overlapping sessions are not prevented at create/edit. | `OrderSession::live()` returns the first match. |
| F4 | **P2** | No customer notification when a payment is verified/rejected. | — |
| F6 | **P3** | `README.md` and the `composer.json` description are the Laravel skeleton's. | `README.md`, `composer.json` |

## Quality / process gaps

| ID | Pri | Gap |
|---|---|---|
| Q1 | **P1** | No tests for users, roles, payment methods, order sessions, menu/orders/my-orders — including privilege escalation (S1/S2), 403s, and the oversell guard. |
| Q2 | **P1** | The Stop hook that enforces doc sync is not installed (`.claude/settings.json` write was denied; snippet in `docs/harness.md`). |
| Q3 | **P2** | No CI pipeline (tests, `npm run build`, `composer audit`, `npm audit`). |
| Q4 | **P2** | No backup/restore, deployment or production runbook. |
| Q5 | **P3** | `tests/TestCase.php` comment says 16 permissions; real count is 27. |
| Q6 | **P3** | No code-style config; Pint disagrees with the house style, so formatting is manual. |

## Suggested order of work

1. **S1, S2, S3** (P0) with tests.
2. **S5 + S11** (stock caps / expiry, decision history), then **S4** (private proofs) and **S7/F2** (customer role).
3. **S6, S8, Q1, Q2**, then P2 and P3.
