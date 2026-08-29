# AGENTS.md

Guidance for coding agents working on the broadcast overlays. Keep this file short; the reasoning lives in `docs/`.

## Project overview

Broadcast graphics for Ultimate tournaments, extending Live! by BULA (which runs on UltiOrganizer 4). Three surfaces: the **scoreboard** bug, the **studio** (a full-frame stage plus the page that controls it), and the **commentator** second screen. See `docs/README.md`.

Installed by dropping this directory into `live/overlays/` of a Live! install. It is its own repository; the host is not.

## Three hard constraints

These are not preferences. Breaking any of them breaks the project's reason to exist.

1. **Never edit a file that Live! ships.** `live/bin/update-from-github.sh` unzips a release over the tree without deleting extra files. A local patch to Live!'s own code disappears at the worst possible moment — mid-tournament, on upgrade, silently. Everything we write lives under `live/overlays/`.
2. **No overlay reads the database.** Everything goes through `live/api`. This is what makes the directory portable and what keeps us off Live!'s internals. `docs/STUDIO.md` §3.4 contemplates one exception for per-game block data; it is marked open, and it is not a precedent.
3. **The only file outside this directory is the host's root `.htaccess`**, and only for the optional `/s/` and `/c/` short URLs. It ships as `install/root-htaccess-snippet.conf` for pasting — `.htaccess` has no import mechanism, so `IncludeOptional` is not an option (Apache errors and 500s every request under that directory).

## Layout

Runtime code sits at the top level, because a routed view's path *is* its URL — moving `scoreboard.php` into a subdirectory changes `?view=live/overlays/scoreboard`.

| path | what |
|---|---|
| `scoreboard.php` `stage.php` `commentator.php` `index.php` | routed pages |
| `show.php` `colors.php` `lines.php` `notes.php` | routed JSON endpoints |
| `shared/` | CSS, the PHP stores behind those endpoints, and the JS modules every surface shares: `possession.js` `stoppage.js` `field.js` `ratio.js` `tracking.js` `secret.js` `csv.js` `bios.js` |
| `conf/` | operator state written by the web server — **gitignored** |
| `logos/` | per-installation team logos — **contents gitignored** |
| `docs/` `tests/` `fixtures/` `install/` | not served to viewers |

`tests/selftest.php` is still a routed page (`?view=live/overlays/tests/selftest`) because it must be loadable by the switcher it diagnoses.

## Architecture rules

- **Routing:** every page checks `UO_ROUTED_VIEW` and 404s otherwise, so a direct request to a `.php` file returns nothing.
- **Two polls, deliberately different.** Show state is a *static* `conf/show.json` at ~1s, because an operator's click must feel instant. Game data follows `meta.expires_timestamp` (30s for a live game). Do not route show state through PHP and do not poll the API faster — the cache life is the server telling you when it will have news.
- **One renderer, never two.** The scoreboard's `?at=<seconds>&goals=<n>&phase=…` draws a single deterministic frame for post-production; it works by handing the existing `render()` a truncated payload, not by reimplementing anything. Alignment between a video and a game lives outside the overlay entirely — some tournaments record no goal times at all, so the caller decides which goals have happened and the overlay just draws it.
- **Arm, then show.** A card fetches, loads and decodes everything it needs *before* it is revealed. `img.decode()`, not `onload`. A card that appears while an asset is still loading pops in half-drawn, on air.
- **Placement is non-exclusive; visibility is exclusive per slot.** Several cards may be *placed* in one position, armed and waiting; at most one may be *on air*. Switching one on takes the slot from the other.
- **The store is the authority, not the UI.** `shared/show.php` drops unknown cards, cards in slots they do not fit, second-visible-in-a-slot, and anything under a fullscreen takeover. Never enforce a rule only in `index.php`.
- **State that belongs to a game is stored per game.** A tournament runs several fields at once, so a single shared document lets one field silently destroy another's data and put it on the wrong scoreboard — both demonstrated before the possession store was keyed by game. Prefer a shape where the mistake cannot be written over a guard that every future reader has to remember.
- **`LOCK_EX` on a temp file locks nothing.** The stores write via temp-file-and-rename, which gives *readers* atomicity — never half a document — and no mutual exclusion whatever, because each writer holds the lock on its own private temp file. A read-modify-write needs `flock` on a shared lock file across the whole cycle. `show.php`, `colors.php`, `possession.php` and `notes.php` have one; **`lines.php` does not**, and its `saveTeam()` is a read-modify-write — two commentators saving lines for different teams in the same moment can still lose one of them. Known gap, not a design decision.
- **Writes use optimistic locking.** Send the `rev` you read; the store rejects a stale one with 409 and returns current state to reapply.
- **Never block a click to prevent a consequence — show the consequence first.** Displacing a card is usually intended. Warn on hover, on the card that would lose; do not disable.

