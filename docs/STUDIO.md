# Studio — operator-controlled overlay system

**Status:** plan, with the MVP built (§9). `PLAN.md` owns the platform rebase and the scoreboard itself; this document covers the step from *one overlay per URL* to *one stage per broadcast, with an operator deciding what is on it*. `COMMENTATOR.md` covers a third consumer — the person talking over the broadcast, who needs information rather than graphics, and who operates under almost the opposite constraints because nothing they see goes to air.

**Written:** 2026-08-22

---

## 1. Why the current shape runs out

Today one URL is one overlay, and the switcher decides where it sits. That works for exactly one graphic. The moment a second one exists — a player stat card, a half summary, a bracket — the model breaks down in three ways:

- The switcher would need one browser source per graphic, each separately positioned, each separately shown and hidden through the device's own UI.
- Nothing can coordinate between graphics: two cards can occupy the same corner, or the scoreboard can stay up underneath a full-screen takeover.
- Positioning lives in the device, so it cannot be changed from a laptop mid-game, and it is different on every switcher.

A scoreboard bug, a possession callout, a player stat card and a half-summary card are not four browser sources. They are one graphics layer with a director deciding what is on it, and that is the shape this document describes.

## 2. Architecture

```
                    ┌──────────────────────────────────────────┐
  switcher  ───────▶│  STAGE   full 1920x1080, transparent     │
  browser source    │  one URL for the entire broadcast        │
                    │                                          │
                    │   ┌────────┐              ┌───────────┐  │
                    │   │ slot   │              │  slot     │  │
                    │   │ upper- │              │  upper-   │  │
                    │   │ left   │              │  right    │  │
                    │   └────────┘              └───────────┘  │
                    │   ┌──────────────────┐                   │
                    │   │ lower-left       │   ← scoreboard    │
                    │   └──────────────────┘                   │
                    └──────────────────────────────────────────┘
                              ▲                      ▲
                     show state (what)        game data (numbers)
                     poll ~1s, tiny           poll ~10-30s, cached
                              │                      │
                    ┌─────────┴────────┐    ┌────────┴─────────┐
                    │ CONTROL          │    │ live/api          │
                    │ admin-gated UI   │    │ (unchanged)       │
                    └──────────────────┘    └───────────────────┘
```

Five pieces, and the split between the two polls (§6) is the important one.

### 2.1 Stage — `live/overlays/stage.php`

The only URL the switcher ever needs. Full-frame, transparent, no chrome. It owns the slot grid and mounts cards into slots. It does not know what any card means.

### 2.2 Cards

A card is a self-contained renderer with a fixed contract: given the game payload and its own params, draw into the box it is handed. Candidates, cheapest first:

| card | source of truth | cost |
|---|---|---|
| `scoreboard` | already built | none — it is the bug we have |
| `topplayers` | `entity=teams` → `players[].total` | low — a sort, nothing to derive (§3.1) |
| `pregame` / `halftime` / `postgame` | `goals` + `classifyPoints()` + `entity=teams` | **built** — one renderer, three framings (§9.3) |
| `player` | `hometeam_scoreboard` / `visitorteam_scoreboard` | low — goals/assists yes, blocks conditional (§3.1) |
| `gamestats` | `classifyPoints()` | low — breaks yes, turnovers impossible (§3.2) |
| `embed` | an iframe of an existing Live! page | low *if* framing works — unverified, see §7 |
| `teamresults` | `entity=teams` | medium |
| `bracket` | `entity=reference` → `pool_placements` | medium; open question 3 in `PLAN.md` |
| `spirit` | `entity=spirit`, variable `cat1..catN` | medium |

### 2.3 Slots

Standard positions only, no drag-and-drop:

```
upper-left    upper-center    upper-right
                 center
lower-left    lower-center    lower-right
                                          + fullscreen (takeover)
```

**Placement is non-exclusive; visibility is exclusive.** Several cards may be *placed* in one slot — that is how an operator sets up alternatives in advance, each armed and preloaded — but at most one of them may be *on air* at a time, because two cards in one position would draw over each other. Putting one on air therefore takes any other in that slot off; §2.7 covers how that is surfaced. `fullscreen` hides everything else while it is up. All three rules live in the store, not only in the control UI, so no client can write an illegal combination.

### 2.4 Show state

One small JSON document, the entire contract between control and stage:

```json
{
  "rev": 42,
  "game": 702,
  "cards": [
    { "id": "scoreboard", "slot": "lower-left", "visible": true },
    { "id": "player", "slot": "upper-right", "visible": true, "params": { "player": 800 } }
  ]
}
```

Stored exactly like `conf/team-colors.json` — `live/overlays/conf/show.json`, gitignored, written atomically through temp-file-and-rename so a stage polling through a save never reads half a document.

**Write protocol.** `rev` is not decoration: a writer sends the `rev` it last read, and the store rejects the write if `rev` has moved. Two people on the control page — a common enough situation, one on a laptop and one on a phone — would otherwise silently overwrite each other, and the loser would not know their change had been undone. On rejection the control UI reloads and reapplies.

### 2.5 Control — `live/overlays/index.php` (`/s/`)

Admin-gated by `SeasonAccess::isLiveAdminAuthenticated()`, so anyone who can view an overlay cannot change what is on air. A grid of toggles: card on/off, slot chooser, and card-specific pickers (which player, which team). No preview needed in v1 — the operator is looking at the program monitor anyway.

**Control owns all overlay-local state, not just show state.** There is a category of data here that does not come from UltiOrganizer or Live! at all, is authored by the broadcast operator, lives in `live/overlays/conf/`, and is gated by the same admin session:

| state | file | today |
|---|---|---|
| Which cards are on screen | `conf/show.json` | this document |
| Kit palettes and the per-game coin-toss pick | `conf/team-colors.json` | currently edited on the picker page |
| Possession log, if ever built (§10.4) | — | not built |

Kit colour is the clearest case. UO has no team colour at all — `uo_pool.color` and `uo_series.color` exist but `uo_team` has none — so the palette and the coin-toss pick are purely overlay state. They are edited on the picker page only because the picker existed first, which splits one operator's controls across two surfaces with the same auth gate.

**Consolidate them into one page at `/s/`, public but read-only.** Not a separate admin-gated surface: the game list, the fields and the URLs are not secret, and someone setting up a camera should not need an admin password to find a URL — least of all in auto mode, where there is no operator to hold one. So the page renders read-only for anyone, and logging in turns the controls on in place. It carries a login button so an unauthenticated visitor can see *why* things are inert and where to go.

The line that must stay open is the overlay itself: `/s/702` and the stage are fetched by a browser source that cannot log in, so they can never be gated by anything beyond Live!'s event-publication boundary.

A consequence worth noting for whoever moves it: the colour editor already renders read-only rather than erroring when not authenticated, and distinguishes "not admin" from "`conf/` not writable by the web server". Both behaviours should survive the move — they are what makes an unexpected read-only state diagnosable instead of mysterious.

#### Overlay-local state as a proving ground

There is a general principle here, and it is arguably the most useful thing the Studio offers beyond the graphics themselves.

**Operator-maintained state is a way to add features that would otherwise need core changes.** Kit colour already demonstrates it: UltiOrganizer has no team colour, and rather than proposing a `uo_team.color` migration, the overlay stores palettes itself and the feature simply exists. Break-chance declaration (§3.5) is the same move applied to possession.

What makes this attractive as a first step:

