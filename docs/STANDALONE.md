# Standalone mode — overlays without UltiOrganizer

A concept, not a plan of record. Nothing here is built.

## 1. The question

Everything in this project currently assumes an UltiOrganizer installation with Live! by BULA underneath it: the front controller routes the pages, the API serves the game, and the admin session gates what reaches air. That assumption is load-bearing in the deployment sense and almost nowhere else.

The situation it fails is common and unglamorous. A tournament is scored on paper. A club streams a friendly. A showcase game sits outside the event that owns the software. A federation runs Live! but this particular pitch is not in it. In all of them the overlays would work — the scoreboard, the stage, the commentary desk, the progression card — and the only reason they cannot is that nothing is there to answer "what is game 702".

**Standalone mode is a second answer to that one question.** Not a fork, not a mock, and not a reduced product: the same renderers, reading the same payload shape, from a store somebody fills in by hand.

## 2. Why this is more tractable than it looks

The coupling is thin, and it is worth stating in numbers before designing anything.

**On the PHP side there are two classes.** `Api\ConfigManager` and `Api\SeasonAccess`, used in exactly three files — `show.php`, `possession.php` and `colors.php` — and in every case for the same three lines: load the config, ask whether this request carries the Live! admin session. Nothing reads the database. Nothing else imports anything from the host.

**On the data side there are four read endpoints.** `entity=games` (the list, and one game's detail), `entity=teams` (a roster with tournament totals), `entity=playerevents` (one player's game-by-game history) and `entity=reference` (field names via reservations). `entity=config` is read once by the stage and the self-test. That is the whole surface.

**Everything the desk itself authors already works without a database.** What is on air (`conf/show.json`), kit colours (`conf/team-colors.json`), the possession log with the gender ratio and the declared line size (`conf/possession-<game>.json`), shared line selections (`conf/lines/`) and the commentary desk's prepared notes (`conf/notes/`) are flat files written by this project, read by this project, and already the authority for everything they hold. Roughly half of what a commentator sees is already standalone.

**And the clock is three integers.** `timer_start`, `timer_paused_duration` and `timer_pause_start` on `game_result`, from which `scoreboard.php` derives everything. It already synthesises them: the post-production frame at `?at=<seconds>&goals=<n>` builds a synthetic `timer_start` so that "the existing clock code runs unchanged" (`scoreboard.php` §"one renderer"). A local clock is a start button writing one number and a pause button writing two.

So the part that intuitively feels hardest — timekeeping — is the part with a working precedent. The genuinely new input is **the score**, because a goal is not one number: it is an entry in the `goals` array, and that array is what drives the point number, the ABBA slot, the progression card, hold/break derivation, and the per-point strip on a player's quick card.

## 3. The shape: one renderer, two providers

The project's existing rule is **one renderer, never two** — the post-production frame works by handing the real `render()` a truncated payload rather than reimplementing anything. Standalone mode is the same rule applied one layer down.

> **One renderer, one payload shape, two providers.** Live! is one. A local JSON store is the other. Nothing above the seam knows which it is talking to.

That constraint is the whole design, and it is worth being severe about it, because the obvious cheaper thing — letting standalone emit "a simpler shape, we control both ends" — creates a second contract that every consumer then has to branch on. `docs/PLAN.md` records the field names that have already caught people out (`teams.hometeam`, not `awayteam`; `game_info.hometeamshortname`; `poolinfo.timecap` in minutes). A local store that gets to be tidy is a store whose payload drifts from the one the overlays are actually tested against.

**So the local provider emits Live!'s shape, warts included.** Where Live! is inconsistent, standalone is inconsistent in the same way. This is a case where copying somebody else's mistakes is correct.

### Where the seam goes

Three cuts, in order of how much they buy:

