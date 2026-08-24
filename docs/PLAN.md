# The platform, and what is left to build

**Status:** the scoreboard, the stage and the commentator page are built, tested and documented. What remains is hardware verification, the post-production tool, and a handful of things that need upstream changes — §5.

This file is the **platform reference**: what Live! v3 gives an overlay, what it does not, and the facts that were expensive to establish. It also records why the scoreboard looks the way it does, because that reasoning does not survive in the CSS.

**Related:**

- [`STUDIO.md`](STUDIO.md) — the stage, the cards, show state, and the full account of what the data supports.
- [`COMMENTATOR.md`](COMMENTATOR.md) — the second screen. Nothing it shows goes to air, so it can use data the overlays must refuse.
- [`POSTPRODUCTION.md`](POSTPRODUCTION.md) — putting a scoreboard on a recording after the fact.

**Last assessed:** 2026-08-23

## 1. Current state

| | |
|---|---|
| UltiOrganizer | 4.0.0, `DB_VERSION 97`, at `origin/master` |
| Live! by BULA | 3.0.6, unmodified drop-in, no local patches |
| Dev stack | `docs/dev/compose.yaml` — PHP 8.3 + MariaDB 10.11, app on :8080, db on :3307 |
| Event | `HRN2026` (upstream test fixture), `api_public=1` |
| Surfaces | `/s/` studio · `/s/<game>` scoreboard · `/s/<game>/overlay` stage · `/s/field/<n>/overlay` field-following stage · `/c/<game>` commentator |
| Tests | 56 Playwright specs, `npm test` |
| **Unverified on hardware** | **nothing here has run on a Magewell Director Mini or a Yolobox.** Whether the browser source runs JavaScript continuously is the assumption everything rests on |

---

## 2. The platform

### Routing through the front controller

Overlays are reached as UO views, never by direct path:

```
/index.php?view=live/overlays/scoreboard&game=1234
```

`resolveViewPath()` (`lib/common.functions.php:1426`) permits subdirectories and blocks traversal. The page arrives with an open database connection, a started session and resolved locale, `LIVE_BULA_ENABLED` defined so `live/api.php` will answer, `EnforcePrivateEventAccessForView()` and `SeasonAccess::deny()` already applied, and `UO_ROUTED_VIEW` defined so an overlay can refuse to render if reached directly.

Direct access to `live/*.php` returns **404** by design in v3 — `api.php` guards on `!defined('LIVE_BULA_ENABLED')`. This includes CORS preflight.

**An overlay for an unpublished event returns 403**, the same as the rest of Live!. A broadcaster testing before the event goes public will hit this. It is correct, and it looks exactly like a bug if undocumented.

### The v3 API contract

The things that bite, from `docs/api-v3-changes.md` in the release zip:

1. **Errors are JSON with the shape `{"error": "<string>"}`** — a plain string, not an object. Anything reading `error.message` is wrong.
2. **Status codes to handle:** `400` (bad or foreign id), `403` (event not public), `503` (maintenance — returns **HTML**, not JSON), `429` (voting only). 400 and 403 are fatal and must stop the poll loop rather than retry through a broadcast.
3. **`status: "completed"` means "has started and is not ongoing"**, so 0-0 games and forfeits are `completed`. Key "is this live?" off `isongoing`, never off `status`.
4. **Ids are validated against the configured event.** A game id from another tournament in the same database returns 400, not data.
5. **`live/data/*.json` is not a stable interface** and is purged when the event is unpublished. Never read the cache files directly.
6. **Spirit is `cat1..catN`, variable count**, defined by `reference → season.spiritCategories`. Nothing may hard-code five categories.

### The public entity surface

From `live/api/EntityRouter.php`: `reference`, `games`, `teams`, `statistics`, `standings`, `players`, `playerevents`, `spirit`, plus the non-cacheable `config`, `config_static`, `hb`, and the privileged `wipe` / `warm`.

