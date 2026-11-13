# Documentation index

| Doc | What it holds | Updated when |
|---|---|---|
| [harness.md](harness.md) | The mandatory change loop + doc-sync rules | the process changes |
| [architecture.md](architecture.md) | Stack, conventions, domain model, directory map | conventions/domain change |
| [api.md](api.md) | Every HTTP/AJAX endpoint, permissions, payloads | any route / payload change |
| [security.md](security.md) | Auth, permissions catalogue, input handling, known weaknesses | auth/permission/upload/config change |
| [database.md](database.md) | Tables, columns, relationships, seeding | any migration / seeder |
| [frontend.md](frontend.md) | JS page modules, components, UI conventions, build | any UI change |
| [testing.md](testing.md) | Commands, helpers, coverage, rules | tests change |
| [known-issues.md](known-issues.md) | Open gaps and roadmap | a gap is found or fixed |
| [changelog.md](changelog.md) | Every feature / fix / error | **every change** |

Check sync: `php scripts/harness.php --docs`.
