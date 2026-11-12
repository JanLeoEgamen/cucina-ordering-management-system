# Harness — the working loop

This is the mandatory loop for **every** change in this repo (feature, fix, refactor, error investigation).
`CLAUDE.md` imports this file, so it is always in context. The loop ends only when the docs match the code.

```
1 UNDERSTAND → 2 PLAN → 3 IMPLEMENT → 4 VERIFY → 5 SYNC DOCS → 6 REPORT
                                          ↑            │
                                          └── fail ────┘   (harness fails → fix → repeat from 4)
```

## 1. Understand
- Read the relevant docs first ([architecture.md](architecture.md), then the one matching your area).
- Read the neighbouring controller/model/view/test: copy their pattern; do not invent a new one.
- Check [known-issues.md](known-issues.md) — the work may already be a listed gap.

## 2. Plan
- State what will change and which docs it touches (use the table in step 5). For anything touching auth, money, stock or schema, say so explicitly.

## 3. Implement
- Follow the conventions in architecture.md. New endpoint = route + controller action + `middleware()` entry + permission (if new) + test.
- Never edit an applied migration; add a new one.

## 4. Verify
```
php scripts/harness.php --docs   # fast: docs drift
php artisan test                 # or: php scripts/harness.php  (both)
```
Frontend / view / style / controller-built-markup change: `npm run build` must succeed.
If a check fails, fix the cause (not the check), then repeat.
Do not report success while anything here is red; if you could not run something, say so.

## 5. Sync docs (automatic, not optional)
Update **every** file whose trigger fired, in the same change as the code:

| Trigger | Update |
|---|---|
| Route added / changed / removed, request or response shape changed | [api.md](api.md) |
| Permission, role, auth, upload, validation, config or secret touched | [security.md](security.md) |
| Migration, table, column, status value, seeder | [database.md](database.md) |
| New/changed page module, view, component, nav link, build config | [frontend.md](frontend.md) |
| New convention, new domain concept, new directory, stack change | [architecture.md](architecture.md) |
| New tests, new helper, changed test command or baseline count | [testing.md](testing.md) |
| A gap/bug found but not fixed — or a listed one fixed | [known-issues.md](known-issues.md) |
| **Always** (every feature, fix, error resolved, schema or permission change) | [changelog.md](changelog.md) under `[Unreleased]` |
| Setup/run commands changed | `CLAUDE.md` Commands section + `README.md` |

Errors: when you diagnose a bug or failure, record the **root cause and fix** in changelog.md (type `error`/`fix`), and if it reveals a trap
future work could hit, add one line to the "Gotchas" list in `CLAUDE.md`.

## 6. Report
- Say what changed, what was verified (and how), what docs were updated, and anything left open (also added to known-issues.md).

## Enforcement
- `scripts/harness.php` checks: every route ∈ api.md, every permission ∈ security.md, every table ∈ database.md,
  every `resources/js/pages/*.js` ∈ frontend.md, and "code changed ⇒ changelog.md changed".
- `.claude/settings.json` registers a **Stop hook** running `php scripts/harness.php --hook`. If docs drifted, Claude cannot finish the turn
  until it updates them (the hook stands down on its second pass to avoid loops).
  **Status: not installed yet.** Create `.claude/settings.json` with:
  ```json
  { "hooks": { "Stop": [ { "hooks": [ { "type": "command", "command": "php scripts/harness.php --hook", "timeout": 60 } ] } ] } }
  ```
- The script can only prove *presence*, not *accuracy*. Accuracy is on you: re-read the section you edited.

## Extending the harness
Add a check in `scripts/harness.php` whenever a new kind of doc/code pairing appears (e.g. config keys ↔ setup docs), and list it above.
