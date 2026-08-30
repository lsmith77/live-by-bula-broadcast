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

1. **A payload provider.** `shared/overlay-client.js` polls for the scoreboard; `commentator.php` fetches four entities directly (`games&id`, `teams&id`, `playerevents&id`, and the games list) and `stage.php` three (`teams&id` twice, `config`). **Seven API call sites across two files, plus the client** — they become one module, `shared/provider.js`, answering the same questions from either source, and the switch is one setting.

Worth noting what that count excludes. The pages make fourteen `fetch` calls in total, and **half of them already talk to this project's own stores** — `notesUrl`, `linesUrl`, `showUrl` — which are flat files under `conf/` and need no provider at all. Standalone does not touch them. The coupling is smaller than a raw grep for `fetch` suggests.
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
| Goals: who scored, who assisted, when | Whoever is watching — see [`MATCHCONTROL.md`](MATCHCONTROL.md) | `conf/standalone/goals-<id>.json` | The one genuinely new input surface |
| The clock | same | the game file | Three integers |

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

That has a pleasant side effect. Today the browser suite cannot run in CI at all, because Live! is third-party software under a signed Terms of Use that a public workflow cannot check out (`.github/workflows/ci.yml`). Standalone mode is what would eventually make it runnable — no database, no Live!, no licence.

**But not a provider alone, and it is worth being exact about that.** The pages are served through UltiOrganizer's front controller and refuse to load without it (`UO_ROUTED_VIEW`), so a payload provider with nothing to serve the pages gets a workflow no further. Running the browser suite in CI needs **milestone 2 and milestone 3 together** — a provider that needs no API, and a front controller that needs no host. Either alone is not enough.

**It must not quietly answer the licensing question.** Live! by BULA is distributed under a signed Terms of Use, and these overlays are built for it. A mode that runs without it is not obviously a circumvention — it serves games Live! was never going to hold — but it is a question for the people who signed, not one to settle by shipping. Ask before publishing.

## 7. A staging that produces something useful early

1. **The provider seam, hosted only.** Introduce `shared/provider.js`, route the four call sites through it, change nothing else. Behaviour identical, and it is independently verifiable: the whole suite must stay green with no other change. This is the commit that makes everything after it cheap.
2. **A recorded-payload provider — built.** `Provider.recorded()` answers the same questions from a capture on disk, `tests/capture.mjs` writes one by driving `Provider.live()` against an instance, and a page picks between them through `Provider.fromConfig()` on one setting. The commentary desk now renders a real game with no UltiOrganizer, no Live!, no database and no network — asserted end to end in the standalone suite. See §7a.
3. **Auth and routing seams — built.** `app.php` is a front controller with an explicit allow-list of the eleven views this directory serves, `shared/auth.php` is the one place anything asks "is this an admin", and `login.php` is the standalone door. `Api\ConfigManager` and `Api\SeasonAccess` are no longer named anywhere outside `Auth`, so the PHP coupling to the host is now a single `class_exists()` check. See §3a.
4. **The editor.** Teams, a game, the clock, and goal entry — the operator surface. This is the biggest single piece and the one worth prototyping against a real broadcast before committing to a shape.
5. **Rosters via the existing CSV**, promoted to source of truth.
6. *(Later, maybe never)* accumulation across games within a standalone event.

Milestone 3 is the first point at which somebody who does not run UltiOrganizer can use this project. Milestone 1 is worth doing regardless of whether the rest ever happens.

### 3a. What milestone 3 turned up

**An allow-list rather than a resolver.** UltiOrganizer resolves `?view=` against the filesystem because it serves hundreds of pages and cannot enumerate them — it rejects `..`, restricts the characters, then confirms the realpath is still inside the base directory. Correct for that problem, and a design whose safety rests on three checks being right. This directory serves eleven pages and can name all of them, so `app.php` does: **no part of the request ever becomes part of a path**, and there is no traversal to defend against rather than a filter that must hold.

**`php -S` does not read `.htaccess`, and §8 recommends `php -S`.** That combination was a live hole, found by making the request rather than by reading the code: a standalone deployment served `conf/notes/<room>.json` — the desk's prepared notes, about named people — to anyone who asked. `app.php` now carries the same default-closed rule in a router block, and thirteen tests drive it over HTTP.

**The shipped `.htaccess` had the same bug in a quieter form.** Its denial was anchored on `^/live/overlays/conf/`, a path that only exists in a hosted installation. Standalone serves this directory at the document root, where that pattern matches nothing while looking entirely correct. Now anchored on `conf/` itself.

**And the first version of the tests proved nothing.** They ran against the working copy, where `live/vendor/autoload.php` sits two directories above `shared/auth.php` — so `Auth::isHosted()` was answering *true* and every assertion labelled "without Live!" was exercising the hosted path. The suite now builds a throwaway tree with no host above it, and asserts that it really is one before asserting anything else.

### 2a. What milestone 2 turned up

**The hosted URL layout was written into every page.** Each one built its asset URLs as `<prefix>/live/overlays/...` in its own helper, and four wrote `?view=live/overlays/<endpoint>` for the line, note, possession, colour and show stores. All of it correct, and all of it an assumption that dissolves when this directory *is* the document root. It is now asked once, of `Overlays\Mode`.

`commentator.php` was the worst of them: it wrote the path inline for all ten of its script tags rather than using a helper, which made it both the one page that could not be served from anywhere else and the one page with no cache-busting — so an operator could be running last week's logic.

**The front controller served the picker for any unknown path.** A request for a missing asset fell through to the dispatcher, which defaults to `index`, so a mistyped script URL answered **200 with a page of HTML**. The browser reported it as `Unexpected token '<'`, which is a long way from "that file is not there". Unknown paths now 404.