1. **A payload provider.** `shared/overlay-client.js` is the single fetch point for the scoreboard and the stage. `commentator.php` fetches three entities directly (`games&id`, `teams&id`, `playerevents&id`). Those four call sites become one module — `shared/provider.js` — that answers the same four questions from either source. The switch is one setting.
2. **An auth seam.** The three `SeasonAccess::isLiveAdminAuthenticated($config)` calls become one local function that asks the same question. Standalone answers it from a password hash in `conf/`, with the same session semantics; hosted answers it exactly as now. The point is that `show.php` continues to say "is this an admin" and never learns which regime it is under.
3. **A front controller.** Ten files guard on `UO_ROUTED_VIEW` and 404 otherwise, which is what stops a direct request to a `.php` reaching anything. Standalone needs its own entry point that sets that constant and dispatches `?view=`, so the guard keeps working unchanged rather than being weakened for the standalone case.

None of these is a rewrite. All three are the same code answering the same question from a different place.

## 4. What has to be authored, and by whom

| What | Who enters it | Where it lives | Notes |
|---|---|---|---|
| Event, pool, division | Operator, once | `conf/standalone/event.json` | Division name decides mixed, which decides the whole ratio apparatus |
| Teams: name, short name, colour | Operator, once per team | same | Short names already exist in the payload (`hometeamshortname`) and the kit palette already exists |
| A game: two teams, name, field, target score, caps | Operator, per game | `conf/standalone/game-<id>.json` | Mirrors `game_info` + `poolinfo` |
| Rosters: number, name | **The teams, via the existing CSV** | `conf/notes/` or alongside | See below |
| Goals: who scored, who assisted, when | Operator or desk, live | `conf/standalone/goals-<id>.json` | The one genuinely new input surface |
| The clock | Operator, live | the game file | Three integers |

**The roster answer already exists and is better than a form.** The bio round trip (`COMMENTATOR.md` §5a) already sends each team a CSV they fill in themselves, already carries `Number` and `Name` columns, already refuses another team's file by id and by name, and already validates matchings to FMP/MMP. In hosted mode those columns are decoration — the roster comes from Live! and the CSV only adds what Live! lacks. **In standalone they become the source of truth**, which is a promotion rather than a new mechanism, and it puts roster authorship with the people who know it. The `NOTICE` row already tells them what they are writing into.

## 5. What is genuinely lost

Being honest about this is most of the value of the document.

**Gone entirely, because there is no history to have:**

- Tournament totals per player — goals, assists, points, games played, points per game. Every "Tournament" row on a player sheet and quick card is a number Live! accumulates across the event.
- Game-by-game history and the "most frequent connection" derived from it (`entity=playerevents`).
- Blocks. They are already conditional on a Live! setting and absent more often than not, and `blocksTracked()` already drops the column when the field is missing — so standalone gets the existing "not tracked" path for free, correctly.
- Seeds, standings, and anything comparing this game to others in a pool.
- Spirit scores.

**Recoverable within standalone, at a cost:** running totals across a tournament are just an accumulation over the goals files, if standalone owns every game of that tournament. That is a real feature and a later one; it should not be in a first version, because it turns a per-game store into an event database and that is the thing this mode exists to avoid needing.

**The honest framing for the UI:** all of the above already has a documented rule — **absent is not zero** (`AGENTS.md`). A missing field means "this installation does not track this"; a present `0` means "none yet". Standalone should therefore *omit* these fields rather than send zeros, and every consumer already does the right thing with an omission. A player sheet in standalone shows this game's numbers and no tournament row, which is truthful. A player sheet showing `0 G · 0 A` for a player who has scored nine over three days is a lie the overlay would tell on air.

This is the single most important design consequence in this document, and it costs nothing to get right because the behaviour is already built and already tested.

## 6. What standalone must not become

**It must not become a reason to weaken the guards.** The `UO_ROUTED_VIEW` check, the admin gate on anything that reaches air, `conf/` staying unreadable over HTTP, the `.htaccess` that serves two files by name and 404s the rest — those exist because this project writes operator state to flat files and one of those files holds notes about named people. A standalone front controller has to establish the same guarantees, not skip them because there is no host to inherit them from.