## What the data actually supports

Checked against real payloads, not assumed. `docs/STUDIO.md` §3 is the full account; these are the traps.

- **Turnovers do not exist.** No table, anywhere. A throwaway or a drop leaves no trace. Anything needing possession is impossible, not merely hard.
- **Blocks arrive as `deftotal`** on the `entity=teams` roster row, and only when the `ShowDefenseStats` system setting is on — it ships off. Completed games only. There is no per-game block list in any payload.
- **Absent is not zero.** A missing field means "this installation does not track this"; a present `0` means "none yet". Test for presence, not truthiness. A column of zeros pretending to be a ranking is worse than no column.
- **`TeamScoreBoardWithDefenses()` does not return `totalavg`.** Anything reading it silently becomes zero the moment an admin enables defence stats. Derive from `total` and `games`.
- **Holds and breaks are the same fact twice** — they sum to total points. Lead with breaks.

## Code style

- **PHP:** match the surrounding code. There is no formatter configured in this repo; the host project uses PER-CS 2.0 and following it keeps the two consistent. `php -l` every file you touch.
- **Client JS: the baseline is Live!'s baseline, which is Chrome 80+.** Live!'s own frontend is a Vite bundle loaded as `<script type="module">` and uses nullish coalescing (`??`, 125 occurrences) and optional chaining (`?.`) in the entry chunk every page pulls in — including its `TVScreenPage` route, the vendor's own analogue of these overlays. A browser that cannot run that shows nothing at all for Live!, so any device on which Live! works can run modern JavaScript.
  - The page `<script>` blocks here are written ES5-style — `var` and `function`, no arrow functions or template literals. That is **existing convention, not a compatibility requirement**: match it when editing those files so a diff reads consistently, but do not treat it as a constraint, and do not rewrite `shared/overlay-client.js` (modern, `async`/`await`, `?.`) to match. It is no more demanding than Live! already is.
  - **What is genuinely unverified is the hardware, not the syntax.** OBS moved off CEF 75 at 27.2.3 and current releases ship Chromium 95 or newer, but Magewell and Yolobox publish nothing about their embedded engines. `tests/selftest.php` exists to answer that on the device, and it is worth running before a first broadcast — the failure it detects is not "an overlay looks wrong" but "an overlay never updates".
- **CSS:** colours go through the tokens in the `:root` blocks, never inline literals. Adding a hard-coded hex breaks theming.
- **Markdown: never hard-wrap.** One line per paragraph, list item, and list-item continuation, however long. Blank lines still separate blocks. Hard wraps make a one-word edit reflow a whole paragraph and turn diffs to noise. When unwrapping an existing file, preserve the leading indent of a list-item continuation or it breaks out of the item and restarts the numbering.

## Language

- **Always MMP and FMP, never M and F.** Matching male player and matching female player. The categories describe who a player matches up against, not who they are, and the shortened form quietly turns a matchup rule into a statement about people. UltiOrganizer's own printed scoresheet still says "4M/3F"; write ratios as `4MMP/3FMP`, which is the same rule stated properly. The store validates the format, so the short form is rejected rather than silently accepted.
- **Name one side of a ratio, not both.** Write `4MMP`, not `4MMP/3FMP`. On a seven-a-side line four MMP means three FMP and there is nothing else it could be, so the second half is spent saying what the reader already knows — and, more importantly, writing both halves forces a decision about which comes first. There is no good answer to that question, and no reason to answer it: naming the majority side avoids putting either category ahead of the other every time a ratio appears on screen. Same for five-a-side: `3MMP` is 3MMP/2FMP.
  - The *stored* value keeps both halves (`4MMP/3FMP`), because it is a data format with a validator rather than something anybody reads. `Ratio.short()` in `shared/ratio.js` turns it into the displayed form; use it everywhere a ratio reaches a screen. That module also owns the A-B-B-A pattern, the pair for a season type, and the mixed-division test — they were duplicated between the commentator page and the progression card, which is exactly how the card came to be printing the long form after the commentator had moved to the short one.
- Say what a control switches on, not what one of its outputs is called. "Break chance" named a single graphic while the toggle actually enables possession tracking, which also feeds clean holds, the turnover count and conversion figures.

