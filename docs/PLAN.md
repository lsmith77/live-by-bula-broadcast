# Live Video Overlay System — Implementation Plan

**Status:** Phase 0 and Phase 1 complete and verified against real data. The scoreboard has since been rebuilt from a card into a broadcast bug and extended well past its Phase 1 scope — see "Scoreboard: from card to broadcast bug" and the feature table below it. Phase 2 item 1 is done; the rest of Phase 2 and Phase 3 are open.

Rebased on UltiOrganizer 4.0 + Live! by BULA 3.0.6; supersedes the January 2026 plan, which was written against UO 3.x/pre-release and a hand-patched Live! 1.9.16.

**Related:**

- `STUDIO.md` — the next architectural step: a full-frame stage hosting multiple cards with an operator deciding what is on screen. Its MVP is built. It also carries the data-availability findings and upstream feature requests that came out of building the scoreboard, and the evaluation of WebSockets and a Node extraction (rejected, §6).
- `COMMENTATOR.md` — a second-screen information surface for the commentary position. Nothing it shows goes to air, so it can use data the overlays must refuse.

**Last assessed:** 2026-08-22

**Still unverified on hardware:** nothing in this plan has been confirmed on the Magewell Director Mini. Whether its browser source runs JavaScript continuously is the single assumption everything else rests on, and it gates Phase 2 entirely.

## Current state

| | |
|---|---|
| UltiOrganizer | 4.0.0, `DB_VERSION 97`, at `origin/master` |
| Live! by BULA | 3.0.6, unmodified drop-in, no local patches |
| Dev stack | `docs/dev/compose.yaml` — PHP 8.3 + MariaDB 10.11, app on :8080, db on :3307 |
| Event | `HRN2026` (upstream test fixture), `api_public=1` |
| Working | `?view=live/overlays/index` (picker), `?view=live/overlays/scoreboard&game=<id>` |
| Superseded | `statistics/pregame.php` — deleted; the `pregame`/`halftime`/`postgame` cards do the job (`STUDIO.md` §9.3). Progression overlay removed |

---

## 1. Where things stand

### The version gap

| Component | This checkout | Current upstream | Note |
|---|---|---|---|
| UltiOrganizer | `DB_VERSION 81`, 66 commits behind `origin/master` | `v.4.0.0` (2026-07-06) + 66 commits, `DB_VERSION 97` | `ktolonen/ultiorganizer` |
| Live! by BULA | 1.9.16, locally patched | `v3.0.6` (2026-08-20) | Requires UO 4, PHP 8.3+, MariaDB 10.11+ |
| Overlays | ~2900 lines, never executed | — | Untracked (`?? live/overlays/`) |

**On the UO version to target.** The `uo-with-live-3.0.6.zip` bundle ships a UO at `DB_VERSION 95` (files dated 2026-08-15) — that is the pairing upstream actually tested. `origin/master` is two schema versions ahead at 97. Either target works, but if anything behaves oddly after Phase 0, the bundle is the known-good reference to diff against.

Upstream release history for context: Live! `v1.9.17` (2026-05-05) → `v2.0.0` (2026-07-23) → `v3.0.0` (2026-08-14, "Require UltiOrganizer 4.0") → `v3.0.6` (2026-08-20). The v1.9.16 in this checkout is four releases and one major version behind.

### Why the January attempt stalled

The `live/` tree in this checkout is 1.9.16 with hand-written compatibility shims, visible in `git diff live/`:

- `$dbcon` → `$mysqlconnectionref` in `admin.php`
- `\`-prefixed calls to global UO functions throughout `GameManager.php` / `TeamManager.php`
- UO library `require_once` calls manually re-added to the top of `api.php`
- `TeamSpiritStats2()` / `TeamSpiritTotal()` stubbed out with `// Spirit stats functions don't exist in this UltiOrganizer version` and hardcoded zeros

**Every one of these hacks is now obsolete.** Live! 3.x is a documented drop-in on an unmodified UO 4 site. The patched `live/` must be discarded, not carried forward — and it must not be committed in its current state (it is currently staged).

### What Live! v3 changed that affects overlays

From `docs/api-v3-changes.md` in the 3.0.6 release:

1. **Direct access to `live/*.php` returns HTTP 404.** `api.php` now guards on `!defined('LIVE_BULA_ENABLED')`. All API access must go through UO's front controller: `/index.php?view=live/api&entity=games&id=<id>`. This includes CORS preflight. → The existing `overlay-client.js` builds `../api.php`. Dead as written.
2. **All errors are JSON with `Content-Type: application/json; charset=UTF-8`**, and the body shape is `{"error": "<string>"}` — a plain string, not an object. → `overlay-client.js` reads `data.error.message`. Wrong shape.
3. **New status codes to handle:** `400` (bad/foreign id), `403` (event not public), `503` (maintenance — returns **HTML**, not JSON), `429` (voting only).
4. **`status: "completed"` changed meaning** — now "has started and is not ongoing", so 0-0 games and forfeits are `completed`. Anything keying "is this game live?" off `status` needs rechecking against `isongoing`.
5. **Ids are validated against the configured event.** A game id from a different tournament in the same database returns `400`, not data.
6. **`live/data/*.json` is explicitly not a stable interface** and is purged when the event is unpublished. Do not read the cache files directly.
7. **Spirit is `cat1..catN`, variable count**, defined by `reference → season.spiritCategories`. Nothing may hard-code five categories.

### What Live! v3 now ships that the January plan proposed to build

Live! v3 added: holds/breaks per team charted (v3.0.0, refined v3.0.5), tournament progression through pools and brackets (v3.0.0, v3.0.5), goal/assist distribution across rosters (v3.0.5), first-half/second-half scoring for games with a recorded halftime (v3.0.0), and a TV screen page with its own configuration interface.

**But check where each one actually lives before assuming you can consume it.** Two different answers, verified against the 3.0.6 release:

- **Tournament progression is server-side and reusable.** `entity=reference` now returns `pool_placements` — one `{pool_id, team_id, placement}` per team/pool membership, resolved by UO's own standings logic (`live/api/ReferenceData.php:167`), with `placement: null` while unresolved. An overlay can consume this directly. `scoreboard/progression.php` becomes a layout over real data.

- **Holds and breaks are *not* computed by the PHP API.** `grep -rn isbreakpoint live/api/*.php` returns nothing; neither does the same grep across the bundled UO. The derivation lives entirely in the React bundle (`assets/SingleGameWrapper-*.js`), which builds point objects carrying an `isbreakpoint` flag and buckets what it cannot classify into `unresolved`:

  ```
  o = points.filter(x => x.ishomegoal===1 && x.isbreakpoint===0).length + a.home   // home holds
  i = points.filter(x => x.ishomegoal===1 && x.isbreakpoint===1).length + a.home   // home breaks
  l = a.unresolved.filter(x => x.ishomegoal===1).length                            // uncallable
  ```

So an overlay that wants holds/breaks **must derive them itself** from the `goals` array that `entity=games&id=` already returns. This partially rehabilitates the January `calculateGameBreakHoldStats()` — the *algorithm* is a starting point even though its data access was wrong. Its core assumption is not, though: it infers initial possession from who scored the first goal, which is unknowable from the goal list alone. That is precisely the case v3.0.5 fixed by marking such points uncallable rather than guessing. Any reimplementation must carry an explicit unresolved bucket and must not silently attribute those points.

Otherwise the overlay system's job is *presentation for a video switcher* — transparent background, fixed 1920×1080 canvas, chroma-key friendly, no chrome — not statistics computation.

### The v3 public entity surface

Authoritative list from `live/api/EntityRouter.php`: `reference`, `games`, `teams`, `statistics`, `standings`, `players`, `playerevents`, `spirit`, plus the non-cacheable `config`, `config_static`, `hb`, and the privileged `wipe` / `warm`. Note `playerevents` is new since 1.9.16 and was not in the January plan's inventory.

New v3 infrastructure worth knowing about before touching anything: `SecurityHeaders.php` (sends CSP and framing headers), `SeasonAccess.php` (the event-publication boundary), `WarmManager.php` (a cache warmer that pre-generates payloads), and `SetupGuard.php`.

---

## 2. Salvage assessment

**Verdict: rebase, don't restart.** Roughly the presentation half survives; the data half does not.

### Keep (retarget, don't rewrite)

| File | Lines | Work needed |
|---|---|---|
| `shared/overlay-base.css` | 375 | None. Pure CSS, zero coupling. |
| `shared/overlay-client.js` | 260 | Three changes: routed URL, `{"error": "string"}` shape, handle 403/503/429. Backoff and error-counting logic is sound. |
| `scoreboard/scoreboard.php` | 348 | Keep markup + render functions; retarget field names; route via `?view=`. |
| `scoreboard/progression.php` | 460 | Keep layout only — feed it v3's progression data instead of its own. |
| `statistics/pregame.php` | 465 | Keep layout only — feed it v3's team stats instead of its own. |
| `index.php` (preview/selector) | 511 | Keep; update URLs and add a live-game picker. |