**It must not become a second test surface that nobody runs.** The suite deliberately drives a real instance, because the payload shape is the thing that has been wrong before. A standalone provider is a *fixture generator* by another name, and the temptation will be to test the overlays against it — which would be exactly the mock the project refused. The rule that keeps this honest: **standalone's provider is tested against Live!'s recorded payloads**, not the other way round. Capture real payloads, assert the local store reproduces their shape field for field, and keep the overlay tests pointed at a real instance.

That has a pleasant side effect. Today the browser suite cannot run in CI at all, because Live! is third-party software under a signed Terms of Use that a public workflow cannot check out (`.github/workflows/ci.yml`). A standalone provider is, incidentally, **the thing that would make the browser suite runnable in CI** — no database, no Live!, no licence. That is not a reason to build it, but it is a real second payoff and it argues for building the provider seam first and cleanly.

**It must not quietly answer the licensing question.** Live! by BULA is distributed under a signed Terms of Use, and these overlays are built for it. A mode that runs without it is not obviously a circumvention — it serves games Live! was never going to hold — but it is a question for the people who signed, not one to settle by shipping. Ask before publishing.

## 7. A staging that produces something useful early

1. **The provider seam, hosted only.** Introduce `shared/provider.js`, route the four call sites through it, change nothing else. Behaviour identical, and it is independently verifiable: the whole suite must stay green with no other change. This is the commit that makes everything after it cheap.
2. **A recorded-payload provider.** The same interface reading captured JSON from disk. No editing UI. Immediately useful for development and for post-production, and it is what lets the browser suite run without Live!.
3. **Auth and routing seams.** A standalone front controller and a local admin password. At this point the overlays run on a bare PHP host against recorded payloads.
4. **The editor.** Teams, a game, the clock, and goal entry — the operator surface. This is the biggest single piece and the one worth prototyping against a real broadcast before committing to a shape.
5. **Rosters via the existing CSV**, promoted to source of truth.
6. *(Later, maybe never)* accumulation across games within a standalone event.

Milestone 3 is the first point at which somebody who does not run UltiOrganizer can use this project. Milestone 1 is worth doing regardless of whether the rest ever happens.

## 8. What a server would have to be

The point of this section is that the answer is small. Everything below was read off the code rather than assumed, and the headline is that **standalone needs no database and no Composer** — the two things that make the hosted deployment heavy.

### The floor

| | Requirement | Why |
|---|---|---|
| **PHP** | 8.3 or 8.4 | What CI lints and what the host runs. The code uses no syntax newer than typed properties and arrow functions, so a lower floor is likely and simply untested — do not claim one without testing it. |
| **Extensions** | `json`, `pcre`, `mbstring`, `filter` | The complete list of extension-dependent calls in the project is `json_encode`/`json_decode`, `preg_match`/`preg_replace`, `mb_substr`, `filter_input`, `flock` and `random_int`. All but `mbstring` are bundled and enabled by default. |
| **Composer** | **none** | `vendor/autoload.php` is required by exactly three files, solely to reach `Api\ConfigManager` and `Api\SeasonAccess`. The project's own classes are `require_once`d directly. Replace the auth seam (§3) and the autoloader has nothing left to load. |
| **Database** | **none** | Nothing in this project opens one, in either mode. Hosted mode reaches the database only through Live!'s API over HTTP. |
| **Web server** | Apache with `mod_rewrite` and `AllowOverride All`, or nginx with the rules translated | The `.htaccess` does two jobs: routing the short URLs, and refusing HTTP access to `conf/` except for the one file the stage polls as a static asset. On nginx both become `location` blocks, and **the `conf/` denial is the one that must not be forgotten** — it is what keeps the desk's notes about named players out of a browser. |
| **Filesystem** | `conf/` writable by the web server, on a filesystem where `flock` works | `show.php`, `colors.php`, `possession.php` and `notes.php` do their read-modify-write under `flock` on a shared lock file. NFS and some container volume drivers do not implement it faithfully, and the failure is silent interleaving rather than an error. Note that **`lines.php` has no such lock** — a known gap recorded in `AGENTS.md`, not a decision, and one standalone would inherit unchanged. |
| **TLS** | Needed in practice | A browser source loading an overlay over HTTP from a page served over HTTPS is blocked as mixed content, and some switcher browsers refuse plain HTTP outright. |