## Security

- **`lines.php` and `notes.php` are the only unauthenticated writes in the project.** The room code is a namespace, not a credential — nothing there reaches a viewer. Everything else that writes is behind `SeasonAccess::isLiveAdminAuthenticated()`.
- **A room code is never on permanent display.** `shared/secret.js` masks the code field on both the commentator page and the Studio, revealing on demand and re-masking after 30s. A namespace nobody can guess is what the unauthenticated stores rest on, and a five-character code printed on a booth screen all day is not unguessable — it is published. The auto-hide is the load-bearing part: "reveal, read it out, forget to hide it" is the actual failure mode. It also retires a whole class of argument — once the auto-hide makes "left revealed" unreachable, no feature can be justified by "otherwise they will leave it revealed". That reasoning is what put a redundant auto-reveal on the Studio's New code button.
- **`notes.php` holds personal data, which none of the other stores do.** It is a commentator's own notes about named players — plus, in the same entries, self-declared identity fields (nickname, pronouns, name pronunciation), of which pronouns are the most sensitive thing this project stores — so three things are load-bearing rather than tidy: `conf/` stays default-closed so a note room is never a servable file, the rooms expire after 14 days, and every note records who wrote it. A guessed line room exposes a line-up; a guessed note room exposes what a desk wrote about people. Same mechanism, larger consequence. `docs/COMMENTATOR.md` §5a.
- **Bound what the attacker controls, not what a caller is expected to send.** Both original caps in `lines.php` were wrong this way: rooms-per-game bounded nothing because game ids are unvalidated integers, and players-per-team bounded nothing because teams-per-room was uncapped. `docs/COMMENTATOR.md` §6 records both.
- **Encode on output.** `json_encode` with `JSON_HEX_TAG | JSON_HEX_AMP` into `<script>`, `htmlspecialchars(..., ENT_QUOTES)` into markup, integers cast. `htmlspecialchars` does *not* protect a CSS context — validate those values instead.
- **Whitelist every URL parameter** against a fixed list or a regex. Never interpolate one into a class attribute or a path.
- **`conf/` is default-closed** in `.htaccess` with `show.json` allowlisted. Anything new dropped in there is not public unless you say so.

## Accessibility and the daylight rule

The broadcast surfaces (`scoreboard`, `stage`) are rendered to video and have no interactive audience. The **studio** and **commentator** pages are real UIs used by people, and are held to real standards.

- **The commentator page defaults to a light, high-contrast theme, and that is not a style choice.** It is used at the side of a pitch. A screen cannot out-emit the sun, so under a bright sky a dark interface stops being dark — the glass becomes a mirror. Every text pair in the day theme measures **7:1 or better** (AAA). Night is available for a booth and is remembered per device.
- **Do not use `prefers-color-scheme` for this.** It reports what someone chose for their OS months ago, indoors. It says nothing about whether the sun is on the screen now.
- Structure carries meaning that position cannot: headings, `aria-labelledby` from panels to the header that names them, `aria-pressed` on toggles, `role="dialog"` with managed focus. The commentator header names each team once *visually*; a screen reader gets the same naming structurally.
- Never right-align by reversing DOM nodes — the content is then read backwards. Use `flex-direction: row-reverse`.

## Known open risk

**Diagnostics are painted onto the broadcast canvas.** A wrong game id, or five consecutive failed polls, replaces the scoreboard with white error text over the live picture. Useful on a laptop during setup, unacceptable once the source is live, and the page cannot tell the two apart. Unresolved by choice — see `docs/STUDIO.md` §11 for the three options.

## Every feature ships with docs and a test

Not a nicety here, for two reasons specific to this project.

**Docs, because the reasoning is the deliverable.** Most of what these documents record is *why* — which upstream field does not exist, which number cannot be claimed, which plausible approach was measured and found wrong. None of that survives in the code, and re-deriving it costs what it cost the first time. A feature landing without its reasoning written down is a feature that will be re-litigated.

**A test, because the failure mode is on air.** These surfaces are watched live by people who cannot refresh, and the characteristic bug is not a crash but a graphic quietly asserting something untrue — a tab outliving its point, a rate without its denominator, a field-following overlay silently showing the wrong game. Those look completely normal in a screenshot. `tests/e2e/derived.spec.js` is the model: it asserts as hard on what a feature *refuses* to claim as on what it shows.