### Discard and rewrite

| File | Lines | Why |
|---|---|---|
| `shared/pregame-stats.php` | 269 | Every query is against a schema that does not exist. |
| `shared/team-colors.php` | 181 | Same. |

These two were never executed. Verified against `sql/ultiorganizer.sql` at `origin/master`:

- `DBFetchRow()` **does not exist** in `lib/database.php` (the real helpers are `DBFetchAssoc`, `DBQueryToRow`, `DBQueryToArray`). Every function in both files calls it — they would fatal on first use.
- `uo_game` has `hometeam` / `visitorteam` / `homescore` / `visitorscore` / `time`. The code queries `home_team` / `away_team` / `home_score` / `away_score` / `scheduled_time`.
- `uo_series_team` and `uo_season_pool` **do not exist**. The real join table is `uo_team_pool` (`team`, `pool`, `rank`, `activerank`); pool→series is `uo_pool.series`.
- `uo_spirit` **does not exist**, and neither do columns `rules` / `fouls` / `fair` / `positive` / `communication` / `to_team`. UO 4 stores spirit as `uo_spirit_score` (`game_id`, `team_id`, `category_id`, `value`) joined to `uo_spirit_category` — which is exactly why the v3 API exposes variable `cat1..catN`.

One premise did survive: **`uo_pool.color` is real** — `varchar(6)`, and `uo_series.color` too. There is **no** `uo_team.color`; team-level color is not a UO 4 concept, so the original plan's "pool color, with a per-team override" fallback chain collapses to pool → series → default. The approach is sound; only the query was wrong.

### Discard outright

The eight markdown files in this directory total ~150 KB against 2,869 lines of code — `ARCHITECTURE.md`, `EXAMPLES.md`, `FAQ.md`, `OPTIONAL_ENHANCEMENTS.md`, `STATISTICS_AVAILABLE.md`, `SUMMARY.md`, `QUICKSTART.md`, `README.md`. They document API shapes that changed, statistics that Live! now computes, and behavior that was never verified. Replace with one `README.md` written after the code runs.

---

## 3. Architecture (revised)

### Route through the front controller

The January plan accessed overlays by direct path (`/live/overlays/scoreboard/basic.php`). That is now the wrong entry point. UO 4's `resolveViewPath()` (`lib/common.functions.php:1426`) permits subdirectories and blocks traversal, so:

```
/index.php?view=live/overlays/scoreboard&game=1234
```

resolves to `live/overlays/scoreboard.php` and arrives with:

- an open database connection (`OpenConnection()` in `index.php`)
- a started session and resolved locale
- `LIVE_BULA_ENABLED` defined, so `live/api.php` will answer
- `EnforcePrivateEventAccessForView()` and Live's own `SeasonAccess::deny()` already applied
- `UO_ROUTED_VIEW` defined, so an overlay can refuse to render if reached directly

**Operational consequence to document:** an overlay for an event that is not published externally returns 403, same as the rest of Live!. A broadcaster testing before the event goes public will see this. It is correct behavior, but it will look like a bug if undocumented.

### Data flow

```
Video switcher (Magewell Director Mini / Yolobox / OBS browser source)
  │
  │  GET /index.php?view=live/overlays/scoreboard&game=1234
  ▼
live/overlays/scoreboard.php          ← validates game id, emits shell HTML
  │                                      + overlay-base.css + overlay-client.js
  │  poll, every N seconds
  ▼
GET /index.php?view=live/api&entity=games&id=1234
  ▼
live/api.php → GameManager → UO 4 lib functions → MariaDB
```

Server-side PHP does exactly two things: validate the game id and emit the shell. Everything live comes over the polled JSON. No overlay talks to the database directly — that is what made the January data layer both wrong and unnecessary.

### Directory layout

Runtime code sits at the top level, because a routed view's path *is* its URL — moving `scoreboard.php` into a subdirectory changes `?view=live/overlays/scoreboard`. Everything that is not served to a viewer is separated out, so the top level lists only things a browser actually requests:

```
live/overlays/
  scoreboard.php  stage.php  commentator.php  index.php    routed pages
  show.php  colors.php  lines.php  possession.php          routed JSON endpoints
  shared/          CSS, JS and the PHP stores behind those endpoints
  conf/            operator-authored state; gitignored, web-server-writable
  logos/           per-installation team crests; contents gitignored
  docs/            PLAN, STUDIO, COMMENTATOR, POSTPRODUCTION
  tests/           selftest.php (routed), playwright.config.js, e2e/
  fixtures/        dev-fixture.sql, dev-score.sh, logos/
  install/         the root .htaccess snippet, for pasting
```