Field names that have caught people out, all verified against real payloads: `game_result.homescore` / `visitorscore` / `isongoing`, `game_info.hometeamshortname`, `teams.hometeam` and `teams.visitorteam` (**not** `awayteam`), `game_info.color` for the pool colour, `teams.*.photos` for logos, `teams.*.seed`, `poolinfo.timecap` (minutes) and `poolinfo.timeouts`.

### What the API does not compute

**Holds and breaks are not computed server-side.** `grep -rn isbreakpoint live/api/*.php` returns nothing, and neither does the same grep across the bundled UO — the derivation lives entirely in the React bundle (`assets/SingleGameWrapper-*.js`). An overlay that wants them **must derive them itself** from the `goals` array.

The one trap: initial possession is not knowable from the goal list alone. Any derivation must carry an explicit **unresolved** bucket and must not silently attribute those points — which is exactly what Live! v3.0.5 fixed in its own implementation. `classifyPoints()` in `shared/overlay-client.js` mirrors the three-bucket model.

**Tournament progression, by contrast, is server-side and reusable.** `entity=reference` returns `pool_placements` — one `{pool_id, team_id, placement}` per team/pool membership, resolved by UO's own standings logic (`live/api/ReferenceData.php:167`), with `placement: null` while unresolved. Do not recompute it.

**Turnovers do not exist anywhere**, which is why break chance is operator-declared rather than derived. `STUDIO.md` §3 is the full account of what the data supports.

### Caching, and where score latency comes from

`getGameDetail()` uses `CACHE_SECONDS_GAME_DETAIL_*` (`live/api/GameManager.php:14-19`) and picks `CACHE_SECONDS_GAME_DETAIL_ONGOING = 30` purely on `game_result.status === 'ongoing'`. So for a single game the lifetime is a **flat 30s** — not variable, and not the `CACHE_MINUTES_*` family, which governs `entity=games` list requests that no overlay makes.

The client follows `meta.expires_timestamp` rather than a fixed interval, clamped to [`interval`, 60s]. A finished game reports a cache life of a year, so a final score is fetched once.

**Do not lower `CACHE_MINUTES_MODULATOR` to compensate.** Upstream documents it as "DO NOT LOWER BELOW 1.0" (`live/api/ConfigManager.php:642`) and it is global — it scales the public frontend's caching too. An earlier revision of this plan recommended exactly that; the recommendation was wrong. The supported fix is an overlay-side uncached endpoint reading the score directly, which stays inside `live/overlays/` (`STUDIO.md` §6).

This is also the most likely explanation for "the switcher is not refreshing": 30s of server cache plus the client's wait is easily mistaken for a dead browser source. `?view=live/overlays/tests/selftest` and `?demo=1` tell the two apart.

### Schema facts

- **`uo_pool.color` is real** (`varchar(6)`), and so is `uo_series.color`. There is **no** `uo_team.color` — team colour is not a UO 4 concept, so the fallback chain is pool → series → default, and per-team kit colour has to be stored by the overlays themselves (§4).
- **No framing or CSP headers reach overlay pages.** `SecurityHeaders::sendBrowserHeaders()` is called from `live/api.php` only, not by UO's front controller for arbitrary views, so a switcher may wrap an overlay in a cross-origin iframe without a workaround.
- **UO has no countdown clock anywhere.** It is derived — see the clock row in §4.

---

## 3. Architecture

### Data flow

```
Video switcher (Magewell Director Mini / Yolobox / OBS browser source)
  │
  │  GET /index.php?view=live/overlays/scoreboard&game=1234
  ▼
live/overlays/scoreboard.php          ← validates game id, emits shell HTML
  │                                      + overlay-base.css + overlay-client.js
  │  poll, following meta.expires_timestamp
  ▼
GET /index.php?view=live/api&entity=games&id=1234
  ▼
live/api.php → GameManager → UO 4 lib functions → MariaDB
```

Server-side PHP does exactly two things: validate the game id and emit the shell. Everything live comes over polled JSON.