- **No schema migration, no upstream review cycle.** It ships at the pace of this directory.
- **Reversible.** A `conf/*.json` file that turns out to be a bad idea is deleted.
- **Per-installation.** One tournament can try something without every UO site inheriting it.
- **It produces evidence.** If operators use a control constantly, that is a real argument for the upstream version. If nobody touches it, an upstream change was avoided — which is worth as much.

And the boundaries, which matter just as much:

- **Ephemeral and broadcast-only.** It exists while a broadcast is running, for the games being broadcast. Anything that should be true for every game belongs upstream.
- **Not authoritative.** It must never be written back into the scoresheet or treated as a result. A missed press degrades a graphic; it must not corrupt a record.
- **It spends operator attention**, which is finite and contended precisely when the broadcast matters most (§3.5).

**The graduation path.** Prove it here; if it proves valuable *and* needs to be authoritative or available beyond the broadcast, propose it upstream then. Because cards adapt at runtime (§3.3), the overlay can prefer upstream data the moment it appears and fall back to operator state when it is absent — so graduating a feature is a data-source change, not a rewrite, and the operator control quietly stops being needed rather than having to be withdrawn.

### 2.6 Card lifecycle: arm, then show

**Every card has two phases.** This exists because of imagery (§5) but it is an architectural rule, not a photo detail:

```
ARM   fetch the data, load and decode every image the card will use, lay it out offscreen
SHOW  reveal it — no network, no decode, no layout, nothing that can stall a frame
```

A card that appears while an asset is still loading pops in half-drawn, on air.

The operator flow makes this natural: selecting a player in the control UI *arms* the card, and the show button reveals it. Between those two actions there is normally several seconds of a human deciding, which is ample.

Mechanically, arming waits on `img.decode()` for each image — `onload` is not sufficient, because it resolves before the bitmap is decoded and the first paint can still stall. A decode that fails or times out arms the card **without** the asset rather than blocking it: a missing portrait is a much smaller failure than a card that never appears.

Auto mode (§8) has no operator to arm anything, so it must arm on the *preceding* trigger — a half-summary card arms when the halftime cap event appears, not when it is due on screen.

### 2.7 Show the consequence; never block the click

Placement is non-exclusive but visibility is exclusive per slot (§2.3), so putting a card on air can take another one off. The obvious protection — disable the control that would displace something — is the wrong trade twice over: displacing a card is very often exactly what the operator intends, and a disabled control gives them no route to what they want and no explanation of why.

So the click always works, and the consequence is shown **before** it: hovering a control that would take something off air flags the cards that would lose. The warning lands on the card being taken off, which is where an operator looking for it will look, rather than in a message box beside the control they are already pressing.

This applies to every displacing action — switching a card on into an occupied slot, moving a live card to an occupied slot, and All off air (§9.2) — and it is why none of those are ever disabled for conflict. The only thing that disables a control here is having nothing to do.

## 3. What the data actually supports

Verified against live payloads and the UO schema, not assumed. This is the difference between a card that can be built this week and one that needs an upstream change.

### 3.1 Player stats — well supported

`entity=teams&id=<team>` returns a `players` array, and the per-game equivalent is `hometeam_scoreboard` / `visitorteam_scoreboard` in the game payload. Each player carries:

| field | meaning |
|---|---|
| `done` | goals |
| `fedin` | assists |
| `total` | **goals + assists already summed** |
| `callahan` | callahans |
| `num` | jersey number |
| `games`, `doneavg`, `fedinavg`, `totalavg` | per-game averages |

So "top 3 by combined goals and assists" is a sort on `total` — no derivation needed. `totalavg` gives the fairer pre-match version when teams have played unequal numbers of games, which at a tournament they usually have.

**Blocks are reachable — behind a switch that ships off.** A player card naturally wants GOALS / ASSISTS / BLOCKS. Goals and assists are above; blocks arrive too, but only once an administrator turns them on.

`live/api/TeamManager.php:151` picks the roster query by `ShowDefenseStats()`, a system setting that ships **false** (`sql/ultiorganizer.sql:975`). With it on, `TeamScoreBoardWithDefenses()` is used instead and every roster row on `entity=teams` carries an extra field:

| field | meaning |
|---|---|
| `deftotal` | that player's block count for the tournament |

Measured, not assumed — with the setting on and `uo_defense` seeded, `entity=teams&id=300` returns `deftotal` on all 28 rows, non-zero for the three players who have one.

Three properties that shape how it may be used:

- **It is the roster row, not the game payload.** `entity=games` carries nothing about blocks, so a block count is a per-*tournament* figure. There is no per-game block list to split it with, the way the goal list splits goals and assists.
- **Completed games only.** The subquery filters `g.isongoing=0`, the same rule the `done`, `fedin` and `total` columns follow. A block made in the game currently on the clock is not in the number until that game is closed. Consistent with the neighbouring columns, and worth saying out loud wherever the figure appears.
- **The setting is a display toggle, not a guarantee the data was captured.** See §4 — this is the constraint that still rules out a leaderboard.

One trap worth naming: `TeamScoreBoardWithDefenses()` does **not** return `totalavg`, which the non-defence variant does. Anything reading `totalavg` silently becomes zero the moment an administrator turns the setting on. Derive points-per-game from `total` and `games` instead.

### 3.2 Game stats — partly supported, and partly redundant

| stat | available? | how |
|---|---|---|
| **Holds** | yes | derived client-side, `classifyPoints()` |
| **Breaks** | yes | same |
| **Blocks** | tournament totals yes, per game with work | tournament totals arrive as `deftotal` on the `entity=teams` roster row once `ShowDefenseStats` is on (§3.1). A *per-game* count is not in any payload; `GameTeamDefenseBoard()` (`lib/game.functions.php:534`) returns one but needs the direct-database decision in §3.4 |
| **Break chances** | not from UO data | no possession changes are recorded, so it cannot be derived (§3.4) — but an operator can declare it, which is the route taken (§3.5) |
| **Clean holds** | only with §3.5 | derivable once break chances are declared, behind a config option because its failure mode over-claims |
| **Turnovers** | **no** | no turnover table exists at all. A throwaway or a drop changes possession and leaves no trace anywhere in the schema |

**Holds and breaks are the same fact twice.** Every point is one or the other, so holds + breaks = total points and the pair carries no more information than breaks alone. A stats card should lead with breaks — the number that decides ultimate games — rather than spending a row on its complement. `classifyPoints()` also returns an `unresolved` count, and that must be shown whenever it is non-zero rather than folded into either column.

**Blocks are the genuinely additive one** — a block count cannot be inferred from the score at all, so it is the row that earns its place next to breaks, and it is the same data the player card wants. But its completeness is not guaranteed, which constrains how it may be used (§4).

### 3.3 Cards adapt at runtime; they are never configured to match

A card renders GOALS / ASSISTS when the block field is absent from the payload, and GOALS / ASSISTS / BLOCKS the moment it appears — no overlay setting to flip, no redeploy, no per-installation build. An administrator turning `ShowDefenseStats` on mid-tournament should see the column appear on the next poll.

Two things that follow, both easy to get wrong:

- **Absent is not zero.** A missing field means "this installation does not track blocks"; a present `0` means "this player has no blocks yet". The first must drop the column, the second must show `0`. Testing `if (blocks)` conflates them — test for presence.
- **The layout must not assume three columns.** A two-column card has to look deliberate rather than like a three-column card with a hole in it, so column widths come from what is actually being rendered.

The same rule applies to every card: read what the payload offers and render that. Cards that are configured rather than adaptive drift out of sync with the installation and fail in the least visible way — silently, on air.

### 3.4 Break chance: not derivable, but declarable