`tests/selftest.php` is still a routed page — `?view=live/overlays/tests/selftest` — because it has to be loadable by the switcher it diagnoses, and a nested path routes fine.

**Nothing but runtime code sits at the top level**, and that is the rule the layout follows: a routed view's path *is* its URL, so moving `scoreboard.php` into a subdirectory would change `?view=live/overlays/scoreboard`. Everything else goes down a level, including `playwright.config.js` — which Playwright would rather find in the working directory, so `npm test` passes `--config`. A `tools/` directory will come back when the post-production CLI (`POSTPRODUCTION.md`) needs somewhere to live.

**Nothing here needs a `.gitattributes` entry.** `live` is classified `dev` in `docs/ai/release-package-coverage/inventory.txt` and the whole tree is already export-ignored (`git check-attr export-ignore live/overlays/scoreboard.php` confirms it), because Live! is a drop-in addon distributed separately rather than part of UltiOrganizer's release package. So this split is for the people reading the directory and for whatever packaging the overlays eventually get — not something `build-release.sh` acts on.

### Upgrade survival

`live/bin/update-from-github.sh` unzips the release **over** the existing tree; it does not wipe it. A self-contained `live/overlays/` directory (referencing only `live/api.php` through the routed URL, never editing a Live! core file) therefore survives a Live! upgrade untouched. This is the one structural assumption from the January plan that holds up, and it is worth preserving deliberately: **no overlay change may require editing a file that ships in the Live! zip.**

---

## 4. Work plan

### Phase 0 — Rebase the platform ✅ done

1. Bring UO to 4.0.0+: `git pull` (66 commits behind `origin/master`). Run the DB upgrade — `lib/database.php` declares `DB_VERSION 81`.
2. Verify PHP 8.3+ and MariaDB 10.11+.
3. Discard the patched `live/`: unstage it, delete it, extract `live-by-bula-3.0.6.zip` clean. Re-apply only real local config (`live/conf/LocalConfig.php`, `local-config.json`, logos).
4. Confirm the compat hacks are gone — `git diff live/` should be empty against the pristine 3.0.6 tree.
5. Run `php live/api.php --heartbeat` and load the Live! frontend. **Do not start Phase 1 until Live! 3.0.6 runs unmodified.**

*Exit criterion met: `?view=live/api&entity=games&id=700` returns 200 JSON on an unmodified `live/`, and `live/api.php` returns 404 as v3 intends.*

### Environment (resolved during Phase 0)

UO 4 ships its own Docker stack, so no DDEV and no hand-written compose file are needed:

```sh
cp docs/dev/.env.example docs/dev/.env      # DB_PORT set to 3307: 3306 is taken locally
docker compose -f docs/dev/compose.yaml up --build -d app db
docker compose -f docs/dev/compose.yaml exec -T db \
  mariadb -uroot -p<root-pw> ultiorganizer < sql/ultiorganizer.sql
```

`conf/config.inc.php` must use `DB_HOST = db` (the compose service name).

**Test data.** There was none: the January `sample_data.sql` at the repo root targets the same non-existent schema as the deleted overlay helpers (`uo_team.team`, `uo_poolteam`, `uo_spirit`) and will not load. It is superseded and should be deleted.

The real source is the upstream test harness, whose fixture is schema-correct by construction:

```sh
gh api repos/ktolonen/ultiorganizer-tests/contents/fixtures/baseline.sql \
  --jq .content | base64 -d > baseline.sql
```

It seeds event `HRN2026` with `api_public=1`, two teams, a finished game (700) and a scheduled one (701). Because it contains no *ongoing* game — the state overlays actually run against — `live/overlays/fixtures/dev-fixture.sql` adds game 702: live, 8–6, 14 goals, halftime cap and two timeouts. Load baseline first, then the dev fixture.

Finally point Live! at the event: `LIVE_SEASON_ID` = `HRN2026` in `live/conf/local-config.json`.

### Phase 1 — Retarget the client and one overlay ✅ done

Built:

- **`shared/overlay-client.js`** rewritten. Routed URL injected by the page (never guessed), `{"error": "string"}` parsing, 503-is-HTML handled without parsing, and 400/403 treated as *fatal* — a wrong game id or an unpublished event stops the loop instead of retrying through a broadcast. Everything else backs off exponentially to a cap.
- **Poll interval now follows the API.** `meta.expires_timestamp` says when the cached payload goes stale, clamped to [`interval`, 60s]. Measured: an ongoing game reports a 30s cache life, a finished game 31,536,000s — so a final score is fetched once and the January fixed 5s poll is gone.
- **`classifyPoints()`** derives holds and breaks from the goal list, since the API does not. Three buckets, matching Live!'s own model. Verified against game 702: home 5 holds + 2 breaks, visitor 5 holds + 1 break, 1 unresolved — 14 of 14 goals accounted for, and each side's total equals its score.
- **`scoreboard.php`** at the flattened routed path, `UO_ROUTED_VIEW`-guarded, `position` whitelisted against a fixed list, colours regex-validated rather than escaped into a class attribute. Bound to the real field names (`game_result.homescore`, `game_info.hometeamshortname`, `teams.visitorteam`) — the January code used `home_score` and `teams.awayteam`, neither of which exists.
- **`index.php`** rewritten as an operator picker: live games first, then scheduled, then final, each with a copyable browser-source URL. It reads through the same routed endpoint the overlays use, so anything listed is known to work.
- **Both PHP data helpers deleted.** `game_info.color` carries the pool colour and `teams.*.photos` carries logos, so overlays need **no database access at all** — a better outcome than the planned rewrite.

Resolved while building:

- **No framing or CSP headers reach overlay pages.** `SecurityHeaders::sendBrowserHeaders()` is called from `live/api.php` only, not by UO's front controller for arbitrary views, so a switcher that wraps an overlay in a cross-origin iframe is not blocked. The Director Mini needs no workaround.
- **Score animation no longer fires on first paint.** Every overlay animated up from 0 on load, which on a switcher reads as a goal being scored.

*Exit criterion met in the browser — finished, ongoing, and fatal-error states all verified. Still to confirm on the Director Mini itself.*

### Switcher test (Magewell Director Mini)

The Mini reaches the stack over the LAN: `docker compose` publishes the app on `0.0.0.0:8080`, so the browser-source URL is the Mac's LAN address, not `localhost`.

**Short URLs.** A switcher's on-screen keyboard makes a long URL genuinely expensive to enter, so the overlays have a two-character entry point:

```
http://<mac-lan-ip>:8080/s/702          scoreboard, transparent
http://<mac-lan-ip>:8080/s/702/green    ... on a chroma-key background
http://<mac-lan-ip>:8080/s/             picker
```

These come from two rewrite blocks, both internal rewrites onto `?view=` — nothing bypasses the guard that makes a direct request to `live/overlays/*.php` return 404:

| File | Routes | Note |
|---|---|---|
| `live/overlays/.htaccess` | `/live/overlays/702`, `/live/overlays/702/green`, `/live/overlays/` | Ours, self-contained, survives Live! upgrades |
| `.htaccess` (repo root) | `/s/702`, `/s/702/green`, `/s/` | **Diverges from upstream UO.** Delete the marked block if it ever conflicts on a pull — it costs only URL length |

Both need `mod_rewrite` and `AllowOverride All`; `docs/dev/Dockerfile.app` enables both. The root block declines to rewrite anything matching a real file or directory.

Two helpers exist for this:

- **`?bg=`** — the page is transparent by default. A switcher that cannot composite alpha needs a solid colour to key on instead: `&bg=green` (`#00B140`), `blue`, `magenta`, `black`, or any six-digit hex. This is the fallback for open question 1.
- **`fixtures/dev-score.sh`** — adds or removes a goal on the ongoing fixture game and drops the cached API payload, so a score change shows up on the next poll rather than up to 30s later. Without it the fixture is static and the test only proves the overlay renders, not that it updates.

  ```sh
  live/overlays/fixtures/dev-score.sh home      # or visitor / undo / show
  ```

Development only: it writes straight to the database rather than going through the scorekeeper.

### Operator controls on the picker

Two additions to `/s/` after the first switcher test:

**Scorekeeper links.** Each game row links to `/scorekeeper/?view=addscoresheet&game=<id>`, UO's own incremental scoresheet and game-clock page — the place the score is actually entered. Scorekeeper has its own login, so an unauthenticated click lands on its login page rather than an error. `fixtures/dev-score.sh` stays the shortcut for testing without logging in.

**Team colours.** UO 4 has no team colour: `uo_pool.color` and `uo_series.color` exist, but nothing on `uo_team`, `uo_team_profile` or `uo_club`. Without one, both sides of a scoreboard show the same pool value.

A team also does not have *one* colour. It brings a set of kits, and which one it wears is decided at the coin toss minutes before the pull — so the palette is prepared in advance and the per-game pick is made live, in one click on the game's row:

```json
{
  "palettes": { "300": ["D02129", "FFFFFF"] },
  "games":    { "702": { "home": "FFFFFF", "visitor": "1B1B1B" } }
}
```

| Piece | |
|---|---|
| `shared/colors.php` | The store. Validates to `RRGGBB`, de-duplicates, caps a palette at 8, and writes atomically via temp-file-and-rename so an overlay polling through a save never reads a half-written file |
| `conf/team-colors.json` | The data. Gitignored: runtime state, per installation, written by the web server |
| `colors.php` | Routed endpoint. `POST {"palettes": …}` edits the prepared kits; `POST {"game": 702, "home": …}` is the coin-toss write, kept deliberately small and separate |
| `index.php` | Palette editor per team, plus a Kit column on each game row — clicking a swatch sets that side's kit, clicking the active one clears it |

Saving requires the Live! admin session — the same one gating `entity=wipe`, via `SeasonAccess::isLiveAdminAuthenticated()` — so anyone who can view an overlay cannot rewrite its colours. Without it the editor renders read-only. Log in at `?view=live/admin` first.

Precedence in the scoreboard is **URL parameter → this game's kit → the team's first kit → pool colour**, so `&homecolor=` still wins for a one-off and a cleared pick falls back cleanly.

`live/overlays/conf/` must be writable by the web server. The dev stack bind-mounts the repo and Apache runs as `www-data`, so it needs `chmod 777 live/overlays/conf` locally — the same dance `docs/local-development.md` documents for `conf/` during install. The endpoint reports a clear error rather than failing silently if it cannot write.

### Scoreboard: from card to broadcast bug

The layout as inherited was a **card** — symmetric, centre-weighted, 800×181 (about 4.4:1), with a shared centred score between the two teams. It was replaced by a **bug**: a single strip, measured at **1446×96** plus a 28px context ribbon, anchored bottom-left inside a 54/60px title-safe inset.

Area was never the problem: 800×181 is 6.98% of a 1920×1080 frame, inside the 5–10% broadcast norm. The *distribution* was. The rewrite trades height for width at roughly constant area.

Structure, reading outward from the centre:

```
[logo][seed] NAME (kit fill) [SCORE] │ CLOCK │ [SCORE] (kit fill) NAME [seed][logo]
                          ── context ribbon ──
```

Each score sits against its own team rather than in a shared pair, so name and number are one eye movement and there is no separator glyph to parse. Both scores stay inboard, flanking the clock, so the pair can still be read together.

What was learned building it, all verified by measurement rather than inspection:

- **Team names are one line, always.** The earlier two-line wrap is most of what made the card tall. A condensed face carries the longest real names instead — but the font stack cannot be trusted: measured on macOS, `font-stretch: condensed` is **inert** (551px, identical to normal), and Roboto Condensed / Archivo Narrow / Oswald are all absent without shipping a webfont. Arial Narrow is the only broadly portable condensed face, and even it renders `Mosquitos Klosterneuburg` at 468px against a 420px box. So `fitName()` measures each name after layout and steps its size down to a 24px floor. It must run *after* the board is visible: a hidden element reports `scrollWidth` and `clientWidth` as 0, and every name silently "fits".

- **Kit colour fills the name block.** The old 8px bar sat at the outer edge — the least-read position on the strip — and was thin enough to need an inset highlight to survive a dark kit. As a fill it is read first, the highlight hack is gone, and the foreground is computed per block from the fill's luma. A team with no kit gets a neutral block, which looks deliberate where two identical slivers looked broken.

- **Logo plates are chosen per logo, from the artwork's own pixels.** Ultimate logos are overwhelmingly monochrome marks on transparency, and they come in both polarities — a navy crest and a white wordmark are equally common, sometimes in the same game. A single plate setting cannot serve both, so each logo is drawn to a canvas, the mean luma of its non-transparent pixels is measured, and it gets a light plate below ~140 and a dark one above. `?logoplate=light|dark|none` overrides. Cross-origin art taints the canvas; that is caught and the neutral default stands.

- **Logos must not shrink.** As flex items they were being squeezed by long names. `flex-shrink: 0` pins them; height follows the bug (96px) with width up to 120px, so a wordmark stays legible instead of being crushed into a square. Note the fixture `logos/301.svg` was itself malformed — its text was ~265px wide inside a 220px viewBox, so it overflowed its own canvas and looked like a CSS clipping bug. `object-fit: contain` was correct all along; the artwork was fixed.

- **Seed replaces the win-loss record.** `teams.*.seed` is served directly. A record of 1-0 on day one carries almost no information; a seed is meaningful all week and costs one glyph.