**Two polls, deliberately different.** Show state is a *static* `conf/show.json` at ~1s, because an operator's click must feel instant; possession rides the same fast channel because a break chance is worthless ten seconds late. Game data follows the 30s cache. Routing show state through PHP would put a request per stage per second onto the same box serving the tournament's public site.

**One renderer, never two.** The scoreboard's `?at=<seconds>&goals=<n>&phase=…` draws a single deterministic frame for post-production by handing the existing `render()` a truncated payload — not by reimplementing anything.

### Directory layout

Runtime code sits at the top level, because a routed view's path *is* its URL — moving `scoreboard.php` into a subdirectory changes `?view=live/overlays/scoreboard`. Everything not served to a viewer goes down a level:

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

`tests/selftest.php` is still a routed page — `?view=live/overlays/tests/selftest` — because it has to be loadable by the switcher it diagnoses. `playwright.config.js` lives in `tests/` rather than the working directory Playwright would prefer, so `npm test` passes `--config`. A `tools/` directory will come back when the post-production CLI needs somewhere to live.

**Nothing here needs a `.gitattributes` entry.** `live` is classified `dev` in `docs/ai/release-package-coverage/inventory.txt` and the whole tree is already export-ignored, because Live! is a drop-in addon distributed separately rather than part of UltiOrganizer's release package.

### Upgrade survival

`live/bin/update-from-github.sh` unzips the release **over** the existing tree; it does not wipe it. A self-contained `live/overlays/` — referencing `live/api.php` only through the routed URL, never editing a Live! core file — therefore survives a Live! upgrade untouched. **No overlay change may require editing a file that ships in the Live! zip.**

---

## 4. What was built, and what it cost to learn

### Environment and fixtures

```sh
cp docs/dev/.env.example docs/dev/.env      # DB_PORT 3307: 3306 is taken locally
docker compose -f docs/dev/compose.yaml up --build -d app db
docker compose -f docs/dev/compose.yaml exec -T db \
  mariadb -uroot -p<root-pw> ultiorganizer < sql/ultiorganizer.sql
```

`conf/config.inc.php` must use `DB_HOST = db` (the compose service name), and `live/overlays/conf/` must be writable by the web server — the dev stack bind-mounts the repo and Apache runs as `www-data`, so `chmod 777 live/overlays/conf` locally.

Test data comes from the upstream harness, whose fixture is schema-correct by construction:

```sh
gh api repos/ktolonen/ultiorganizer-tests/contents/fixtures/baseline.sql \
  --jq .content | base64 -d > baseline.sql
```

It seeds event `HRN2026` with `api_public=1`, two teams, a finished game (700) and a scheduled one (701). Because it contains no *ongoing* game — the state overlays actually run against — `fixtures/dev-fixture.sql` adds game 702: live, 8–6, 14 goals, halftime cap, two timeouts, and 28-player squads. Load baseline first, then the dev fixture. Point Live! at the event with `LIVE_SEASON_ID = HRN2026` in `live/conf/local-config.json`.

`fixtures/dev-score.sh home|visitor|undo|show` adds or removes a goal and drops the cached payload, so a score change shows up on the next poll rather than up to 30s later. Without it the fixture is static and a test proves only that the overlay renders, not that it updates. Development only — it writes straight to the database.

### Short URLs for a switcher

A switcher's on-screen keyboard makes a long URL genuinely expensive to type, so there is a two-character entry point:

```
http://<lan-ip>:8080/s/702          scoreboard, transparent
http://<lan-ip>:8080/s/702/green    ... on a chroma-key background
http://<lan-ip>:8080/s/702/overlay  the full-frame stage
http://<lan-ip>:8080/s/             the studio
http://<lan-ip>:8080/c/702          the commentator page
```

