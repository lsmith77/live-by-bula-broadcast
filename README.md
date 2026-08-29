# Broadcast overlays for Live! by BULA

A broadcast graphics layer for Ultimate tournaments, extending [Live! by BULA](https://github.com/layoutd/live-by-bula). It turns the data an event is already keeping — score, clock, rosters, goals and assists — into graphics a video switcher can put on air, and gives the people running the broadcast somewhere to control them from.

Three surfaces, for three different people:

- **Scoreboard** — a broadcast bug on a transparent 1920×1080 canvas. One URL, point a browser source at it, done.
- **Studio** — a full-frame stage hosting several cards at once, plus the control page that decides what is on it.
- **Commentator** — a second screen, never on air: rosters, stats, who is on the field.

Everything reads through Live!'s public JSON API. No overlay touches the database, which is what makes this a drop-in that survives a Live! upgrade.

![A full frame, as a switcher receives it](docs/images/stage.png)

*What actually reaches the switcher: a 1920×1080 frame, transparent everywhere except the graphics. The bug sits inside a title-safe inset with a companion strip above it, and the rest of the frame is left alone — which is the point.*

![The scoreboard bug](docs/images/scoreboard.png)

![The scoreboard through a point](docs/images/scoreboard.gif)

*Every display state, from one real payload — score, clock, caps, timeouts, hold and break. Reproduce it on any device with `?demo=1`.*

| | |
|---|---|
| ![Score progression](docs/images/progression-card.png) | ![Summary card](docs/images/summary-card.png) |
| **Score progression** — the traditional staircase: right when the home team scores, down when the away team does. In a mixed division each step carries the gender ratio it was played at, by dash as well as colour | **Summary** — one card that works out for itself whether it is before the pull, at the half, mid-game or full time |
| ![Studio](docs/images/studio.png) | ![Commentator](docs/images/commentator-daylight.png) |
| **Studio** — what is on air, and where | **Commentator** — daylight by default, because the job happens beside a pitch |
| ![Player sheet](docs/images/player-sheet.png) | ![Commentator at night](docs/images/commentator-night.png) |
| **Player sheet** — everything Live! publishes about one player | **Night** — for a booth or an evening game |

## Features

### Scoreboard — the on-air bug

| | |
|---|---|
| Score | Live, with a flash on change, and a HOLD / BREAK tab over the scoring side |
| Clean holds | HOLD becomes CLEAN HOLD when the defence never touched the disc that point — only where possession was actually being tracked |
| Break chance | A red tab while the defence has the disc, driven by whoever is tracking possession |
| On defence | A quieter standing tag, always true, shown whenever nobody is tracking possession |
| Game clock | Counts up, or down to the pool's time cap. Derived from `timer_start`, ticks locally between polls, corrected for server skew |
| Caps | Half cap amber, time cap red, with the new point cap spelled out. Dropped once the game is not running |
| Timeouts | Ticks under each name, with the half-time reset where the format has one |
| Kit colours | Entered in the Studio minutes before the pull. Neutral until both sides are set |
| Team logos | Per-team files in `logos/`, falling back to Live!'s own team photos |
| Context ribbon | Pool, round, field |
| Layout | Auto-fitting team names, `?size=compact`, four corner positions, light/dark logo plate |
| Backgrounds | Transparent by default; `?bg=green\|blue\|magenta\|black\|<hex>` for switchers that cannot key alpha |
| `?demo=1` | Walks every display state from one real payload — scheduled, live, hold, break, timeout, cap, paused, final |
| `?reload=<s>` | Meta-refresh fallback for a browser source that rasterises once and never runs timers |
| `?at=…` | Renders one deterministic frame of a stated instant and stops — the basis for adding an overlay to a game that was recorded without a switcher |

### Studio — the operator's page at `/s/`

| | |
|---|---|
| Public, read-only | Game list, fields, kick-off times and browser-source URLs without logging in. Logging in adds the controls, not the information |
| Game list | Live first, then scheduled, then final, each with field and time |
| Kit colours | A picker beside each team name on the matchup line |
| Position picker | A 3×3 map of the frame, plus Full frame and With scoreboard |
| On air | One switch per card. Several cards may be *placed* in a position; one may be *on air* |
| Displacement warning | Hovering a control that would take something off air flags the card that would lose, before the click. Nothing is ever disabled for conflict |
| Auto mode | A card shows itself at the moment it is about: 15s after a goal, 30s at the halftime cap, 45s at full time, or continuously until the first pull. Each card is offered only the moments that suit it |
| Config file | Export the whole arrangement and import it on another field — or feed it to post-production, so a recording gets the same overlay the broadcast used |
| All off air | Clears the frame in one click, keeping every position and preload intact |
| Undo | One level — the mistakes worth reversing are always the last action |
| Tournament logo | `TV_SCREEN_LOGO_PATH` in a chosen corner, kept clear of whatever else is there |
| Break chance mode | Enable it, then declare possession with **O** and **D** on the keyboard, or hand tracking to a commentator by naming their room code |
| Connected commentators | How many commentator pages are live on that code |

### Stage — one URL per broadcast at `/s/<game>/overlay`

| | |
|---|---|
| Single browser source | One URL for the whole broadcast, pinned to one game, so several fields can run side by side |
| Slots | Nine positions plus a full-frame takeover and a companion slot that rides with the scoreboard |
| Cards | Scoreboard, last goal, last assist, last goal + assist, top players (4 per team, at least one FMP and one MMP in mixed), and pre-game / half-time / full-time summaries with records, seeds and breaks |
| Arm then show | Every card loads and decodes before it is revealed, so nothing pops in half-drawn on air |
| Auto mode | With no configuration at all, the stage runs the scoreboard and nothing else |

### Commentator — the second screen at `/c/`

| | |
|---|---|
| Daylight first | A high-contrast light theme measured at 7:1 or better throughout, because the page is used beside a pitch. A night theme for booths, remembered per device |
| Team stats | Record, points for and against, difference, pool, standing, with deep links into the tournament site |
| Rosters | Both squads side by side, sorted by number, goals, assists, points, tournament total, or blocks |
| Player sheet | This game and the tournament, blocks and callahans where recorded, games and points per game, a game-by-game history, and the team-mate they most often score with |
| Line selection | Who is on the field, shared between two commentators through a room code |
| Possession | Track offence and defence with **O** and **D**; turnover counts for this point and the last |
| Blocks | A column and a sort, but only where the installation actually records them |

### Underneath

| | |
|---|---|
| Two polls | Show state and possession on a ~1s static channel; game data on Live!'s own cache life. An operator's click is instant; a break chance is never ten seconds late |
| Store-side rules | Slot and visibility rules live in the store, so no client can write an illegal frame |
| Optimistic locking | Two people on the control page cannot silently overwrite each other |
| Self-test | `tests/selftest.php` — four independently moving panels, so a switcher that fails to update can be diagnosed by *which* layer is frozen |
| Fixtures | A complete two-team tournament, idempotent, with a script to add goals and watch an overlay react |

## Requirements

A working [Live! by BULA](https://github.com/layoutd/live-by-bula) 3.0.6 installation with a published season. Live! v3 in turn requires [UltiOrganizer](https://github.com/ktolonen/ultiorganizer) 4, PHP 8.3+ and MariaDB 10.11+.

Note that Live! by BULA has its own prerequisite: its [Terms of Use](https://github.com/layoutd/live-by-bula/blob/main/Terms%20of%20Use%20-%20Live%20by%20BULA.pdf) must be signed and returned to live@beachultimate.org before use.

## Install

Unzip or clone into `live/overlays/` — beside Live!'s own code, the same way Live! is installed beside UltiOrganizer's — and make `conf/` writable by the web server. There is no build step.

Recorded a game without a switcher? [docs/POSTPRODUCTION.md](docs/POSTPRODUCTION.md) covers adding the same overlay afterwards.

Full instructions, URLs, and the reasoning behind the design: **[docs/README.md](docs/README.md)**.

## Help wanted

Two kinds of help would make more difference to this project than more features.

**Design.** The graphics work and are measured — contrast, title-safe insets, colour-blind separation, names that fit — but measured is not the same as *good*. The scoreboard bug and the cards would benefit from somebody who designs broadcast graphics for a living, and the operator and commentator interfaces from anyone who does UX. If a layout here looks amateur to you, that is useful information and I would like to hear it.

**Testing.** This has never run at a real tournament. It has never run on a Magewell Director Mini or a Yolobox at all, and the single assumption everything rests on — that a hardware switcher's browser source keeps running JavaScript — is still unverified on hardware. `tests/selftest.php` answers that in about ten seconds on the device.

If you are broadcasting an Ultimate event and willing to try it, or to run the self-test and report what happened, that is worth more than any amount of further development. Failures are the useful part: an overlay that never updated, a graphic that looked wrong on air, an instruction that made no sense at the side of a pitch.

There is also a wishlist pointed the other way: [docs/UPSTREAM.md](docs/UPSTREAM.md) collects what these overlays want from UltiOrganizer and Live! by BULA themselves — one entry per ask, with what it unlocks and a link to the full case.

Open an issue either way.

## Development

This project is built with heavy use of AI coding assistants. The conventions that keep that workable — what may not be touched, how claims are verified, and why the documentation is load-bearing rather than decorative — are written down in [AGENTS.md](AGENTS.md), which is meant for both the humans and the agents.

Two of those conventions are worth stating here, because they shape everything else. Behaviour is established by **measurement** rather than by reading the code and reasoning about it: layout claims come from `getBoundingClientRect()` in a real browser, data claims from running the query and comparing rows. And the documents under [docs/](docs/) record *why* decisions were made, including the ones that turned out to be wrong — which is the part neither a human nor a model can reconstruct from the source later.

## Tests

```
npm install
npm test                      # the suite
ADMIN_PASS=... npm test       # including the tests that change what is on air
npm run shots                 # regenerate the images above
```

Playwright, against a running dev instance rather than a mock — the payload shape is exactly the thing that has been wrong before, so a mock would agree with itself and nothing else. It uses the system Chrome (`channel: 'chrome'`), so there is no browser download and the dev dependency is three packages.

The assertions are deliberately about **geometry, contrast and state transitions** rather than markup, because that is how this code has actually failed: a companion card overlapping the scoreboard by 22px, a stage rendering below the fold in a normal window, a heading that read backwards to a screen reader, two caps that looked like bounds and bounded nothing. `expect(box.right).toBe(other.left)` is a claim about what goes on air; `expect(html).toContain('<div>')` is not.

Tests needing the Live! admin session skip themselves without `ADMIN_PASS`, and anything that writes show state restores it afterwards — the suite borrows the same files a real broadcast reads.

## Licence

CC BY-NC-ND 4.0, matching Live! by BULA. See [LICENSE.txt](LICENSE.txt).
