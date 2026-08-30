# Video overlays — documentation index

## What this is

A broadcast graphics layer for Ultimate tournaments, extending **[Live! by BULA](https://github.com/layoutd/live-by-bula)** 3.0.6 (which itself runs on UltiOrganizer 4.0). It turns the tournament data an event is already keeping — the score, the clock, rosters, goals and assists — into graphics a video switcher can put on air, and gives the people running the broadcast somewhere to control them from.

Three surfaces, for three different people:

| surface | who it is for | what it is |
|---|---|---|
| **Scoreboard** | the switcher | A broadcast *bug* on a transparent 1920×1080 canvas: score, clock, timeouts, hold/break. One URL, points a browser source at it, done. |
| **Studio** | the operator | A full-frame stage hosting several cards at once, plus the control page that decides what is on it. One URL for the whole broadcast, changed live from a laptop. |
| **Commentator** | the people talking | A second screen, never on air: rosters, stats, who is on the field. Nothing here reaches a viewer, which is why it can show numbers a graphic must refuse. |

Everything reads through Live!'s public JSON API. **No overlay touches the database**, which is what makes the whole directory a drop-in that survives a Live! upgrade.

### Why it lives in `live/overlays/`

`live/bin/update-from-github.sh` unzips a Live! release **over** the existing tree without deleting files it does not ship. So a self-contained directory alongside Live!'s own code survives every upgrade, and no file Live! ships is ever edited. That constraint is absolute — see `PLAN.md`.

---

## Getting started

### Requirements

- Live! by BULA 3.0.6 installed and a published season — which brings UltiOrganizer 4.0 with it. Live!'s own [Terms of Use](https://github.com/layoutd/live-by-bula/blob/main/Terms%20of%20Use%20-%20Live%20by%20BULA.pdf) must be signed and returned to live@beachultimate.org before use.
- PHP 8.3+, MariaDB 10.11+ (Live!'s own requirements).
- `mod_rewrite` and `AllowOverride All` for the short URLs. The dev image (`docs/dev/Dockerfile.app`) has both. Without them everything still works through the long `?view=live/overlays/...` form.

### Install

There is no build step and nothing to compile. The directory is the installation.

1. **Make `conf/` writable by the web server.** It holds operator-authored state — what is on air, kit colours, shared line selections, and the commentary desk's prepared notes about players — and is gitignored because it is per-installation runtime data, not code. It must stay unreadable over HTTP as well as writable: the `.htaccess` here serves two files by name and 404s the rest, which is what keeps the notes out of a browser.

   ```
   mkdir -p live/overlays/conf
   chown <web-server-user> live/overlays/conf
   ```

   Every page degrades to read-only rather than erroring when it is not writable, and says so.

2. **Optionally add the `/s/` and `/c/` short URLs.** Paste [`install/root-htaccess-snippet.conf`](../install/root-htaccess-snippet.conf) at the end of the host's root `.htaccess`. This is the only file the project cannot install itself, because it belongs to the UltiOrganizer root rather than to `live/overlays/` — and `.htaccess` has no import mechanism, so it has to be pasted rather than included (Apache rejects `IncludeOptional` in `.htaccess` outright and 500s every request under it).

   Skip it if you like. `live/overlays/.htaccess` ships with the project and already serves `/live/overlays/702`, `/live/overlays/702/overlay` and the rest, and `?view=live/overlays/...` always works. The short forms exist because the thing typing them is often a switcher's on-screen keyboard.

3. **Check a URL resolves.** Open `/s/` (or `/live/overlays/` without the snippet). If it 404s, the rewrite rules are not being read — check `mod_rewrite` and `AllowOverride All`, and use `?view=live/overlays/index` meanwhile.

4. **Log in for control.** Anything that changes what is on air needs the Live! admin session from `?view=live/admin`. Read-only viewing never does — a camera operator at a field should not need a password to find a URL, least of all in auto mode where there is no operator at all.

5. **Point the switcher at one URL** and leave it there. In auto mode, with no `conf/show.json` at all, the stage runs the scoreboard and nothing else.

### URLs

Replace `702` with the game id. The Studio shows the URL for the game you have selected, so these are for reference rather than for typing.

| URL | what it is |
|---|---|
| `/s/` | **Studio** — game list, control, colours, logo. Public read-only; controls appear when logged in. |
| `/s/702/overlay` | **Stage** — the full-frame graphics layer. This is the browser source. |
| `/s/702` | **Scoreboard alone**, if you want just the bug with no stage. |
| `/s/702/green` | Scoreboard on a chroma-key background. Also `blue`, `magenta`, `black`, or any 6-digit hex. |
| `/s/702/overlay/green` | The same, for the stage. |
| `/c` | **Commentator** landing — pick a game. |
| `/c/702` | Commentator page for that game. |
| `?view=live/overlays/tests/selftest` | Switcher self-test (below). |

Transparent by default. Use `?bg=` / the `green` form only if the switcher cannot key alpha.

### First checks on real hardware

Do these before a broadcast, in this order — they cost minutes and each rules out a whole class of failure:

1. **`?view=live/overlays/tests/selftest`** on the switcher, watching the **program output**, not a laptop. Four panels move independently (JS timer, requestAnimationFrame, pure CSS, network poll). Whichever are frozen tell you which layer the device is not running. "The overlay does not update" has at least five distinct causes and this separates them.
2. **`/s/702?demo=1`** — walks every display state from one real payload: scheduled, live, hold, break, timeout, cap, paused, final. The only way to see a running clock without a live game, and the sharpest test there is, since no polling or server cache sits in the path.
3. **Confirm the scorekeeper starts the clock.** `timer_start` is only ever written by Scorekeeper. If nobody starts it there is no clock on air and the overlay quietly falls back to a status word. Worth a line on the pre-game checklist.

---

## The documents

Read them in this order if you are new; each assumes the one before it.

### [`PLAN.md`](PLAN.md) — the platform and the scoreboard

The foundation. What Live! v3's API gives an overlay and what it withholds, how a request actually flows, which field names the payloads really use, the polling and caching model, the scoreboard bug itself, and what is still left to build — clock derivation, cap states, timeouts, hold/break classification.

**Go here for:** how the data reaches an overlay, what a field is called, why a poll interval is what it is, the directory layout, and the switcher-compatibility findings.

### [`STUDIO.md`](STUDIO.md) — the stage and the operator

The step from *one overlay per URL* to *one stage per broadcast with an operator deciding what is on it*. Slots, cards, show state and its optimistic locking, the arm-then-show lifecycle, and the control page.

Its long middle section — **"What the data actually supports"** — is the most reusable part of the whole set: every statistic checked against real payloads and the UO schema, with a verdict. It is what stops a card being designed around data that does not exist. Turnovers do not exist anywhere in the schema; blocks do, behind a setting that ships off; break chances cannot be derived at all.

**Go here for:** whether a card can be built, what a slot may contain, why a control is not disabled, and the full cases behind the upstream asks.

### [`POSTPRODUCTION.md`](POSTPRODUCTION.md) — adding an overlay after the fact

Most games are recorded by somebody with a camera and no switcher. This is how that footage gets the same scoreboard afterwards: the alignment problem between a video's timeline and a game's, what UltiOrganizer can and cannot tell you about when a goal happened, and why a fit that is more than twenty seconds out should refuse to render rather than produce something subtly wrong.

**Go here for:** the anchor model, the residual thresholds, and the parts that are designed but not yet built.

### [`COMMENTATOR.md`](COMMENTATOR.md) — the second screen

The information surface for the people calling the game. Prep mode (rosters, team stats, player detail) and play-by-play mode (line selection, who is on the field), plus line sharing between two commentators.

It opens with the observation that governs the whole document: **the incompleteness that blocks a graphic does not block a commentator.** A block count nobody can vouch for is unusable as a lower third and perfectly usable to someone who can say "at least four". That asymmetry is why this surface can show things the Studio cannot.

**Go here for:** what a commentator needs, how line sharing works and why its write is unauthenticated, and the pronunciation and pronoun questions.

### [`UPSTREAM.md`](UPSTREAM.md) — what is wanted from upstream

The digest of asks against UltiOrganizer and Live! by BULA — one entry per ask with what it unlocks, roughly what it costs, and a link to its full case in the documents above. The page to hand to an upstream maintainer, and the text to paste from when opening an issue.

**Go here for:** the upstream wishlist in one place.

### [`STANDALONE.md`](STANDALONE.md) — the overlays without UltiOrganizer

A concept, with nothing built. Everything here assumes an UltiOrganizer installation with Live! underneath, and that assumption is load-bearing in the deployment sense and almost nowhere else: the coupling is **two PHP classes in three files, four read endpoints, and a clock that is three integers**. A tournament scored on paper, a club friendly, a showcase game outside the event that owns the software — the overlays would work in all of them, and the only thing missing is something to answer "what is game 702".

The document is mostly about where the seam goes, what a person would have to type, and what is honestly lost — which is every number Live! accumulates across an event, and which the existing "absent is not zero" rule already handles correctly by omission.

**Go here for:** whether this could run without the host, and what it would cost.

### [`SETUP.md`](SETUP.md) — setup, checks and teardown

A concept, with nothing built. These docs ask for a pre-game checklist in **five separate places** — including the same clock item written twice in this file — and no checklist exists anywhere.

The design question it answers is narrow: a fixed list is wrong because every rig differs, and a blank one is a text file. What the software can add is that **it can check some of the items itself** — ten of them are already knowable from state this project reads, including the clock nobody started and the commentator who typed the code wrong. The rest are per-rig and configurable, which is where multiple cameras and different switchers live.

It also covers a **"remind me" mode** during the broadcast, which needs more care than it looks: the case for it is the strongest in the project — the characteristic failure is a graphic quietly asserting something untrue, and those look normal — but a timer that fires during a point is worse than what it guards against. The resolution is to fire at breaks the system already knows about (a score, a timeout, halftime) rather than on a clock, and to detect rather than nag wherever the thing is detectable. Reaching the operator is its own problem, since they are not looking at the page — and **the obvious answer, a beep, is usually wrong**: it leaks into the commentary mic, and firing at breaks puts it exactly when the mic is hot. The ladder runs silent-first, and the honest limit is that a crew watching a hardware multiview cannot be reached by a browser at all.

And it does not stop at the first pull. **Teardown carries the two most expensive mistakes of the day**: an overlay left on air over a finished game — the same "quietly asserting something untrue" failure, and checkable — and the post-production anchors. `POSTPRODUCTION.md` needs at least one *"goal n is at this position in the video"* or footage cannot be aligned at all, and **how many you need depends on how the game was scored** — one for a live Scorekeeper, one per goal for a sheet with clustered times. The software can work out which case you are in; the crew can only act on it before they leave.

**Go here for:** why this is a readiness display rather than a notes app, and the scoping rule that keeps it one.

### [`MATCHCONTROL.md`](MATCHCONTROL.md) — score and clock, and who keeps them

A concept, with nothing built, and not standalone-specific. A broadcast crew is one, two, three or four people depending on the day, and the wrong way to allocate the score button is to pick a crew size and design for it. `STUDIO.md` §3.5 already settled the axis — *does this compete with the capturer's main job, or is it their main job* — and for score the answer is whoever is already watching the game, which at two people is the commentator rather than the operator.

The load-bearing conclusion is technical rather than organisational: **a goal must be written as the point it creates, not as `+1`.** A delta entered twice is a real 2–0 from one point; a statement of the result is safe by construction, and that is what lets several surfaces hold the button without anybody having to own it.

The decision for now is the simple one: **a single phone-optimised page, nothing embedded** — with keyboard shortcuts in the commentator page as the likely later step rather than a panel, since that page is already keyboard-driven and its play view cannot afford the rows.

It also records a gap found while writing it: **timeouts are shown on air and on neither desk.** The scoreboard derives them correctly; `commentator.php` and `index.php` do not mention the concept.

**Go here for:** who presses what, on which device, at each crew size — and why hosted mode wants the same surface with the score read-only and the clock as a fallback.

---

## Development

### Fixtures

[`../fixtures/dev-fixture.sql`](../fixtures/dev-fixture.sql) creates a tournament to develop against: two 28-player squads, a completed game (700) and an ongoing one (702) at 8–6 with a halftime cap, plus recorded blocks — and an ongoing mixed semi-final (703) with fourteen-a-side squads, for the gender-ratio features and the line picker. Idempotent — safe to re-run.

```
mariadb -uroot -p<root-password> ultiorganizer < live/overlays/fixtures/dev-fixture.sql
```

[`../fixtures/dev-score.sh`](../fixtures/dev-score.sh) adds or removes a goal on the ongoing game so you can watch an overlay update while it is on air:

```
live/overlays/fixtures/dev-score.sh home      # or visitor / undo / show
```

It writes straight to the database, bypassing Scorekeeper. Development only.

### Settings that change what the overlays can show

Nothing here is an overlay setting — every one of them belongs to UltiOrganizer, Live! or the pool format, and the overlays simply render whatever is available. Collected in one place because an overlay that "does not show timeouts" is almost always a pool that has none configured rather than a bug.

| setting | where | ships as | what it changes |
|---|---|---|---|
| **Published season** | Live! admin, `LIVE_SEASON_ID` | — | The precondition for everything. An unpublished season means the API returns nothing and every surface is empty |
| `ShowDefenseStats` | `uo_setting`, UO admin | `false` (`sql/ultiorganizer.sql:975`) | On, every roster row gains `deftotal` and the commentator page grows a **Blk** column and a Blocks sort. Off, the field is absent and the column does not exist. Note the `totalavg` trap in `STUDIO.md` §3.1 |
| `TV_SCREEN_LOGO_PATH` | `live/conf/local-config.json` | a sample path | The tournament logo. Its corner is chosen in the Studio; the stage keeps it clear of whatever else is in that corner |
| `TEAM_PHOTOS_ENABLED` | `live/conf/local-config.json` | `false` | On, Live! exposes team photos and the scoreboard will fall back to one when `logos/<team_id>.*` is absent. Those are usually wide squad photos rather than a square mark, so a real logo file beats it |
| `UO_URL_PREFIX` | UO config | `/` | Every route and asset URL is built from this. Set it when UltiOrganizer is installed under a subpath, or the overlays will request their CSS from the wrong place |
| **Pool: time cap** | `uo_pool.timecap`, pool settings | unset | Set, the game clock counts **down** to it and the cap states appear. Unset, the clock counts up and no cap is ever shown |
| **Pool: timeouts** | `uo_pool.timeouts`, `timeoutsper` | unset | The allowance drawn as ticks under each team name. `timeoutsper: "half"` resets the allowance at half time, so only timeouts after the `half_cap` event count in the second half |
| `CACHE_MINUTES_MODULATOR` | Live! admin | `1.0` | How long game data is cached. **Upstream documents this as "do not lower below 1.0"** — lowering it to chase score latency is not the supported route. See `PLAN.md` §6 for what is |

**Two preconditions that are not settings at all**, and between them account for most of "the overlay is not working":

- **A scorekeeper has to start the clock.** `timer_start` is only ever written by UltiOrganizer's Scorekeeper. If nobody starts it there is no clock on air and the overlay quietly falls back to a status word. Worth a line on the pre-game checklist.
- **Games must be marked ongoing.** Live and final are different states, and almost everything transient — the clock, cap states, hold and break tabs, break chance — is deliberately suppressed once a game is not running.

### Tests

```
npm install
npm test                      # the suite, against a running instance
ADMIN_PASS=... npm test       # including the tests that change what is on air
npm run test:unit             # the pure logic alone: no browser, no instance
npm run test:standalone       # routing and guards on php -S, no host at all
node tests/modules.mjs        # every shared module loads under CommonJS
npm run shots                 # regenerate the images in docs/images/
```

Playwright, against a running dev instance rather than a mock — the payload shape is exactly the thing that has been wrong before, so a mock would agree with itself and nothing else. It uses the system Chrome, so there is no browser download. The config lives in `tests/`, which is not where Playwright looks by default, so `npm test` passes `--config`.

The assertions are about **geometry, contrast and state transitions** rather than markup, because that is how this code has actually failed. On a 1920×1080 canvas viewed on a laptop, eyeballing is not evidence.

Tests needing the Live! admin session skip themselves without `ADMIN_PASS`, and anything that writes show state restores it afterwards — the suite borrows the same files a real broadcast reads.

### What continuous integration can and cannot prove

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) runs on every push and pull request: PHP syntax on 8.3 and 8.4, the shared modules' CommonJS load, `npm run test:unit`, `npm run test:standalone`, and a check that every relative link in these docs resolves.

The standalone job is the first one here that makes real HTTP requests, and it can only exist because standalone mode needs no UltiOrganizer, no Live!, no database and no Apache. What it guards is worth the job on its own: that `conf/` — which holds the commentary desk's notes about named people — is not served over HTTP. `php -S` does not read `.htaccess`, so those rules exist a second time inside `app.php`, and only a request can prove they work.

**It cannot run the browser suite, and that is a consequence of a deliberate choice rather than a gap.** The suite drives a real Live! instance because the payload shape is exactly the thing that has been wrong before; Live! by BULA is third-party software distributed under a signed Terms of Use, so a public workflow cannot check it out — which is also why `live/` is gitignored in UltiOrganizer. A green tick therefore means the pure logic and the syntax are sound, not that the overlays work. `npm test` against a real instance remains the gate, and it is yours rather than the robot's.

Adding the browser suite later means making Live! reachable from the runner: a private mirror, a deploy key or token as a secret, PHP and MySQL services, `fixtures/dev-fixture.sql` loaded, and `ADMIN_PASS` set. Secrets are not exposed to workflows from forked pull requests, so such a job would only ever run on pushes and same-repository branches.

[`../tests/selftest.php`](../tests/selftest.php) is a different thing: the switcher diagnostic described above, a routed page rather than part of the suite, because it has to be loadable by the device it is diagnosing.

**Every run starts from a configured stage, not an empty one.** `tests/global-setup.js` seeds a logo in a corner, cards placed and on air, both fixture games tracked with a code, a gender ratio and a live stoppage — what a stage looks like ten minutes into a tournament.

That is not decoration. These tests write to the files a real broadcast reads, so "empty" is not a state that occurs in practice, and a test that reads state it did not set passes or fails on history rather than on the code. Eight did over this project's life, each found only when something unrelated moved. Seeding makes the ninth fail immediately. `resetPossession()` is the helper for the commonest case: turning the mode on does not clear a log, so a test asserting on an event count must empty it first.

UltiOrganizer's own harness (`ktolonen/ultiorganizer-tests`) covers UltiOrganizer, not this directory.

### Two rules that keep biting

- **Never edit a file Live! ships.** The upgrade script unzips over the tree; a local patch disappears without warning at the worst possible moment.
- **Never read the database from an overlay.** Everything goes through `live/api`. It is what makes the directory portable, and the one place `STUDIO.md` contemplates relaxing it is marked as an open decision, not a precedent.