| File | Routes | Note |
|---|---|---|
| `live/overlays/.htaccess` | `/live/overlays/702`, `/live/overlays/702/green`, `/live/overlays/` | Ours, self-contained, survives Live! upgrades |
| host root `.htaccess` | `/s/…`, `/c/…` | **Outside this repo.** Ships as `install/root-htaccess-snippet.conf` for pasting; delete the block if it ever conflicts — it costs only URL length |

Both need `mod_rewrite` and `AllowOverride All`. Everything is an internal rewrite onto `?view=`, so nothing bypasses the guard that makes a direct request to `live/overlays/*.php` return 404.

**`?bg=`** exists because a switcher that cannot composite alpha needs a solid colour to key on instead: `green` (`#00B140`), `blue`, `magenta`, `black`, or any six-digit hex. The page is transparent by default.

### Team colours

A team does not have *one* colour. It brings a set of kits and which one it wears is decided at the coin toss, minutes before the pull — so the palette is prepared in advance and the per-game pick is made live, in one click on the game's row.

| Piece | |
|---|---|
| `shared/colors.php` | The store. Validates to `RRGGBB`, de-duplicates, caps a palette at 8, writes atomically so an overlay polling through a save never reads a half-written file |
| `conf/team-colors.json` | The data. Gitignored: runtime state, per installation, written by the web server |
| `colors.php` | Routed endpoint. `POST {"palettes": …}` edits prepared kits; `POST {"game": 702, "home": …}` is the coin-toss write, kept deliberately small and separate |

Precedence is **URL parameter → this game's kit → the team's first kit → pool colour**. Saving requires the Live! admin session (`SeasonAccess::isLiveAdminAuthenticated()`), so anyone who can view an overlay cannot rewrite its colours.

**Both sides or neither.** One real kit beside a placeholder reads as that team's colour, which is worse than showing neither, so the scoreboard uses kit colours only once both are set.

### The scoreboard: from card to broadcast bug

The layout as inherited was a **card** — symmetric, centre-weighted, 800×181 (about 4.4:1), with a shared centred score between the two teams. It is now a **bug**: a single strip, measured at **1446×96** plus a 28px context ribbon, anchored inside a 54/60px title-safe inset.

Area was never the problem — 800×181 is 6.98% of a 1920×1080 frame, inside the 5–10% broadcast norm. The *distribution* was. The rewrite trades height for width at roughly constant area.

```
[logo][seed] NAME (kit fill) [SCORE] │ CLOCK │ [SCORE] (kit fill) NAME [seed][logo]
                          ── context ribbon ──
```

Each score sits against its own team rather than in a shared pair, so name and number are one eye movement and there is no separator glyph to parse. Both scores stay inboard, flanking the clock, so the pair can still be read together.

What was learned building it, all verified by measurement rather than inspection:

- **Team names are one line, always.** The two-line wrap is most of what made the card tall. A condensed face carries the longest real names instead — but the font stack cannot be trusted: measured on macOS, `font-stretch: condensed` is **inert** (551px, identical to normal), and Roboto Condensed / Archivo Narrow / Oswald are all absent without shipping a webfont. Arial Narrow is the only broadly portable condensed face, and even it renders `Mosquitos Klosterneuburg` at 468px against a 420px box. So `fitName()` measures each name after layout and steps its size down to a 24px floor. **It must run after the board is visible**: a hidden element reports `scrollWidth` and `clientWidth` as 0, and every name silently "fits".
- **Kit colour fills the name block.** The old 8px bar sat at the outer edge — the least-read position on the strip — and was thin enough to need an inset highlight to survive a dark kit. As a fill it is read first, the highlight hack is gone, and the foreground is computed per block from the fill's luma.
- **Logo plates are chosen per logo, from the artwork's own pixels.** Ultimate logos are overwhelmingly monochrome marks on transparency, in both polarities — a navy crest and a white wordmark are equally common, sometimes in the same game. So each logo is drawn to a canvas, the mean luma of its non-transparent pixels measured, and it gets a light plate below ~140 and a dark one above. `?logoplate=light|dark|none` overrides. Cross-origin art taints the canvas; that is caught and the neutral default stands.
- **Logos must not shrink.** As flex items they were being squeezed by long names; `flex-shrink: 0` pins them. Note the fixture `logos/301.svg` was itself malformed — its text was ~265px wide inside a 220px viewBox, so it overflowed its own canvas and looked exactly like a CSS clipping bug. `object-fit: contain` was correct all along; the artwork was fixed.
- **Seed replaces the win-loss record.** A record of 1-0 on day one carries almost no information; a seed is meaningful all week and costs one glyph.
- **Colour must never be the only signal.** Measured with a deuteranope simulation: solid green against solid red is 1.50:1 in normal vision and **1.18:1** for a deuteranope — two indistinguishable blocks. Hold and break therefore separate by *lightness* as well as hue (light green block with dark text, dark red block with white text): 5.70:1 and 4.79:1. The words carry the meaning; colour only reinforces.