### What it has to withstand

Not much, but the shape is unusual: **many small polls, no bursts, and a hard latency requirement on one file.**

| Poller | Interval | What it hits |
|---|---|---|
| Stage — what is on air | ~1s | `conf/show.json` as a **static file**, deliberately not through PHP |
| Possession, and the shared line | 2s | Two routed PHP endpoints |
| Game data | 10s | The payload provider |
| Prepared notes | 15s | A routed PHP endpoint |

One field in use is roughly **one scoreboard browser, one stage browser and one or two commentary desks**, so about 4–6 pollers per pitch. Ten pitches is at most a few hundred requests a minute, nearly all of them conditional GETs for small JSON. Any PHP host from the last decade handles this; a Raspberry Pi on the venue LAN handles this. **The requirement is not throughput, it is the ~1s file.** `conf/show.json` is served by the web server rather than by PHP precisely so that an operator's click feels instant, and anything in front of it — a proxy, a CDN, an aggressive `Cache-Control` — that adds a second of staleness is a second of a graphic staying on air after it was taken off.

### What standalone adds to the list

- **A writable store for the authored data** — `conf/standalone/`, same permissions and the same HTTP denial as the rest of `conf/`.
- **An admin credential of its own.** The host already keeps a bcrypt hash in a PHP config file (`live/conf/LocalConfig.php`), which is exactly the shape to copy: a hash in `conf/`, never a plaintext password, never a value in the repository.
- **A front controller**, which is a single file that defines `UO_ROUTED_VIEW` and dispatches `?view=`. The ten guards stay as they are.

### Where it could run that hosted mode cannot

Worth stating, because it is most of the practical appeal: with no database and no Composer, the deployable artefact is **a directory of PHP files and a writable `conf/`**. That runs on shared hosting, on a laptop with `php -S` at a venue with no uplink, or in a container built `FROM php:8.3-apache` with one `a2enmod rewrite`. A tournament running standalone on a laptop behind the commentary desk is a realistic deployment, and it is the one that makes the mode worth building.

The offline case deserves care rather than a footnote: if the venue has no uplink, team logos, fonts and any CDN asset have to be local already. Worth auditing before promising it.

## 9. Open questions

- **Who keeps the score?** The commentary desk already has a person watching every point, and possession tracking has established they will press a key per event. But score is not a private reference panel — it reaches air, and the admin gate exists precisely because a bad write there changes what a viewer sees. Operator-only is the safe default; desk-with-code is the useful one. This is the same argument §6 of `COMMENTATOR.md` settled for possession, and it should probably be settled the same way.
- **One game or an event?** A per-game file is simpler and matches how `conf/possession-<game>.json` is already keyed. An event file is what standings and totals would need. Start per-game; the possession store's history is a warning about shared documents.
- **Where do game ids come from?** They have to be stable, because every store in this project is keyed by them. Operator-assigned integers are fine and boring.
- **Does the Timekeeper app help?** UltiOrganizer ships a standalone Timekeeper — public, template-driven, WFDF time-limit signalling — which is prior art for exactly the clock question, in the same house. Worth reading before designing a clock UI rather than after.
- **Does this dissolve any upstream asks?** Several entries in `UPSTREAM.md` are "Live! does not record X" — possession, the first point's ratio, players per side, FMP/MMP matchings. In standalone this project *is* the system of record, so it could simply record them. That is worth noting but not celebrating: it would make standalone the more capable mode for mixed coverage, which is an odd position for a bridge to end up in, and it makes the case for the upstream asks stronger rather than weaker.
