# Project Context — Prime Capital Portfolio API

Backend-only Laravel REST API for a fictional investment firm. It tracks each
client's cash and instrument holdings purely from an append-only ledger of
transactions (`deposit`, `withdrawal`, `buy`, `sell`). No frontend, no auth.
This is a timed technical assessment — correctness, clean architecture, and a
legible, incremental Git history matter as much as working code.

This file is the single source of truth for business facts and hard
constraints. It does not replace judgment on architecture — several real
decisions below are explicitly left open for the Phase 1 design pass. If a
task prompt and this file ever seem to disagree, this file wins; ask before
assuming otherwise.

## Domain, in one paragraph

A `Client` maps to exactly one cash-and-holdings account, in one currency
(identified by name only — no auth, no PII). Whether that's a physical
`accounts` table or an implicit property of `Client` is an open architecture
question — see ADR-001, do not assume either answer here.
`Instrument`s are free-text tickers (e.g. "AAPL"), seeded ahead of time; a
buy/sell must reference one that already exists — the system never creates
an instrument implicitly from a transaction request. *(Note: the original
brief's "no list to pick from" means there's no closed dropdown of allowed
tickers — any string works as a label. It does **not** mean an Instrument
row is auto-created on first use. The client explicitly confirmed
instruments are pre-seeded and buy/sell must reference an existing one.)*
Everything that happens to a client's money or holdings is one `Transaction`
row. Nothing about that row's own arithmetic changes after it's written.

## Non-negotiable business rules

These came directly from the client. Do not renegotiate, "improve," or add
scope beyond them without asking first.

1. **One client → one account → one currency.** No auth, no multi-account.
   (Whether "account" is its own model — open, see ADR-001.)
2. **Instruments are pre-seeded**, never created implicitly via buy/sell.
   Referencing an unknown instrument is a validation error (422), not a
   silent create.
3. **Transactions are strictly immutable.** Insert-only. No `PUT`/`PATCH`,
   no soft-delete-then-recreate, no admin "correction" endpoint. Ever.
4. **Four transaction types** — `deposit`, `withdrawal`, `buy`, `sell` — as a
   backed PHP enum, not a raw string column.
5. **Precision**: `amount` (deposit/withdrawal) and `price` (buy/sell, per
   unit) are exactly 2 decimal places, `decimal(15,2)` columns — never a PHP
   float in any calculation path. `quantity` (buy/sell) is a positive
   integer, never fractional.
6. For buy/sell, the total (`quantity × price`) is **derived**, never
   accepted as input and never stored as an independently-editable column
   that could drift out of sync. (This closes a real exploit: if `amount`
   were accepted alongside `quantity`/`price`, nothing would stop
   `quantity=5, price=100, amount=1` from being submitted.)