### Scoreboard feature reference

| feature | notes |
|---|---|
| **Game clock** | Derived, not read — UO has no countdown anywhere. Mirrors `GameClockState()` (`lib/game.functions.php:1042`): `elapsed = now − timer_start − timer_paused_duration`, minus any open pause. `timer_start` is unix **seconds**. Ticks locally every second so it does not wait on the 30s poll, with server skew corrected from `meta.generated_timestamp` |
| **Countdown** | When `poolinfo.timecap` is set (minutes), the clock counts down to it and clamps at 0:00; otherwise it counts up |
| **Cap states** | `half_cap` → amber, `time_cap` → red, matching Timekeeper's convention (`docs/timekeeper.md:99-101`), with the new point cap shown (`TIME CAP · TO 4`). Dropped once the game is not running: red behind "Final" reads as an alarm |
| **Timeouts** | Ticks under each name. Allowance from `poolinfo.timeouts`, taken counted from `gameevents`. `timeoutsper: "half"` resets the allowance at the half |
| **Timeout callout** | A tab over the team that called it, from the `timeout` events already in `gameevents`. The window is measured on the *game* clock, which handles both scorekeeping habits without knowing which is in use: with the clock paused for the timeout the age stays put and the tab lasts exactly as long as play is stopped, and with it running the tab drops off after `poolinfo.timeoutlen`. Outranks a break chance — during a timeout nobody has the disc, so claiming one would assert something that is not happening |
| **Hold / break callout** | A tab above the bar over the scoring team, sharing one `FLASH_MS` with the score flash so the two read as one event |
| **Clean hold** | Upgrades a hold when the possession log says the defence never touched the disc that point — and only on positive evidence, since an untracked point is unknown rather than clean |
| **Break chance** | Transient, from the operator- or commentator-declared possession log on the ~1s channel. Falls back to the standing **ON DEFENCE** tag when nobody is tracking — deliberately not called "break chance", because that is an event claim requiring possession UO does not record |
| **Turnover count** | On the context ribbon, from the possession log on the ~1s channel, and only from two upwards — one change of hands is noise and zero is where every point starts. 12-11 looks identical whether the last point took one throw or fifteen; this is the only thing on the bug that says which |
| **`?field=`** | Follows whatever is live on a field rather than a fixed game, so a switcher set up in the morning survives every round change. `STUDIO.md` §9.0 |
| **`?size=compact`** | A smaller bug for replays and busy shots. Drops standing context — seed, timeouts, ribbon, the ON DEFENCE tag — but keeps outcome callouts: a break is the most valuable thing the bug ever says and worthless a few seconds later, and this is the variant used for exactly the shots where one is most likely |
| **`?demo=1`** | Walks every display state from one real fetched payload — scheduled, live, hold, break, timeout, cap, paused, final. The only way to see a running clock locally, and the sharpest switcher test: no polling or server cache in the path |
| **`?at=`** | Draws one deterministic frame for post-production. See `POSTPRODUCTION.md` |
| **`tests/selftest`** | Four independently moving panels (JS timer, rAF, pure CSS, network) so a switcher that fails to update can be diagnosed by *which* layer is frozen |
| **Asset cache-busting** | `?v=<filemtime>` on CSS and JS, `Cache-Control: no-store` on the page. Neither asset sent any cache header, so browsers cached them heuristically and a stale stylesheet survived a rewrite — harmless on a laptop, serious on a switcher mid-broadcast |
| **`?reload=<seconds>`** | Meta-refresh fallback for a browser source that rasterises once and never runs timers. Off by default; a reload flashes the overlay |