- **Colour must never be the only signal.** Measured with a deuteranope simulation: solid green vs solid red is 1.50:1 in normal vision and **1.18:1** for a deuteranope — two indistinguishable blocks. Hold/break therefore separate by *lightness* as well as hue (light green block with dark text, dark red block with white text): 5.70:1 and 4.79:1. The words carry the meaning; colour only reinforces. Every text contrast on the bug clears WCAG AA.

### Scoreboard features added beyond the original plan

| feature | notes |
|---|---|
| **Game clock** | Derived, not read — UO has no countdown anywhere. Mirrors `GameClockState()` (`lib/game.functions.php:1042`): `elapsed = now − timer_start − timer_paused_duration`, minus any open pause. `timer_start` is unix **seconds**. Ticks locally every second so it does not wait on the 30s poll, with server skew corrected from `meta.generated_timestamp`. |
| **Countdown** | When `poolinfo.timecap` is set (minutes — the admin form says so), the clock counts down to it and clamps at 0:00; otherwise it counts up. |
| **Cap states** | `half_cap` → amber, `time_cap` → red, matching Timekeeper's own convention (`docs/timekeeper.md:99-101`), with the new point cap shown (`TIME CAP · TO 4`). Dropped once the game is not running: red behind "Final" reads as an alarm. |
| **Timeouts** | Ticks under each name. Allowance from `poolinfo.timeouts`; taken counted from `gameevents`. `timeoutsper: "half"` resets the allowance at the half, so only timeouts after the `half_cap` event count in the second half. |
| **Hold / break callout** | A tab above the bar over the scoring team, sharing one `FLASH_MS` with the score flash so the two read as one event. Derived from the goal list alone — whoever concedes receives next. |
| **`ON DEFENCE` tag** | Standing context, not an event. Deliberately *not* called "break chance": that is an event claim requiring possession data UO does not record. Styled smaller and quieter than an outcome tab so it does not compete. |
| **`?demo=1`** | Walks every display state from one real fetched payload — scheduled, live, hold, break, timeout, cap, paused, final. The only way to see a running clock locally, since the fixture's `timer_start` is null. Also the sharpest switcher test: no polling or server cache in the path. |
| **`tests/selftest`** | Four independently moving panels (JS timer, rAF, pure CSS, network) so a switcher that fails to update can be diagnosed by *which* layer is frozen. |
| **Asset cache-busting** | `?v=<filemtime>` on CSS and JS, and `Cache-Control: no-store` on the page. Neither asset sent any cache header, so browsers cached them heuristically and a stale stylesheet survived a rewrite — harmless on a laptop, serious on a switcher mid-broadcast. |
| **`?reload=<seconds>`** | Meta-refresh fallback for a browser source that rasterises once and never runs timers. Off by default; a reload flashes the overlay. |

### Phase 2 — Fill out the set

Only after Phase 1 is confirmed on hardware:

1. ~~Detailed scoreboard: timeouts, halftime indicator, pool name.~~ **Done** — timeouts as ticks with the half reset, cap states as the halftime/time-cap indicator, and pool plus round in the context ribbon. See "Scoreboard features added beyond the original plan" above.
2. Lower-third / minimal variant. (`?size=compact` covers part of this.)
3. Retarget `progression.php` onto `entity=reference` → `pool_placements` — layout survives, data source changes.
4. Retarget `pregame.php` onto `entity=teams` for record/scores. If it needs holds and breaks, derive them in `overlay-client.js` from the `goals` array, mirroring the React bundle's three-bucket model (hold / break / unresolved) rather than the January two-bucket one.
5. Rewrite `index.php` as a preview page that lists currently-live games with copyable URLs.

### Phase 3 — Document

One `README.md`: the URL scheme, the URL parameters, the switcher setup (Director Mini, Yolobox, OBS), the 403-when-unpublished gotcha, and the upgrade-survival rule.

---

## 5. Constraints

- **All new code stays inside `live/overlays/`.** No edits to files that ship in the Live! zip.
- **No database schema changes.** `uo_pool.color` already exists.
- **No direct DB access from an overlay page** beyond the color/logo lookup — everything live goes through the routed API.

  *Under review.* `STUDIO.md` §3.4 identifies the one case that would justify revisiting this: per-player block counts exist in `uo_defense` and via `GameTeamDefenseBoard()`, but Live!'s `GameManager` never exposes them, so a stats card cannot reach them through the API at all. Reading them from an overlay-side routed endpoint would not breach the Live!-upgrade rule — nothing is added to the Live! zip — but it would relax this constraint. Decide it once, deliberately, and for statistics only.