**Decision: break chance is all-or-nothing.** It is only worth showing if it can appear in every case it should. A version that lights on recorded blocks but stays dark on a throwaway or a drop is worse than none: viewers cannot tell "no break chance" from "we did not notice", so the indicator teaches them to distrust it. An overlay that is silent is honest; one that is *intermittently* silent is misleading.

**That rules out deriving it. It does not rule out an operator declaring it** — see §3.5, which is the route worth taking. The distinction is the kind of incompleteness:

| mechanism | failure mode | acceptable? |
|---|---|---|
| Derived from `uo_defense` blocks | **structural** — a throwaway or a drop can never produce a block, so whole categories of break chance are invisible by construction | no |
| Declared by the operator | **attentional** — every case is representable; a missed one is a human slip, the same class of error as a late graphic | yes |

The rest of this section documents why the derived route is closed, because "we have blocks somewhere" keeps looking like an answer.

Traced end to end, because "we have blocks somewhere" is misleading:

| layer | what it has | verdict |
|---|---|---|
| `uo_defense` table | `game`, `num`, `time`, `ishomedefense`, `author` | **timestamped blocks exist** |
| `lib/game.functions.php:534` `GameTeamDefenseBoard()` | aggregates to per-player `COUNT(*)` | **discards `time`** — no possession events |
| `live/api/GameManager.php` | never references defenses at all (0 matches) | **nothing reaches the game payload** |
| `live/api/TeamManager.php:152` | team-level board, behind `ShowDefenseStats()` | wrong granularity |

So the data is in the database and stops before every layer an overlay can reach.

**Reaching it would not change the decision.** A new query against `uo_defense` returning raw rows with times, served from an overlay-side routed endpoint, is compatible with the Live!-upgrade rule — nothing is added to the Live! zip. But it yields only blocks, which is precisely the partial signal ruled out above.

**It is still worth doing for the stats cards**, where a block count is a normal sports statistic carrying no such implication. The same endpoint serves the BLOCKS column through `GameTeamDefenseBoard()` with no new query at all. So "may an overlay read the database directly?" remains worth deciding once, deliberately — for statistics, not for possession.

### 3.5 Operator-declared break chance — the bridge, not the destination

The Studio makes break chance reachable without any UltiOrganizer change at all: it becomes **overlay-local state the operator maintains** (§2.5), carried on the fast show-state channel rather than the cached game payload.

**This is a bridge.** The right long-term home for possession data is the scorekeeper (§10.4), for a reason that is easy to miss when optimising for "cheapest to build": *the operator's attention is not spare capacity*. Their job is directing — choosing graphics, timing them, watching the programme output. Adding "press a button on every turnover" competes with that job rather than riding along with it, and it competes hardest exactly when the broadcast matters most, during a close final with a full card set in play.

Scorekeeper-captured possession is better on every axis except build cost:

| | operator-declared (§3.5) | scorekeeper-captured (§10.4) |
|---|---|---|
| Upstream change needed | none | yes |
| Available for | games being broadcast | every game |
| Survives the broadcast | no — ephemeral overlay state | yes — feeds `uo_player_stats`, season stats, Live! itself |
| Authoritative | no | yes |
| Competes with the capturer's main job | **yes** | no — it *is* their main job |

So build this because it works today, and prefer the possession log the moment it exists. Per §3.3 the scoreboard should simply take whichever is available: real possession data first, operator declaration second, the standing ON DEFENCE tag last. The operator button then quietly stops being needed rather than having to be removed.

**One button, no team picker.** The overlay already knows which side is on defence — it falls out of the goal list, since whoever concedes receives next. So the operator is not saying *who* has a break chance, only *that the defence has the disc right now*. That is a single boolean, which is roughly the least an operator could be asked for.

**It clears itself.** The flag is scoped to the current point, and the states that end a point also end the flag:

| trigger | why |
|---|---|
| the score changes | the point is over; a stale tab would claim something that is no longer happening |
| the disc is turned back over | operator presses again — it is a toggle, not a one-shot |
| the game stops being live | same rule the standing tag already follows |
| a staleness ceiling | belt-and-braces against an operator who sets it and looks away |

Auto-clear on score is the important one: without it the single most likely failure is a red tab left up through the next point, which is exactly the "asserting something untrue" problem the whole section is trying to avoid. Note the overlay can do this itself — it already sees the score change and already flashes HOLD/BREAK on it.

**Latency makes this fit the architecture.** A break chance is worthless ten seconds late, so it cannot ride the 10–30s game-data poll. It belongs on the ~1s show-state channel (§6), which is precisely what that channel exists for. This is the clearest justification yet for the two independent polls.

**Graceful degradation, per §3.3.** The scoreboard renders whichever it has:

- Studio running and the operator engaged → transient **BREAK CHANCE**, the real thing.
- No Studio, or nobody pressing → the standing **ON DEFENCE** tag, which is always true.

So the current behaviour is not thrown away; it becomes the fallback. The card adapts to what is available rather than being configured, exactly like the BLOCKS column.

#### Clean holds, behind a config option

Once break chances are declared, a hold with none marked against it *is* a clean hold. So this falls out of §3.5 for free — but it must be **off by default and enabled explicitly**, because its failure mode is the opposite of break chance's.

| | operator misses a press |
|---|---|
| **Break chance** | the tab does not appear — an **under**-claim, silence |
| **Clean hold** | a hold that had a turnover is labelled CLEAN — an **over**-claim, a false statement on air |

Break chance degrades into saying nothing. Clean hold degrades into saying something untrue. That asymmetry, not squeamishness, is why one is safe to leave on and the other needs a deliberate switch.

The switch is really a **capture-policy declaration** in the sense of §4: the operator asserting "at this event, break chances are marked reliably enough to infer their absence". It is the same pattern — declared, not inferred — applied to a human rather than a scorekeeper, and it belongs with the other overlay-local state in Control (§2.5).

Three rules for the implementation:

- **Assert only the positive.** A hold with a break chance against it is just **HOLD**, never "dirty hold". Only the clean case earns a label; everything else stays silent.
- **Only for points observed end to end.** If the Studio connected mid-point, or the operator enabled the flag mid-point, that point's cleanliness is unknown — fall back to HOLD. A point is only eligible if the mechanism was live for the whole of it.
- **Follow the game, not the wall clock.** Eligibility resets per point, on the same score-change trigger that clears the break-chance flag.

Until then, and whenever no operator is driving it, the scoreboard shows **ON DEFENCE**: a standing fact about the point that is always true, instead of an event claim it cannot verify.

## 4. Capture policy — the precondition for trustworthy stats

### 4.1 The problem

`ShowDefenseStats` is **not** a per-tournament switch saying "scorekeepers at this event record blocks". It is a `SYSTEM_FLAG` in `uo_setting` / `$serverConf` (`lib/configuration.functions.php:35`), sitting alongside `PageTitle` and `DefaultTimezone`: one value for the entire installation and every tournament hosted on it. And it governs **display**, not capture — nothing about it causes a D to be recorded.

The consequence is the thing to design around: **whether blocks were captured varies from game to game within a single tournament**, according to how diligent each game's scorekeeper was. A zero is therefore ambiguous in the worst way — it means either "no blocks" or "nobody entered any", and the overlay cannot tell which.

That makes a cross-team leaderboard actively misleading. "Top blockers" would rank the players whose scorekeeper bothered, and no disclaimer on a broadcast graphic fixes that.

| use | verdict |
|---|---|
| Per-player blocks *within one game* | acceptable — one scorekeeper, internally consistent |
| Comparing the two teams *in this game* | acceptable, same reason |
| Tournament-wide leaderboards or top-N | **not acceptable** unless capture is known complete |

