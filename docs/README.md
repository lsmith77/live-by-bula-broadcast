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

1. **Make `conf/` writable by the web server.** It holds operator-authored state — what is on air, kit colours, shared line selections — and is gitignored because it is per-installation runtime data, not code:

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

The foundation. What the rebase onto UO 4.0 + Live! 3.0.6 changed, how a request actually flows, which field names the payloads really use, the polling and caching model, and the scoreboard bug itself — clock derivation, cap states, timeouts, hold/break classification.

**Go here for:** how the data reaches an overlay, what a field is called, why a poll interval is what it is, the directory layout, and the switcher-compatibility findings.

### [`STUDIO.md`](STUDIO.md) — the stage and the operator

The step from *one overlay per URL* to *one stage per broadcast with an operator deciding what is on it*. Slots, cards, show state and its optimistic locking, the arm-then-show lifecycle, and the control page.

Its long middle section — **"What the data actually supports"** — is the most reusable part of the whole set: every statistic checked against real payloads and the UO schema, with a verdict. It is what stops a card being designed around data that does not exist. Turnovers do not exist anywhere in the schema; blocks do, behind a setting that ships off; break chances cannot be derived at all.

**Go here for:** whether a card can be built, what a slot may contain, why a control is not disabled, and the list of upstream asks.

### [`COMMENTATOR.md`](COMMENTATOR.md) — the second screen

The information surface for the people calling the game. Prep mode (rosters, team stats, player detail) and play-by-play mode (line selection, who is on the field), plus line sharing between two commentators.

It opens with the observation that governs the whole document: **the incompleteness that blocks a graphic does not block a commentator.** A block count nobody can vouch for is unusable as a lower third and perfectly usable to someone who can say "at least four". That asymmetry is why this surface can show things the Studio cannot.

**Go here for:** what a commentator needs, how line sharing works and why its write is unauthenticated, and the pronunciation and pronoun questions.

---

## Development

### Fixtures

[`../fixtures/dev-fixture.sql`](../fixtures/dev-fixture.sql) creates a tournament to develop against: two 28-player squads, a completed game (700) and an ongoing one (702) at 8–6 with a halftime cap, plus recorded blocks. Idempotent — safe to re-run.

```
mariadb -uroot -p<root-password> ultiorganizer < live/overlays/fixtures/dev-fixture.sql
```

[`../fixtures/dev-score.sh`](../fixtures/dev-score.sh) adds or removes a goal on the ongoing game so you can watch an overlay update while it is on air:

```
live/overlays/fixtures/dev-score.sh home      # or visitor / undo / show
```

It writes straight to the database, bypassing Scorekeeper. Development only.

### Optional settings worth knowing

| setting | effect |
|---|---|
| `ShowDefenseStats` | System setting, **ships off**. On, every roster row gains `deftotal` and blocks appear in the commentator page. Off, the column does not exist — see `STUDIO.md` §3.1, and note the `totalavg` trap there. |
| `TV_SCREEN_LOGO_PATH` | The tournament logo. Its corner is chosen in the Studio; the stage keeps it clear of whatever else is in that corner. |

### Tests

[`../tests/selftest.php`](../tests/selftest.php) is the switcher diagnostic described above — a routed page, because it has to be loadable by the device it is diagnosing.

[`../tests/describe-scoreboard.py`](../tests/describe-scoreboard.py) measures rendered screenshots, for checking a layout against what it is supposed to be rather than against how it looks.

There is no automated suite for the overlays. The repository's own harness (`ktolonen/ultiorganizer-tests`) covers UltiOrganizer, not this directory. Verification here has been by measurement — `getBoundingClientRect` through headless Chrome, and payloads compared before and after a change — which is worth continuing, because on a 1920×1080 canvas viewed on a laptop, eyeballing is not evidence.

### Two rules that keep biting

- **Never edit a file Live! ships.** The upgrade script unzips over the tree; a local patch disappears without warning at the worst possible moment.
- **Never read the database from an overlay.** Everything goes through `live/api`. It is what makes the directory portable, and the one place `STUDIO.md` contemplates relaxing it is marked as an open decision, not a precedent.