7. **Cash balance and instrument holdings — and only those two things — are
   derived from the immutable transaction ledger on every read.** There is
   no cached `cash_balance` or `holding_quantity` column anywhere. This is a
   deliberate correctness/simplicity choice (the ledger is the only source
   of truth for *balance state*), confirmed explicitly by the client
   ("calculate for now; only add caching if a real performance problem shows
   up") — not an oversight. This rule is about *state*, not about
   *arithmetic in general*: `quantity × price`, validation checks, and
   aggregate `SUM()` queries obviously still happen constantly. Don't
   propose caching a balance column unless I ask you to revisit it.
8. **Cash must never go negative.** Reject withdrawal/buy if it would, leave
   all state untouched, return a specific error (not a generic 500).
9. **A client can never sell more units of an instrument than they currently
   hold.** Selling exactly the full held quantity (down to zero) is
   explicitly allowed and must succeed.
10. An instrument at **exactly zero held quantity must not appear at all**
    in the portfolio response — not `quantity: 0`, absent entirely.
11. Every write is **atomic**: on any rule violation, zero rows are written
    and zero balances change. In practice, the write path here is a single
    `INSERT` — which is already atomic at the storage-engine level on its
    own. `DB::transaction()` still matters, but for a more precise reason
    than "atomicity" as a buzzword: it's the container that would make a
    defensive `lockForUpdate()` read (see ADR-004) actually hold its lock
    until the insert commits, and it's the correct habitual boundary around
    any "read business state → decide → write" operation regardless of how
    many statements it happens to be today. Be ready to explain this
    precisely, not just say "we use a DB transaction for atomicity."
12. `transaction_fee` exists as a nullable/defaulted column on `transactions`
    for future use. It does **not** currently affect cash/holdings math.
    Verify this explicitly with a test before relying on it — don't let it
    silently get folded into a total.
13. **No authentication, no users, no Sanctum, no policies, no login.** The
    brief has none of this. Do not add it "because a REST API normally
    needs auth" — that's scope creep an evaluator will notice and penalize,
    not reward.
14. No concurrent-request handling is required for this assessment (client
    confirmed) — but rule 11 (atomicity) still holds regardless. Whether to
    defensively add `lockForUpdate()` anyway is a real trade-off worth an
    ADR, not an assumption either way. If adopted, lock the parent `Client`
    row (balance is a computed aggregate, not a single lockable row) —
    don't try to lock the `transactions` table itself.
15. Since `quantity` is always a whole integer, `quantity × price` never
    produces more than 2 decimal places — no rounding strategy is needed
    anywhere. If you find yourself writing a `round()` call near money,
    stop and reconsider.
16. Don't build a shared abstraction across deposit/withdraw/buy/sell merely
    because four methods happen to live on one service class. Check whether
    the behavior genuinely warrants a common code path before extracting
    one — a `TransactionService` that's four clear, separately-readable
    methods is a better assessment deliverable than a clever but harder-to-
    explain shared pipeline.

## Rules of engagement for you (the agent)

- **Never run `git commit` yourself.** Show me `git status` / `git diff`,
  explain what changed and why, and stop — I commit by hand after review.
- **Never create fake, placeholder, or meaningless commits merely to make
  the history look incremental.** Every commit I make corresponds to real,
  completed work I've reviewed and approved — not padding.
- **Never modify Git history or timestamps.** No backdating, no rebasing to
  reorder for appearances, no `--date` tricks. The history is exactly what
  it is.
- **Work exactly one phase at a time**, per whatever the current prompt
  says. Don't pull work forward from a later phase even if it's convenient.
- After finishing a phase: summarize what changed, show the relevant files,
  explain the non-obvious decisions, run the relevant checks, report
  failures honestly (don't paraphrase test output — show it), and **stop**.
- **Use Laravel Boost's MCP tools** to check real project state instead of
  assuming — `application-info`, `database-schema`, `list-routes`,
  `search-docs`, `tinker` (or `php artisan tinker` via bash if the Tinker
  MCP tool isn't registered), `last-error`, `read-log-entries`. If Boost and
  the code ever seem to disagree, investigate the real state — don't assume
  either one is automatically correct.
- **No floating-point arithmetic anywhere near money or quantity math.**
  Prefer `brick/money` (the client explicitly said this is preferred for
  precision) or BCMath with string-safe decimals as the fallback. Flag it
  immediately if you ever see a `(float)` cast or raw `+ - *` on a
  decimal-looking value.
- Prefer the simplest design that satisfies every rule above. No repository
  pattern, event sourcing, queues, caching, or microservice-flavored
  abstractions "for scalability" — this is a scoped assessment, not a
  production system that needs to survive next year.
- For every non-trivial architectural choice, name the alternative you
  considered and why you didn't pick it, in the same message — I need to be
  able to explain every decision out loud without re-reading the code.
- **If you think my proposed approach is wrong, say so directly and explain
  why.** Don't agree with me just because I suggested it. Do not accept a
  suspicious, incomplete, over-engineered, or CLAUDE.md-inconsistent result
  from your own earlier output either — flag it to me instead of quietly
  proceeding.

## Recommended (not fixed) API surface

Confirm/revise this in the Phase 1 design pass, but this is the sane default:

```
POST /api/clients/{client}/transactions      # type in body selects behavior
GET  /api/clients/{client}/cash-balance
GET  /api/clients/{client}/portfolio
GET  /api/clients/{client}/transactions       # history, paginated
```

**Confirmed in ADR-001..004 (Phase 1):** `POST /transactions` returns `201
Created` with the persisted Transaction row on success. Rule-8/9 rejections
return `422` with a machine-readable error code distinguishing the reason
(`insufficient_funds`, `insufficient_holdings`, `unknown_instrument`) — not
just a human-readable message — so a test/client can branch on it
programmatically. Carry this through Phase 3 (validation) and Phase 6
(endpoints) without re-deciding it.

**Confirmed in Phase 3 validation work:** `amount` and `price` must be sent
as JSON **strings** (`"10.50"`, not `10.50`), never bare JSON numbers. This
was discovered empirically, not assumed: a bare JSON number `10.50` is
indistinguishable from `10.5` once PHP parses it (the trailing zero has no
representation in a float), so `decimal:2` cannot reliably enforce "exactly
2 decimal places" against a bare number — only against a string. `quantity`
has no equivalent issue (JSON integers don't lose precision) and stays a
plain number. This is the same reasoning ADR-002 already established for
internal arithmetic (`brick/money` never accepts a float) — it turns out to
apply at the HTTP boundary too, not just inside the codebase. Carry this
exact request shape through Phase 4/5 (service layer) and Phase 6/10
(endpoint examples, README) without re-deciding it.

## Directory conventions

- `app/Enums/TransactionType.php`
- `app/Models/{Client,Instrument,Transaction}.php`
- `app/Http/Requests/CreateTransactionRequest.php` (or per-type requests —
  decide and justify in Phase 1/3)
- `app/Services/PortfolioService.php` (read-side: balance, holdings)
- `app/Services/TransactionService.php` (write-side: deposit/withdraw/buy/sell)
- `app/Exceptions/{InsufficientFundsException,InsufficientHoldingsException}.php`
- `docs/adr/000X-title.md` (architecture decision records)
- `database/seeders/{ClientSeeder,InstrumentSeeder,TransactionSeeder}.php`

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