- **Do not hard-code five spirit categories** anywhere.
- **Do not recompute progression** — `pool_placements` is server-side. Holds and breaks *must* be recomputed (the API does not provide them), and must carry an unresolved bucket.
- Target: OBS/CEF and the Director Mini's browser source. Fixed 1920×1080, transparent background, no scrollbars, GPU-friendly CSS transforms only.

## 6. Open questions

Answered during Phase 1:

- ~~Framing/CSP headers on overlay pages~~ — none are set. A switcher may iframe an overlay cross-origin without a workaround.
- ~~Poll interval~~ — the client now follows `meta.expires_timestamp` instead of a fixed interval. A finished game is cached for a year, so a final score is fetched once.

**Settled: the 30s comes from a different constant family entirely.** An earlier note here guessed it was the `CACHE_MINUTES_ACTIVE_NO_ONGOING` branch. It is not. `getGameDetail()` uses its own set — `CACHE_SECONDS_GAME_DETAIL_*` at `live/api/GameManager.php:14-19` — and picks `CACHE_SECONDS_GAME_DETAIL_ONGOING = 30` purely on `game_result.status === 'ongoing'` (`GameManager.php:358-361`). The `CACHE_MINUTES_*` constants govern `entity=games` list requests, which no overlay makes. So for a single game the lifetime is a **flat 30s**, not 10s and not variable.

**Do not lower `CACHE_MINUTES_MODULATOR` to compensate.** Upstream documents that setting as "DO NOT LOWER BELOW 1.0" (`live/api/ConfigManager.php:642`), and it is global — it scales the public frontend's caching too, not just overlays. Earlier revisions of this plan recommended exactly that; the recommendation was wrong.

The supported way to cut score latency is an overlay-side uncached endpoint reading the score directly, which stays inside `live/overlays/` and adds nothing to the Live! zip. See `STUDIO.md` §6. `?interval=1000` still lowers the client-side floor but cannot beat the server cache on its own.

Note this is also the most likely explanation for "the switcher is not refreshing": up to 30s of server cache plus the client's wait is easily mistaken for a dead browser source. `?view=live/overlays/tests/selftest` and `?demo=1` distinguish the two.

Still open:

1. **Does the Director Mini's browser source support transparency?** OBS does, and the overlay renders correctly transparent in Chrome. If the Mini does not, overlays need an opaque chroma-key background colour as a URL option — a small addition to `scoreboard.php`.
2. **Multi-field tournaments:** one overlay URL per field, or one URL that follows "whichever game is live on field N"? The latter needs a resolver against `entity=games`; the picker page already has the sorting logic it would reuse.
3. **Does `pool_placements` cover bracket play**, or only pools? The field name says pools; the release notes say "pools and brackets". The `HRN2026` fixture has a single pool and no bracket, so this cannot be settled without richer test data — it gates the Phase 2 progression overlay.
4. **Fixture depth.** `HRN2026` has two teams and one pool. Enough to prove the pipeline, not enough to exercise progression, standings, or spirit overlays. Phase 2 needs either a larger hand-built fixture or a sanitised dump from a real tournament.

---

## 7. Where the facts came from

Nothing in this plan is from release notes alone — each claim was checked against source. To re-verify after any upgrade:

- **Live! v3 API contract:** `docs/api-v3-changes.md`, which ships **only inside the release zip** — it is not in the GitHub repo tree, so it cannot be fetched from a raw URL. `gh release download v3.0.6 --repo layoutd/live-by-bula --pattern "*.zip"`.
- **The two zips:** `live-by-bula-3.0.6.zip` (the `live/` directory alone) and `uo-with-live-3.0.6.zip` (the tested UO + Live! pairing). The latter is how the bundled UO's `DB_VERSION 95` was established.
- **UO 4 schema:** `sql/ultiorganizer.sql` at `origin/master` — the source for every table/column claim in §2.
- **UO 4 DB helpers:** `lib/database.php` (`DBQuery`, `DBFetchAssoc`, `DBQueryToRow`, `DBQueryToArray`; no `DBFetchRow`).
- **View routing:** `resolveViewPath()` at `lib/common.functions.php:1426`.
- **Live! entity list:** `live/api/EntityRouter.php`.
- **Holds/breaks location:** `grep -rn isbreakpoint` across `live/api/*.php` and the bundled UO (both empty) vs `live/assets/SingleGameWrapper-*.js` (present).
- **Local damage assessment:** `git diff live/` against the staged 1.9.16 tree.