**And the clock needed the manifest more than expected.** Rebasing on every read holds a recording at the minute it was taken, which is what makes it usable in a test that must still pass next year. Rebasing once at start makes the clock run on from there, which is what a demo wants. Both are one option apart, and getting it wrong is invisible until the recording is a day old.

### 7a. What "captured JSON" means

**Not the `conf/` stores.** Worth separating first, because the two bodies of JSON are easy to conflate. `conf/show.json`, `team-colors.json`, `possession-<game>.json`, `lines/` and `notes/` are *this project's own* operator-authored state — already local, already the authority for what they hold, and they need no provider because standalone does not change them at all (§2). What milestone 2 records is the other half: what **Live!** answers, which today exists only in flight. Every poll fetches it and throws it away.

**Captured by what:** `tests/capture.mjs`, which drives `shared/provider.js` itself against a live instance and writes down what comes back:

```
node tests/capture.mjs --game 702 --out fixtures/payloads/dev
```

Recording through the provider means the capture is *by construction* in the shape the provider returns. Using the provider to record is not a convenience — it means the recording is *by construction* in the shape the provider returns, so there is no third format to keep in step with the other two.

**Containing what:** one directory per capture, one file per request. For a single game that is the game list, the game's detail, the team list, both teams' rosters, `reference`, `config`, and one `playerevents` per player on both squads — bounded and small, around sixty files for two 28-player rosters.

Plus a **manifest** recording the instant of capture and which event and game it came from. That is not bookkeeping, and it is the part that would be easy to leave out:

> **A capture is a snapshot; the clock is not.** `timer_start` is absolute unix seconds and the scoreboard computes `now - timer_start`. A payload recorded on Saturday and replayed on Tuesday shows a game that has been running for three days.

So the replay provider has to rebase the clock by the age of the capture — which is the **synthetic `timer_start`** trick this project already performs twice, in `shared/demo.js` (fixture 702 has `timer_start: null`, so the demo manufactures one to show a running clock at all) and in the post-production frame. Third time; it should probably be shared code by then.

**What a capture is not: a fixture to hand-edit.** The moment somebody adjusts a recorded payload it stops being evidence of what Live! actually sends and becomes a hand-written fake with extra steps — which `demo.js` already warns about: *"a hand-written fake payload drifts from the API shape, and then the demo starts proving things about a scoreboard nobody ships."* Variation belongs in a mutation layer over the recording, which is exactly how `demo.js` works and is the model to copy.

### What milestone 2 actually buys on its own

A fair challenge, and the honest answer is *less than the staging order implies* — so here is what it does and does not do.

**It does not, by itself, get the browser suite into CI.** The pages refuse to load outside UltiOrganizer's front controller, so recorded payloads with nothing to serve the pages are of no use to a workflow. That needs milestone 3 as well.

**What it does buy alone, in order of how much:**

- **Reproducing a real broadcast.** Today, a defect seen at a tournament is gone the moment the game ends: the payload existed only in flight, and nothing keeps it. With a capture, "send me the recording" makes the bug reproducible on a laptop, exactly, months later. There is no other route to that.
- **Fixture depth.** `PLAN.md` already lists this as an open item — `HRN2026` is *"two teams and one pool — enough to prove the pipeline, not enough to exercise progression, standings or spirit"*, and it says the fix is *"either a larger hand-built fixture or a sanitised dump from a real tournament."* A capture is that dump, and it arrives already in the right shape rather than being maintained by hand.
- **Deterministic rendering tests.** The suite currently drives a live instance whose state its own tests mutate. Rendering assertions against a fixed recording cannot be perturbed by the test that ran before them.

**And a reason that only appears if §3 is taken seriously.** Standalone's authored store must emit Live!'s payload shape, warts and all. If it does, then **a capture *is* a standalone event** — the same files, one recorded and one typed — and the reader built here is the reader standalone uses, not a throwaway. That is the strongest argument for doing it, and it is entirely contingent on not letting the authored format drift into something tidier.

**If the goal is standalone working as soon as possible, this milestone can be deferred.** Milestone 1 → 3 → 4 is a coherent path, and the capture reader then falls out of the editor's store reader rather than preceding it. What is lost by deferring is the debugging capability, which is worth more than it sounds and costs little to build early.

**A snapshot is one frame.** Replaying a game *progressing* needs a series of captures with their timestamps, played back in order. That is worth having eventually — it is the only way to test what an overlay does as a score changes without a live game — but it is a second step, and the first one is worth shipping without it.

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

- **Who keeps the score?** Answered separately, in [`MATCHCONTROL.md`](MATCHCONTROL.md): a small phone-shaped surface carrying score and clock alone, gated by capability rather than by screen, so a crew of one to four arranges itself. The load-bearing conclusion there is that a goal must be written as *the point it creates* rather than as `+1`, which is what makes it safe for more than one person to hold the button.
- **One game or an event?** A per-game file is simpler and matches how `conf/possession-<game>.json` is already keyed. An event file is what standings and totals would need. Start per-game; the possession store's history is a warning about shared documents.
- **Where do game ids come from?** They have to be stable, because every store in this project is keyed by them. Operator-assigned integers are fine and boring.
- **Does the Timekeeper app help?** UltiOrganizer ships a standalone Timekeeper — public, template-driven, WFDF time-limit signalling — which is prior art for exactly the clock question, in the same house. Worth reading before designing a clock UI rather than after.
- **Does this dissolve any upstream asks?** Several entries in `UPSTREAM.md` are "Live! does not record X" — possession, the first point's ratio, players per side, FMP/MMP matchings. In standalone this project *is* the system of record, so it could simply record them. That is worth noting but not celebrating: it would make standalone the more capable mode for mixed coverage, which is an odd position for a bridge to end up in, and it makes the case for the upstream asks stronger rather than weaker.