**Interim heuristic:** if a game or tournament has zero blocks recorded across *every* player, treat that as "not captured" and drop the column, rather than rendering a row of zeros. A genuine game with no blocks at all is vanishingly rare, so the heuristic is overwhelmingly right and fails safe when it is wrong.

### 4.2 The upstream ask: organizer-declared policy, with a per-game override

Any optional statistic — blocks today, possession changes if they are ever added — needs a **declared capture policy**, not merely a display toggle. The organizer states what this event undertakes to record; consumers then distinguish *zero* from *not tracked*, which is the distinction every adaptive card in §3.3 depends on.

**It should cascade, and per-game override is the important part.** Staffing is not uniform across a tournament: a Sunday final often has extra people available who can record blocks or possession, while Friday pool play on six fields simultaneously does not. A policy fixed for the whole event would have to be set to the *weakest* game, discarding good data from the showcase games that most want a broadcast overlay.

```
event / series format   declares the baseline  ("we record blocks")
        ↓ inherits
pool                    may narrow or widen it
        ↓ inherits
game                    may override           ("this final also records possession")
```

**This is an existing UltiOrganizer pattern, not a new one.** Game rules already cascade exactly this way: `uo_pooltemplate` holds a reusable format, `uo_pool` carries the live values — `timeoutlen`, `halftime`, `winningscore`, `timecap`, `scorecap`, `timeouts`, `timeoutsper` — and `uo_game.halftime` already demonstrates a per-game override of a pool setting. The ask is to extend a shape the schema and the admin UI both already have, which makes it a much smaller request than inventing a capture-policy concept.

Two properties worth specifying in the ask:

- **Declared, not inferred.** The flag records an *intention to capture*, so absence of data under a policy of "we record blocks" is genuinely "no blocks", and is trustworthy. A flag derived from whether rows happen to exist would restate the problem rather than solve it.
- **Per statistic, not one global "detailed stats" flag.** An event may well record blocks but not possession. One boolean would force the weakest common denominator again.

## 5. Player imagery

**Live! has no player photos at all.** `TEAM_PHOTOS_ENABLED` covers *team* photos only, served from `/live/teams/TEAMID-xxxxx.jpg` (`live/api/TeamManager.php:461`), and it is `false` in this installation's config. No player object in any entity carries an image field. UO core has `uo_player_profile` and `uo_image`, but Live! does not expose them and `PLAN.md` forbids editing anything that ships in the Live! zip.

So player imagery needs an overlay-side store, exactly mirroring what `shared/logos.php` already does for team logos: a `live/overlays/players/` directory keyed by `player_id`, resolved by the overlay, invisible to Live!, and surviving a Live! upgrade untouched. That is a small amount of code and reuses a pattern that is already working.

Every card carrying imagery must respect the arm/show lifecycle in §2.6.

## 6. Transport, and the device question

**Two independent polls, deliberately.** Game data is cached server-side and slow to change. *What is on screen* must react in about a second, because the operator is pressing a button while looking at the output.

| | endpoint | cadence | latency |
|---|---|---|---|
| show state | `conf/show.json`, static | ~1s (`?showpoll=` down to 250ms) | operator click to screen ≈ 0.9s |
| game data | `?view=live/api&entity=games&id=` | follows `meta.expires_timestamp` | score to screen ≈ 3–45s, mean ~20s |

**Serve show state as a static file, not through PHP.** The reason is not CPU. A routed request measures ~7ms against ~3ms for the static file, so 86,400 of them is about seven minutes of CPU a day — real but unremarkable. The actual problem is that `index.php:75` calls `LogPageLoad()`, which runs `UPDATE uo_pageload_counter SET loads = loads + 1` on every routed view (`lib/logging.functions.php:317`). A one-second routed poll would add 86,400 increments a day per stage and quietly destroy the admin page-load statistics. The show document is a plain JSON file written atomically by rename, so Apache serves it directly with no PHP, no session and no counter in the path. It is not secret; it says which graphic is on screen.

### Why not WebSockets

Evaluated properly rather than assumed, because the obvious next thought is to extract all of this into a Node service that consumes the UO/Live! APIs. **The conclusion is no, and the reason is that WebSockets would optimise the one term that is already small.**

Decomposing score-to-screen: the scorekeeper's own reaction is 3–15s and uninstrumented; the POST reaches the database in under 100ms; then the payload sits behind a **flat 30s** server cache (`CACHE_SECONDS_GAME_DETAIL_ONGOING`, `live/api/GameManager.php:15`, selected on `status === 'ongoing'` at `:358-361`); the client then polls exactly on expiry, costing ~8ms. There is **no cache-invalidation hook** — nothing in UO core wipes a game's cached payload when a goal is recorded, so a scored point genuinely waits out the cache.

A WebSocket changes none of that. The data source is a lazily regenerated file cache, not a stream, so a push channel would deliver the same stale payload sooner. It improves only the show-state term: mean 500ms of poll wait, on a 226-byte file. And `?showpoll=250` already recovers most of that with a URL parameter.

**What actually fixes score latency** is an overlay-side uncached endpoint that reads the score directly and is polled every second or two — roughly 150 lines, inside `live/overlays/`, adding nothing to the Live! zip. It does relax `PLAN.md` §5's no-direct-database rule, which is already flagged there as under review, and it is the same decision §3.4 raises for block statistics. Mean score-to-screen would go from ~15s of cache wait to about 1s.

**Do not lower `CACHE_MINUTES_MODULATOR` instead.** Upstream documents it as "DO NOT LOWER BELOW 1.0" (`live/api/ConfigManager.php:642`) and it is global, scaling the public frontend's caching as well. Earlier revisions of this document and of `PLAN.md` recommended it; that was wrong. `ENABLE_CACHE_WARMER` does not help either — it regenerates payloads off the request path but does not shorten the advertised expiry the client obeys, and can increase observed staleness.