---

## 5. What is left

### Before a first broadcast

1. **Run `tests/selftest.php` on the actual hardware** — OBS, the Director Mini, a Yolobox. This is the one open item that could invalidate the approach rather than merely limit it, and the failure it detects is not "an overlay looks wrong" but "an overlay never updates". Everything else here assumes it passes.
2. **Decide what to do about diagnostics on the broadcast canvas.** A wrong game id, or five consecutive failed polls, currently replaces the scoreboard with white error text over the live picture. Useful on a laptop during setup, unacceptable once the source is live, and the page cannot tell the two apart. Three options in `STUDIO.md` §11.

### Built but not finished

3. **The post-production command-line tool.** The deterministic render mode exists and is tested; the tool that walks a recording and burns frames does not. `POSTPRODUCTION.md` has the design.
4. **A lower-third variant.** `?size=compact` covers part of it.
5. **Fixture depth.** `HRN2026` is two teams and one pool — enough to prove the pipeline, not enough to exercise progression, standings or spirit. Anything bracket-shaped needs either a larger hand-built fixture or a sanitised dump from a real tournament.

### Wanted from upstream

These are asks against UltiOrganizer, not work in this repo. `STUDIO.md` §10 and `COMMENTATOR.md` §5, §6c and §8a carry the full cases.

6. **Possession capture in the Scorekeeper.** Everything possession-derived here — break chance, clean holds, turnover counts — rests on somebody declaring it by hand during the broadcast. That works, and it is the wrong permanent home: it is unauthoritative, exists only for games being broadcast, and dies with the tab. **Possession data should land in UO, not in an overlay-side export** — which is why the log is deliberately not exportable (`STUDIO.md` §10.5). An export would make the wrong home permanent.
7. **Per-game block counts through the API.** They exist in `uo_defense` and via `GameTeamDefenseBoard()`, but `GameManager` never exposes them, so a card cannot reach them at all. `entity=teams` carries only tournament totals, behind `ShowDefenseStats`.
8. **Player photos**, which Live! has none of — `TEAM_PHOTOS_ENABLED` covers team photos only.
9. **The first point's gender ratio, per mixed game.** It is circled on the paper scoresheet and never captured, so the ABBA pattern is derivable but its labels are not — and scoring splits cannot be aggregated across games, because "ratio A" means different things in different games. One column would make every mixed game ever played analysable by ratio. `COMMENTATOR.md` §6c.
10. **Pronunciation hints and pronouns**, for the commentary position. Both are personal data the moment they exist, so they need a registration-side home with the consent handling that implies.
11. **Structured commentary fields on the player profile** — short self-declared lines: other sports played, how they came to ultimate, occupation, interests, clubs played for before. `uo_player_profile` already holds `story` and `achievements`, but free text is the wrong shape for someone with four seconds between points. Same home, edit surface and `public` opt-in as item 10. `COMMENTATOR.md` §5.

    **A commentator-authored version is built** (`COMMENTATOR.md` §5a): a free-text note per player, typed at the desk and shared by the room code, because at a smaller tournament the material arrives as whatever the teams hand over an hour before the pull. It does not satisfy this ask and is not meant to. It is a commentary desk's notebook — third-party authored, unverifiable, expiring after 14 days — where this asks for something the player wrote about themselves that follows them between tournaments. If anything, having the local version makes the upstream case easier to argue: it will show whether desks maintain this data at all.