**And put a PROTOCOL there too, not only a derivation.** `shared/tracking.js` holds the one way to talk to the collected-facts store: the API's error contract (`readJson`) plus every write. The Studio and the commentator page had grown their own copies of all of it — `readJson`, `setDefence`, `toggleStoppage`, `correct` — the same protocol written twice, differing only in where the game id and the authorising code came from. Each caller now supplies those two answers and nothing else.

**Put a derivation in `shared/` and test it there.** Anything that decides what goes on air from data — the possession log, the field lookup, the stoppage window — is a pure function, and a pure function tested directly is worth more than the same logic tested through a page. Testing the stoppage window through the browser meant moving the game clock and losing to Live!'s 30-second payload cache: one run asked for a moment 20 seconds into a timeout and was served one 131 seconds in, measuring the previous clock while appearing to measure this one. Moved into `shared/stoppage.js` and tested directly, the same suite immediately found a real bug — the window picked the event with the greatest timestamp rather than the last one that had happened, which put a timeout on a post-production frame eighteen minutes before it was called. Keep one browser test for the wiring; test the logic where it lives.

**Measure in one coordinate space, and say which.** `.stage-canvas` carries a CSS transform, so an element inside it reports scaled viewport pixels while an element inside a card's iframe reports canvas pixels. Comparing the two agrees only in a window exactly 1920 CSS px wide — which is how the tournament logo came to sit on top of the scoreboard everywhere except a full-size browser, and why the test that should have caught it did not.

Modules in `shared/` publish to `window` **and** `module.exports`, because they are loaded both ways. Do not rely on top-level `this`: it is `module.exports` under plain CommonJS but `undefined` under the loader Playwright uses, and it fails at import rather than at use.

**A test must set the state it asserts on.** The suite writes to the files a broadcast reads, so it begins from whatever the last run left — and `tests/global-setup.js` now deliberately seeds a messy stage so that dependence fails immediately instead of months later. Eight tests here had quietly stopped testing anything this way: measuring whatever cards happened to be placed, counting a possession log without emptying it, using a hard-coded "obviously wrong" code that a seeded run had made the right one. Restore in a `finally`, not after the assertions — a failure otherwise leaves the stage rearranged for everything that follows.

Where a test genuinely cannot be written — it needs hardware, or data no fixture has — say so in the pull request and in the doc, and write the check that *can* run. "Untestable" is a claim to justify, not a default.

## Verification

**Measure; do not eyeball.** On a 1920×1080 canvas viewed in a window, looking at it proves nothing. Every layout claim in this project has been settled with `getBoundingClientRect()` through headless Chrome, and several confident-looking visual judgements were wrong.

- **The suite:** `npm test` (add `ADMIN_PASS=...` for the tests that change what is on air). Playwright against a running dev instance, asserting on geometry, contrast and state transitions rather than markup. Add a test whenever a defect is found — every spec in there names the regression it exists to catch.
- Syntax: `php -l <file>`
- Routes: every page should return 200 — `/s/`, `/s/<game>`, `/s/<game>/overlay`, `/c/<game>`, and `?view=live/overlays/tests/selftest`
- Behaviour: drive the real page with the Chrome DevTools Protocol and assert on the DOM, rather than reasoning about what the code should do
- Data: after any change to a query or payload shape, run the old and new versions and compare actual rows
- **Beware your own regexes.** Several "missing element" findings in this project's history were the checker failing on attribute order or on a property set by JS, not the code being wrong. When a check says something is absent, confirm it is absent.

Fixtures: `fixtures/dev-fixture.sql` (idempotent — two 28-player squads, one finished game, one live) and `fixtures/dev-score.sh` to add a goal and watch an overlay react.

## Documentation

- `docs/README.md` — index, install, URLs. Start here.
- `docs/PLAN.md` — platform, request flow, payload field names, the scoreboard itself.
- `docs/STUDIO.md` — the stage, slots, cards, show state, and the full account of what the data supports.
- `docs/COMMENTATOR.md` — the second screen, line sharing, prepared talking points (§5a), the naming/pronunciation questions, and the federation idea (§8a).
- `docs/POSTPRODUCTION.md` — adding an overlay to a game that was recorded without a switcher: alignment, anchors, and what is not solved.
- `docs/UPSTREAM.md` — the digest of asks against UltiOrganizer and Live!, one entry per ask linking to its full case in `STUDIO.md` or `COMMENTATOR.md`.

Keep them in sync with the code. When a doc and the code disagree, the doc is a bug — this project's docs are load-bearing, because most of what they record is *why* something is the way it is, which the code cannot say.