Extraction to Node would also break three things that currently come free: the admin session (`SeasonAccess::isLiveAdminAuthenticated()` reads a `$_SESSION` flag set by UO's `startSecureSession()`), same-origin framing (the stage mounts the scoreboard as an iframe of a `?view=` URL and fetches with `credentials: 'same-origin'`), and the unpublished-event 403 that `EnforcePrivateEventAccessForView()` applies before an overlay renders. Plus a second runtime to supervise on a venue laptop, operated by whoever is also running the camera.

**When extraction would become right**, concretely: more than roughly 25 simultaneous stage clients on one machine; or a sub-100ms click-to-screen requirement, which the arm/show design in §2.6 deliberately removes; or the appearance of a genuine push source such as a possession log (§10.4), at which point polling is the wrong shape regardless of runtime. None hold for a club tournament, and stage count alone never triggers it.

**A known inefficiency, unrelated to transport.** The stage runs its own game-data client *and* the framed scoreboard runs a second one against the same game, while `topplayers` issues two `entity=teams` fetches per payload. That is roughly four times the request rate this table implies. Still trivial at this scale, but it is the thing to fix before reaching for a new protocol.

**The device question is separate and must be measured, not assumed.** Before building any of this, run `?view=live/overlays/tests/selftest` on the switcher and look at the program output, not at a laptop. It moves four things independently — a JS timer, an animation-frame counter, a pure-CSS animation and a network poll — so whichever are frozen identify which layer the device is not running, and those have completely different fixes. It is the first row of §11.

`?demo=1` on the scoreboard is the companion check: it walks every display state from a single fetch, with no polling and no server cache in the path, so a frozen demo indicts the device and a moving one clears it.

## 7. Leveraging what Live! already renders

The elaborate cards — bracket, standings, spirit, team results — are exactly the pages Live! already has. Rebuilding them as overlay cards is the expensive path.

The cheap path is an `embed` card: an iframe pointed at an existing Live! view, scaled and cropped into a slot. That could get bracket, standings and spirit onto the stage for roughly the cost of the iframe plus some CSS.

**Both of its preconditions are unverified**, which is why it is no longer an MVP card (§9):

- Live! pages are built for a white page, not for compositing over video. They need a dark or transparent skin — which may mean a query parameter Live! does not have, and **`PLAN.md`'s constraint is that no file shipping in the Live! zip may be edited.** So the skin has to be applied from the overlay side (an injected stylesheet on a same-origin iframe) or not at all.
- `SecurityHeaders.php` sends framing headers from `live/api.php`. `PLAN.md` records that those do not reach arbitrary views, which is what makes same-origin framing viable — but that was established for overlays, not for framing Live!'s own pages, so it needs re-checking for this specific use.

If same-origin styling turns out not to work, `embed` degrades to "show the page as-is in a slot", which is still useful for a full-screen bracket takeover between points.

## 8. Easy mode — no operator

A tournament will often have nobody to run this. Easy mode is not a separate system: it is a show state that nothing writes by hand.

`stage.php?auto=1` (and the default when `show.json` does not exist) runs a rule-based director in the client:

- scoreboard always on, `lower-left`
- its callouts are already automatic — the standing ON DEFENCE tag and the HOLD/BREAK flash need no operator
- half summary for ~15s when a `half_cap` event first appears
- final card when the game goes to `completed`

That is a small state machine over data the stage already polls, and it means the same URL works with or without an operator. The operator UI simply overrides it: any manual show state wins until it is cleared.

## 9. MVP — built

Smallest thing that proves the architecture rather than a piece of it. All six steps are done; what follows describes what exists rather than what to do.

| # | piece | file | state |
|---|---|---|---|
| 1 | Show-state store — validate, atomic write, `rev` check | `shared/show.php` | ✅ modelled on `shared/colors.php` |
| 2 | Static read path, no PHP (§6) | `conf/show.json` | ✅ served directly by Apache |
| 3 | Stage — slot grid, two mount kinds | `stage.php`, `shared/stage.css` | ✅ |
| 4 | Cards: `scoreboard`, `topplayers` | in `stage.php` | ✅ |
| 5 | Control, folded into the existing page | `index.php` (`/s/`) | ✅ public read-only, controls when admin |
| 6 | Auto mode — scoreboard only, no file needed | `Show::defaults()` | ✅ |
| — | Write endpoint for the control UI | `show.php` | ✅ 403 unauthenticated, 409 on stale `rev` |

**Two mount kinds, which was not in the original sketch.** The scoreboard is already a complete self-contained overlay, so the stage mounts it as an **iframe** rather than re-implementing it — that would have duplicated several hundred lines and given the two copies room to drift. `topplayers` is rendered **inline** by the stage from the payload it already polls. The card table in §2.2 says which kind each card is; `embed` (§7) will reuse the framed path.

**One ordering trap worth recording.** The show-state poll always wins the race against the first game payload, so an inline card cannot mount on the first pass. Re-render must therefore re-apply the *whole* show state when a payload arrives, not just refresh already-mounted slots — iterating the mounted set silently skips exactly the cards still waiting to appear. Framed cards tolerate the repeat because mounting short-circuits when the frame it would build is already there.

**On the second card.** `topplayers` rather than `embed`, deliberately. `embed` looked cheapest but its two preconditions are both unverified (§7), and if either fails the MVP collapses to "stage plus the scoreboard we already had" — which proves nothing about hosting *multiple* cards. `topplayers` runs on data confirmed present (`players[].total`, a sort with nothing to derive), needs no framing, and exercises the same mounting path. It also happens to be a card worth having.

Explicitly **not** in the MVP: `player`, `halfsummary`, `bracket`, `spirit`, `embed`, drag-and-drop, transitions, preview. Each is a card added to a working stage afterwards.

**Order matters:** step 3 (stage + scoreboard, no control at all) is independently useful and independently verifiable. If the switcher turns out not to run JS, the whole plan stops there and nothing after step 3 is wasted.

### 9.1 Auto mode: a card that shows itself

The last-play cards are *about an event*. A scoreboard has something to say continuously and a stat card has something to say whenever anyone looks, but "last goal" is worth exactly the few seconds after a goal and is clutter for the rest of the point. Leaving it up permanently is wrong; asking an operator to click it on and off every point is worse, and in auto mode there is no operator at all.

So `params.auto` makes a card show itself at the moment it is about. There are four such moments, and they are not all the same shape:

| trigger | shape | default | cards |
|---|---|---|---|
| `goal` | event — a window opens and shuts | 15s | the last-play family |
| `halftime` | event, on the `half_cap` | 30s | `halftime`, `topplayers` |
| `final` | event, when the game stops being live | 45s | `postgame`, `topplayers` |
| `pregame` | **state** — true until the first pull | none | `pregame`, `topplayers` |

`for: null` is what separates the two shapes, and getting it wrong is not subtle: a pre-game card with a duration would vanish fifteen seconds in and leave the frame empty until the game started. Stored as `{"on": "halftime", "for": 30}`; the older `auto: true` still means fifteen seconds after each goal, so nothing already configured has to be rewritten.

Each card is offered only the moments that suit it. A last-goal card is about a goal and is clutter for the rest of the point; the summary cards have exactly one moment each, and offering them the others would only be a way to put a half-time card on air at full time.

Fifteen seconds for a goal is long enough to read two names off a screen while still watching the game, short enough to be gone before the next pull.

**Auto is a mode of being on air, not an alternative to it.** The card is still switched ON AIR, which is what reserves the slot and keeps the store's one-visible-per-slot rule meaningful; auto only decides whether an on-air card is currently painting. "On air" keeps meaning *this card owns this position*, and auto adds *and it speaks only when there is something to say*. Anything else would need the exclusivity rules rewritten so that a card flickering into view could displace a neighbour without an operator touching anything.

Three details that are not obvious:

- **Only a goal being added counts.** A scorekeeper correcting an attribution rewrites an existing entry, and re-showing the card for that would put a graphic on air for an edit nobody watching can see the reason for. The trigger is the goal *count* rising.
- **The first payload only sets a baseline.** Without that, every stage would flash the last goal of the match the moment it loaded — the same first-paint trap the scoreboard's score animation already had to be taught to avoid.
- **A timer closes the window, not a poll.** The show poll only calls `applyShow()` on a `rev` change and the game poll is twice as slow as the window, so without an explicit `setTimeout` the card would simply stay up.

**On latency.** The card appears when the *stage learns* of the goal, which can be up to 30 seconds after it happened (`CACHE_SECONDS_GAME_DETAIL_ONGOING`). That is not a defect to engineer around: the scoreboard is on the same poll, so the card appears at the same instant the score ticks over on screen. The two are consistent with each other and with what the viewer sees, which matters more than either being early. Measured end to end against a real goal: card up at t+42s, down at t+57s — 15 seconds exactly.

### 9.2 Two controls that are not per-card

Both live in the bar under the card list, and both exist because the per-card switches are the wrong granularity for the two mistakes an operator actually makes.

- **↶ Undo**, one level. The reversible mistakes are "I just displaced something" and "I switched off the wrong card", and both are the immediately preceding action. A deeper stack would be a worse trade: an operator mid-game will not reason about how far back they are.
- **⏻ All off air.** A graphic is wrong, or the director calls for a clean picture, and the fix has to be one click rather than one click per card. It clears *visibility only* — placement is left alone, so every card stays set up and preloaded (§2.6) and putting the stage back is as fast as switching them on again. Undo restores the whole frame, which is what makes the button safe to reach for without thinking.

  It is disabled when nothing is on air, so it never reads as available when it would do nothing, and it gets the same hover treatment as a displacing click (§2.7): the cards it is about to take off air are flagged before the click, not after.

### 9.3 The three cards that are not about a goal

`pregame`, `halftime` and `postgame` are **one renderer with three framings**, because they are the same question asked at different times: who are these teams, and what has happened so far. Three separate renderers would have meant three places to fix a layout and three chances for them to disagree.

Each shows both sides with crest, seed and record, the score once there is one, and breaks per team. Breaks rather than holds, for the reason in §3.2 — every point is one or the other, so they sum to the score and holds carry no information breaks do not. They come from `classifyPoints()`, the same function the scoreboard uses, so the two can never disagree about what a break was, and `unresolved` is shown whenever it is non-zero rather than quietly counted as a hold.

**Card order on the control page is the store's `CARDS` order, and that is load-bearing.** The scoreboard and the strips that ride with it share the frame and need positioning relative to each other, so they are grouped at the top where an operator coordinating positions is looking. Everything that takes the middle of the frame or the whole of it sits below, because it has nothing to coordinate with. Reshuffling that list for tidiness would undo the grouping.

**What the deleted pre-game stub wanted, and this does not do yet.** `statistics/pregame.php` sat in the tree for months as a 501 returning stub with four hundred lines of markup below the `return`, kept as "the starting point for the Phase 2 port". The port happened by a different route — these cards — so the stub is gone, but it asked for four things the summary cards do not show, and they are worth keeping on the list:

| wanted | reachable today? |
|---|---|
| Scoring averages — goals for, against, margin | yes, from `entity=teams` across the tournament |
| Hold and break **percentages** rather than counts | yes, `classifyPoints()` already returns the parts |
| Breaks converted, as *n* of *m* | no — the denominator is break chances, which needs possession (§3.4) |
| Spirit scores, per category, both teams | yes, `entity=spirit`, subject to the visibility rules |

The first two are small additions to the existing card. The third is blocked on the same missing data as everything else about possession. The fourth is a card of its own, and putting spirit numbers on air deserves its own decision rather than arriving as a row in a stats block.

### 9.4 Deterministic rendering, for a game nobody switched

    ?at=<clock seconds>&goals=<count>&phase=pre|live|final

The scoreboard fetches its payload once, renders a single frame of the state it was handed, and stops — no polling, no timers, nothing that makes two runs differ. This is what lets a game recorded without a switcher get the same overlay added afterwards, frame by frame.

**It is deliberately dumb, and that is the design.** It does not work out which goals have happened by the time given. In post-production that question is not the overlay's to answer: a recording is aligned to the game by anchors an operator supplies, and plenty of tournaments record no goal times at all (`hide_time_on_scoresheet`, §3.1). Keeping the alignment entirely outside means the overlay renders identically whether the answer came from UltiOrganizer's timestamps or from somebody scrubbing a video — and there is exactly one renderer, so a post-produced overlay cannot drift from the live one.

The whole mode is "hand `render()` a truncated payload". One trap found by measuring rather than reading: `render()` recomputes a server skew from `meta.generated_timestamp`, so a three-second-stale cache made `at=600` draw 9:57. Offline there is no "now" to be skewed against, so that field is stripped.

### 9.5 The stage configuration as a file

Export and import sit in the stage bar. Three jobs, and the third is why it earns its place: carrying a setup from one field to the next, keeping a known-good arrangement in a repository rather than in an operator's memory, and **being the input to post-production**. The card set and auto timings a broadcast used live are the ones its recording should get, so authoring them twice would only be a way to make them differ.

The document omits `rev`, which belongs to one store, and `game`, because a layout is reusable and a game id is not — so importing never moves the stage to another game. Imports go to the store like any other write, and the store drops what it does not recognise, so a file that has been on a USB stick cannot produce a frame the control page could not have produced.

## 10. Gaps and upstream asks

Everything below was established against real payloads and the UO schema while building the scoreboard, not guessed. The useful column is the last one: it separates "flip a setting" from "needs upstream feature work", and those are wildly different asks.

### 10.1 Cheap — a config toggle or a directory

| gap | what it unlocks | where the fix lives |
|---|---|---|
| **Per-player blocks** off by default | a GOALS / ASSISTS / **BLOCKS** player card | Resolved for tournament totals: turn on the `ShowDefenseStats` system setting and `deftotal` appears on the `entity=teams` roster row (§3.1). Confirmed by measurement. It does **not** reach the *game* payload, so per-game blocks remain open, and §4 still applies — a display toggle does not make the data complete |
| **Player photos** — no image field anywhere in Live! | any player card worth putting on air | overlay-side `live/overlays/players/<player_id>.jpg`, mirroring `shared/logos.php`. No upstream change, upgrade-safe |
| **Team photos** off | fallback team imagery | `TEAM_PHOTOS_ENABLED` is `false` in `live/conf/local-config.json` |
| **Countdown target usually unset** | the clock silently counts up instead of down | `uo_pool.timecap` (minutes) is per-pool and simply not filled in for most pools. A tournament that sets it gets countdowns for free |

### 10.2 Moderate — needs a UO core change

| gap | what it unlocks | why it is not cheap |
|---|---|---|
| **Possession changes are not recorded** | break chance, clean holds, turnover counts, offensive efficiency | no turnover table exists. `uo_defense` records *blocks* only, so an unforced throwaway or drop leaves no trace. Root cause of four separate things — see §10.4 |
| **No per-event capture policy** | telling *zero* apart from *not tracked*, which every adaptive card depends on | an `EVENT_SETTING` cascading to pool and game — see §4.2 |
| **No explicit halftime event** | exact timeout-allowance reset, accurate half splits | only `half_cap` exists, which is when the halftime *cap* was called. Close enough today, but not the same fact |
| **No soft/hard cap distinction** | showing "game ends at this horn" vs "play to a new target" | UO models every cap as a new point cap, which is soft-cap behaviour. A hard cap is only expressible as a target equal to the current score |
| **No team colour** | kits without a manual picker | `uo_pool.color` and `uo_series.color` exist; `uo_team` has none. Worked around with `conf/team-colors.json` |
| **Timekeeper caps not bound to a game** | a countdown target when the pool has none | `uo_timekeeper_template.half_time_cap` / `time_cap` (55/100 min) live in a standalone app, chosen in the operator's browser, never attached to a game and never in the API |
| **No line/roster-on-field data** | "who is on this point" graphics, which is a genuinely novel overlay | not recorded at any level |

### 10.3 Operational, not data

| gap | what it costs |
|---|---|
| **Clock depends on the scorekeeper starting it** | `timer_start` is only written by Scorekeeper. A scorekeeper who never starts the clock means no clock on air, and the overlay silently falls back to a status word. Worth a pre-game checklist item |
| **Flat 30s server cache on game detail, and no invalidation hook** | nothing wipes a game's cached payload when a goal is recorded, so mean score-to-screen is ~15s of pure waiting. `CACHE_MINUTES_MODULATOR` is **not** the lever — upstream says do not lower it. The fix is an overlay-side uncached score endpoint (§6) |
| **Fixture is two teams, one pool** | progression, standings and spirit cards cannot be exercised or verified locally at all. This blocks Phase 2 more than any missing field does |
| **No UI test framework in the repo** | no Playwright/Cypress/Puppeteer anywhere, and the sibling harness is PHP with only HTTP-level `smoke`/`crawl`. The `demo=1` walk-through plus `--dump-dom` assertions is the closest thing to a regression test and is worth formalising |
| **`entity=statistics` returns 400 without an id** | unexplored; may carry tournament-wide leaders that would make the pre-match card much better |

### 10.4 Feature request: record possession changes

The single change that would most improve broadcast overlays, recorded as one coherent ask rather than four separate ones.

**What:** a timestamped record of each change of possession within a point. This splits into two levels worth keeping separate, because they cost very different amounts to capture:

| level | records | unlocks |
|---|---|---|
| **A — team only** | which team gained the disc, and when | break chance, clean holds, turnover counts, offensive efficiency |
| **B — attributed** | plus the player who lost it, and whether it was a throw or a drop | a per-player turnover stat, the offensive counterpart to blocks |

**UltiOrganizer already anticipated both.** `uo_player_stats` carries `offence_turns`, `defence_turns`, `offence_time`, `defence_time` and `breaks` — and every one is hard-coded to `0` in `lib/statistical.functions.php:468-472` before being written. The columns exist, the aggregation write path exists, and the values are literal zeros. So this is completing scaffolding already in the schema, not introducing a new concept. `uo_defense.author` already attributes a block to a player, so level B would give the symmetric pair: discs won on defence, discs lost on offence.

**Why it cannot be inferred:** `uo_defense` captures blocks, which are only the subset of turnovers the defence forced. A throwaway or a drop changes possession and leaves no trace anywhere in the schema. There is no table, column, or event type from which the rest can be derived — genuinely absent data, not data that is merely hard to reach.

**A and B are not the same ask.** A is one button pressed by someone already watching. B needs a player identified in real time, plus a judgement about whether a failed pass was a throwaway or a drop — conventionally charged to different players. A broadcast operator watching a camera feed cannot do that reliably. **Level A is the one to pursue first**, and it delivers four of the five items on its own.

**On who would capture it.** The obvious objection is scorekeeper burden, and it is fair: a single point can contain many turnovers, and the scorekeeper is already tracking goals, assists, timeouts and the clock. Asking them for possession too is likely to produce bad data or slow the scoresheet down.

But it does not have to be the scorekeeper. **The broadcast operator is already watching every possession** in order to decide what to put on screen, and one "turn" button in the control UI (§2.5) would capture it as a side effect of work they are doing anyway:

- It burdens nobody new — the operator is engaged with the game continuously.
- It is *overlay-local* data, not an official record, so a missed press degrades a graphic rather than corrupting a result. The accuracy bar is far lower than the scoresheet's.

**Collected-but-discarded is the failure mode to avoid.** A spotter tracking the disc for a whole game produces something genuinely valuable — every turnover, every possession, arguably touches per player. If that lives only in a `conf/` file that the next tournament overwrites, the effort is spent once and thrown away, and every event re-collects from scratch. That is a poor trade for a person's full attention across a game.

So persistence is part of the ask, at three levels:

| level | what | cost |
|---|---|---|
| **Keep it** | one file per game, not one shared file — never overwritten by the next game | trivial, and should exist from the first version |
| **Export it** | a download of the possession stream per game, so a team or a statistician can use it | small |
| **Upstream it** | the events land in UltiOrganizer, where they join the scoresheet's own record and reach everyone | the real request |

The third is the point. Overlay-local capture proves the workflow is feasible and produces something useful immediately, but the data belongs in UO: that is where it survives the broadcast, becomes available to teams and season statistics, and stops being a broadcast by-product. Persisting locally is the bridge; it is not a substitute, and building only the bridge would leave the data stranded on a laptop in a field.

One caveat that comes with persistence: a stored per-player possession stream is data about identifiable players, so once it is kept rather than discarded it falls under the same privacy handling as the rest of the player record (`COMMENTATOR.md` §5) — export, anonymisation, deletion — and should carry the capture-policy declaration from §4.

**But the operator route is a bridge, not the answer.** Their attention is not spare capacity — directing a multi-card broadcast is the job, and possession capture competes with it hardest during exactly the games that most deserve good graphics. Keeping stats collection with the scorekeeper and leaving the operator free to decide what to show is the better division of labour, and it is why this remains a genuine upstream request rather than something the Studio quietly absorbs.

Scorekeeper-captured possession is also better on every axis except build cost: it exists for every game rather than only broadcast ones, it persists into `uo_player_stats` and season stats, it is authoritative, and it serves consumers that have nothing to do with overlays.

So: build the Studio version (§3.5) because it works today and proves whether the feature earns its keep; pursue the upstream log because that is where the data belongs. Per §2.5 and §3.3 the overlay prefers real possession data whenever it appears, so the two are a graduation path rather than competing designs. An overlay-captured stream must never be written back into the scoresheet. Level B stays with the scorekeeper or a dedicated stats keeper regardless, because a per-player turnover count assembled from guesses is worse than no column at all.

Two further considerations:

- A turnover count is a *negative* stat about a named, identifiable amateur athlete on a public graphic. That deserves a deliberate decision rather than shipping because the field exists.
- **Whichever level is built needs a capture policy (§4) from the start.** Blocks are the cautionary example: the data has existed for years, but because nothing declares whether a given event records it, a zero cannot be distinguished from an absence and the stat cannot safely be aggregated. Repeating that with possession would waste the whole feature.

**Not built, and deliberately so: an export of the possession log.** The log the Studio keeps is a genuinely interesting dataset — it is the only record anywhere of when the disc changed hands — and there are real uses for it beyond a live tab: adding accurate break-chance graphics to a recording in post-production, and analysis that has nothing to do with broadcast at all. A JSON export would take an afternoon.

The reason to resist it is that an export would make the wrong home permanent. Once people build against a file the overlays emit, that file becomes the interface, and the case for putting possession where it belongs — in UltiOrganizer, captured by the scorekeeper, persisted alongside every other game fact — gets quietly weaker every time someone works around its absence. The log here is scoped to a broadcast, ephemeral by design, and authoritative for nothing; an export would invite it to be treated as none of those things.

So the note stands as a want, not a task: **possession data should land in UO, not in an overlay-side export.** If the upstream log arrives, the export is unnecessary. If it does not, and the demand for the data turns out to be real and recurring, that demand is the argument for the upstream feature rather than a reason to route around it. Worth revisiting only if post-production or analysis needs it before UltiOrganizer does — and even then as a documented stopgap with an expiry, not an interface.

### 10.5 Feature request: record gender matching (FMP/MMP) on the roster

**What:** a per-event designation of each player's gender matching — FMP or MMP — recorded on the roster for *this tournament*, alongside a structured division type so "is this mixed?" does not have to be guessed.

**Why the existing field is not it.** UltiOrganizer does model the ratio *rules*: `cust/wfdf/pdfscoresheet.php` carries a `gender_rule` of "A" (prescribed) or "B" (endzones decide) and prints "4M/3F"-style requirements on the scoresheet. But it detects a mixed division by **matching the word "mixed" in its name** (`:141`), and it never records which player is which — it prints the rule, not the roster.

`uo_player_profile.gender` is the obvious candidate and is the wrong one:

| | |
|---|---|
| It offers **F / M / O** (`user/playerprofile.php:247-269`) | "Other" has no meaning in a matching ratio, so the field cannot answer for every player |
| It sits behind `privacyselection()` | a player may withhold it, so a split would reflect who opted in |
| It is a personal-identity field | matching is a *competition* designation a player registers under, which is exactly the distinction the FMP/MMP terminology exists to draw |
| Nothing in the codebase reads it this way | the scoresheet computes ratios without ever consulting it |

Deriving matching from it would be both a category error and unreliable.

**What it unlocks:**

- Gender-balanced top-scorer cards. The overlay is **already built for this** — `topBalanced()` in `stage.php` splits evenly the moment a `matching` field appears, and falls back to a plain ranking until then. No overlay change needed.
- FMP/MMP labels wherever players are listed.
- The ability to report FMP scoring contribution at all. Without it, a top-scorer card in a mixed division can show four MMPs and silently erase the FMP half of the game — a well-known failing of ultimate coverage that this data would let us avoid rather than reproduce.

**FMP/MMP, never F/M/O.** The designation must be recorded in the sport's own terms rather than as a personal-gender letter. F/M/O does not answer the question a ratio asks — "O" has no matching at all — and mapping identity letters onto matchings is precisely the category error the terminology exists to prevent. A source that cannot say FMP or MMP is not supplying this data. The overlay enforces that: `matchingOf()` in `stage.php` accepts those two values and nothing else.

**Show nothing outside mixed.** In open and women's divisions every player shares one matching, so a label carries no information and a balanced split has nothing to balance. Both are suppressed unless the division type is `mixed` — already implemented, keyed on `teams.*.type` rather than on matching the word "mixed" in a division's name as the scoresheet does.

**Treat it as capture-policy data (§4).** It is only meaningful in mixed divisions, so it needs the same per-event declaration as blocks: an event that has not recorded matchings must be distinguishable from one where every player happens to be unset.

**And apply the pronouns caution (`COMMENTATOR.md` §5) to publication.** A roster matching is a competition fact rather than a statement about identity, which is why collecting it is legitimate where inferring pronouns is not — but it is adjacent enough that it should be published deliberately rather than by default.

### 10.6 Feature request: a per-tournament logo

**What:** an event-level logo on `uo_season`, so a logo belongs to the tournament rather than to the installation.

**What exists.** Live! has three logo paths, all in `local-config.json` and all **installation-wide**: `HOME_LOGO_PATH` (a home-page mark), `SOCIAL_SHARE_LOGO_PATH` (a 1200×630 share card), and `TV_SCREEN_LOGO_PATH` — described upstream as the logo for a TV screen header, already a wide banner at 336×102, and already served through `entity=config`. That last one is what the stage uses today, and it is a good fit for the shape. **UltiOrganizer core has no logo at all**: `uo_season` carries no logo, image or banner column.

**Why installation-wide is not quite right.** One UO installation can host several tournaments, and they do not share branding. It works today only because a Live! deployment is pointed at a single event by `LIVE_SEASON_ID`, so "the installation's logo" and "this tournament's logo" happen to coincide. An installation serving more than one event has no way to differ.

**Decision: one logo, not two.** A tournament mark and its governing federation's mark are both wanted, and showing both eats width that a 1920-wide frame does not have spare — the corner is already shared with a scoreboard or a stat card. So the overlay displays exactly one image, and an event that needs a combination supplies a **combined image made to fit the normal space**, prepared once for that tournament. That keeps the layout predictable and puts the design decision with whoever owns the branding, rather than having the overlay attempt a lockup at runtime and get the proportions wrong.

That in turn is the strongest argument for the per-event field: a combined tournament-plus- federation lockup is inherently specific to one event, so the place to store it is the event.

**Position is already solved locally.** Which corner the logo occupies is overlay state (`conf/show.json`, set in the Studio), because it is decided once for an event and then left alone. It is deliberately not a URL parameter: every switcher would have to carry it and every operator would have to remember it. The overlay keeps it clear of anything sharing its corner by measurement, so it needs no coordination with card placement.

### 10.7 Out of scope: picks, fouls, and similar

Named so it is a considered exclusion rather than an oversight.

Calls and stoppages — picks, fouls, travels, contested calls — would make genuinely novel overlays, and nothing comparable exists in ultimate broadcasting. They are also the clearest case of **capture cost exceeding value**: frequent, often ambiguous, sometimes contested and then retracted, and recording them accurately is a full-time job for a person doing nothing else. Loading that onto a scorekeeper would degrade the data they are already responsible for.

Unlike possession, these have no obvious no-cost capturer either: the broadcast operator cannot reliably see a pick call from a camera position, and a contested call has no single truth to record.

**Out of scope, and not merely deferred.** Revisit only if a tournament supplies a dedicated stats keeper — the staffing model higher-level broadcasts in other sports use, and the only one under which this data is realistic.

### 10.8 If only one thing changed

**Record possession changes** (§10.4). It alone unlocks the complete break-chance indicator, clean holds, turnover counts and offensive efficiency, and it is the only item here that cannot be derived, configured, or worked around from the overlay side. Everything else is a config flip, a directory of images, or a nice-to-have.

The columns for the attributed version already exist in `uo_player_stats`, written as hard-coded zeros — so part of this is finishing something, not starting it.

A "turn" button in the control UI (§3.5) is the fastest route to *seeing whether the feature is worth having*, and it needs no upstream change at all. It is not a substitute for the request: capture belongs with the scorekeeper, so that the data exists for every game rather than only broadcast ones, and so the operator stays free to do their own job.

## 11. Risks

| risk | why it matters | how to settle it |
|---|---|---|
| Device does not run JS continuously | kills every dynamic overlay, not just this plan | `tests/selftest` and `?demo=1` on the hardware — do this first (§6) |
| 1s show-state poll through PHP | `LogPageLoad()` writes a `uo_pageload_counter` row per routed request — 86,400/day/stage would wreck admin stats | settled: `show.json` is served statically, no PHP in the read path (§6) |
| Reaching for WebSockets / a Node rewrite | would optimise the ~0.5s show-poll term while the 30s game cache and the human scorekeeper dominate | evaluated and rejected in §6, with the thresholds that would change the answer |
| Live! pages cannot be skinned in-frame | `embed` degrades to as-is | check framing headers on a real Live! view (§7) — already why `embed` left the MVP |
| Blocks incomplete and indistinguishable from zero | leaderboards silently rank scorekeeper diligence | capture policy (§4); interim all-zero heuristic |
| No player photos in Live! | the nicest card has no art | overlay-side `players/` store, mirroring `logos.php` (§5) |
| An asset still decoding when a card shows | it pops in half-drawn, on air | arm/show lifecycle with `img.decode()` (§2.6); never block a card on a failed asset |
| **Diagnostics are painted onto the broadcast canvas** | a bad game id in the browser-source URL puts white *"Invalid ID" / "No game data"* over the live picture, and the same happens on air after five consecutive poll failures — `showError()` hides the scoreboard and shows the message in its place. Measured on `/s/999999`: three white text nodes, fully opaque, at (1377,18), (92,688) and (92,719). The connection indicator behaves the same way, appearing whenever the poll is unhealthy | **Unresolved — a policy call, not a bug to quietly patch.** The error text is genuinely useful while setting a source up on a laptop and unacceptable once the source is live, and the page cannot tell those apart. Three options: gate all diagnostics behind an explicit `?debug=1` and default the canvas to blank; or keep them only until the first successful paint (the `painted` flag already exists) so a working overlay is never *replaced* by an error, which fixes the mid-broadcast case but leaves the bad-id case; or accept it and make "check the overlay on a laptop first" a checklist item. Worth deciding before a real broadcast |
| Show state edited by two operators | last write wins, silently | `rev` check on write (§2.4) |
| Live! upgrade | overwrites the tree | unchanged rule: nothing outside `live/overlays/` |