12. **Identifiers for people and clubs that survive leaving one installation**, so a career, a club history and a head-to-head record can span instances instead of ending at the edge of whichever database hosted the event. UltiOrganizer already separates the per-event row from the persistent entity in both directions — `uo_player.profile_id` → `uo_player_profile`, `uo_team.club` → `uo_club` — so this is that abstraction one level up rather than a new concept. The hard part is identity, not transport: a wrong merge attributes one player's career to another and links two people's records, so the identifier must be issued rather than derived, and a local `public` opt-in must never be read as a global one. Much larger than anything else on this list, and a UO-wide change to identity, privacy and the API before it is an overlay feature. `COMMENTATOR.md` §8a.

---

## 6. Constraints

- **All new code stays inside `live/overlays/`.** No edits to files that ship in the Live! zip. The one exception is the host's root `.htaccess`, which is optional and ships as a snippet to paste.
- **No database schema changes.**
- **No direct DB access from an overlay page** — everything goes through the routed API.

  *Under review, for statistics only.* `STUDIO.md` §3.4 identifies the one case that would justify revisiting this: per-player block counts are unreachable through the API. Reading them from an overlay-side routed endpoint would not breach the upgrade rule, but it would relax this constraint. Decide it once, deliberately.
- **Do not hard-code five spirit categories** anywhere.
- **Do not recompute progression** — `pool_placements` is server-side. Holds and breaks *must* be recomputed, and must carry an unresolved bucket.
- **Never assert on air what the data cannot support.** A number without its denominator, a tab that outlives its point, a column of zeros presented as a ranking — each is worse than showing nothing.
- Target: OBS/CEF and a hardware switcher's browser source. Fixed 1920×1080, transparent background, no scrollbars, GPU-friendly CSS transforms only.

## 7. Open questions

1. **Does the Director Mini's browser source support transparency?** OBS does, and the overlay renders correctly transparent in Chrome. If the Mini does not, `?bg=` is already the fallback.
2. **Multi-field tournaments:** one overlay URL per field, or one URL that follows "whichever game is live on field N"? The latter needs a resolver against `entity=games`; the studio already has the sorting logic it would reuse.
3. **Does `pool_placements` cover bracket play**, or only pools? The field name says pools; the release notes say "pools and brackets". `HRN2026` has no bracket, so this cannot be settled without richer test data — it gates any bracket card.
4. **Does `entity=statistics` carry anything useful without an id?** It returns 400 unexplored.

---

## 8. Where the facts came from

Nothing here is from release notes alone — each claim was checked against source. To re-verify after an upgrade:

- **Live! v3 API contract:** `docs/api-v3-changes.md`, which ships **only inside the release zip** — it is not in the GitHub repo tree, so it cannot be fetched from a raw URL. `gh release download v3.0.6 --repo layoutd/live-by-bula --pattern "*.zip"`.
- **The two zips:** `live-by-bula-3.0.6.zip` (the `live/` directory alone) and `uo-with-live-3.0.6.zip` (the tested UO + Live! pairing).
- **UO 4 schema:** `sql/ultiorganizer.sql` at `origin/master`.
- **UO 4 DB helpers:** `lib/database.php` (`DBQuery`, `DBFetchAssoc`, `DBQueryToRow`, `DBQueryToArray`; there is no `DBFetchRow`).
- **View routing:** `resolveViewPath()` at `lib/common.functions.php:1426`.
- **Live! entity list:** `live/api/EntityRouter.php`.
- **Holds/breaks location:** `grep -rn isbreakpoint` across `live/api/*.php` and the bundled UO (both empty) against `live/assets/SingleGameWrapper-*.js` (present).
- **Cache lifetimes:** `live/api/GameManager.php:14-19` and `:358-361`.
- **Payload field names:** fetched payloads, not documentation. `curl '…?view=live/api&entity=games&id=702' | python3 -m json.tool`.
