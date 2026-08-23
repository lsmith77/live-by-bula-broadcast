# Commentator — a second-screen information surface

**Status:** plan, nothing built. `PLAN.md` owns the scoreboard; `STUDIO.md` owns the stage and what goes on air. This document covers a third consumer: the person **talking** over the broadcast, who needs information rather than graphics.

**Written:** 2026-08-22

---

## 1. Why this is not part of the Studio

The Studio decides what a viewer sees. The Commentator page decides what a commentator *knows*. Nothing it displays goes to air, and that single fact inverts most of the constraints the rest of this system works under:

| | Studio / overlays | Commentator |
|---|---|---|
| Output reaches viewers | yes | **no** |
| A wrong number is | a false statement on air | something a human discounts out loud, or ignores |
| Incomplete data is | usually unusable (§4) | usable, if labelled |
| Latency budget | ~1s for a click | seconds; nobody is cutting to it |
| Needs the arm/show lifecycle | yes — a half-drawn card is a failure | no |
| Auth | writes gated, overlay open | read-only throughout |
| Density | as sparse as possible | as dense as a person can scan |

**The practical consequence is that this is cheap.** It is a read-only page over an API we already poll, with no broadcast risk, no atomic-write store, no operator contention and no preload discipline. It is the least dangerous thing in the project and probably the highest ratio of usefulness to effort.

It should also be a **separate page on a separate screen**, never a panel inside the Studio: a commentator and an operator are usually two people, and even when they are one person the two jobs want opposite layouts.

## 2. The data situation is much better here than for graphics

Everything the overlays cannot show — blocks, turnovers, possession, clean holds — is about **aggregate statistics** (`STUDIO.md` §3, §4). Play-by-play is a different matter, and it is complete.

Each entry in the `goals` array of the game payload already carries:

| field | |
|---|---|
| `scorerfirstname`, `scorerlastname`, `scorernum` | who scored, with jersey number |
| `assistfirstname`, `assistlastname`, `assistnum` | who assisted |
| `time` | seconds into the game |
| `homescore`, `visitorscore` | running score after this goal |
| `num`, `ishomegoal`, `iscallahan` | sequence, side, callahan flag |

That is a full play-by-play feed, named on both ends of every goal, in a payload the overlays fetch anyway. No new endpoint is needed for the bulk of what a commentator wants.

**And the incompleteness that blocks a graphic does not block a commentator.** A block count that depends on whether that game's scorekeeper recorded Ds (`STUDIO.md` §4) is unusable on a lower-third, because a viewer cannot know it might be short. The same number shown to a commentator, labelled *"3 recorded — may be incomplete"*, is perfectly usable: they are a domain expert who can discount it, and they can simply choose not to say it. **Show it with the caveat rather than withholding it.**

## 3. What professional systems do

General industry shape rather than a specific product — treat this as orientation, not a specification; none of it has been verified against a particular vendor's tool.

Broadcast statistics providers supply commentary teams with a "spotter" or "commentator" feed, and the recurring elements are:

- **Pre-match notes** — head-to-head history, form, seeds, what is at stake.
- **A live event feed** — the play-by-play, in reverse order, scannable at a glance.
- **Player cards on demand** — searchable, with season and match figures side by side.
- **Milestone and streak alerts** — *"two more assists for the tournament lead"*, *"fourth consecutive point won"*.
- **Storyline prompts** — prepared talking points, surfaced when relevant.

The important lesson is what commentators actually use. **They do not read tables on air.** They need *talking points*: a streak, a milestone, a first meeting since a notable game, a run of play. A page that answers "what is interesting right now?" beats one that answers "what are all the numbers?" — and the second is much easier to build by accident.

## 4. What is derivable today, without any upstream change

All of the following come from the goal list, `entity=teams` and `entity=reference`, all already reachable:

**Run of play**
- Scoring runs — *"four of the last five"*, with the names.
- Time since the last goal, and pace against the cap.
- The hold/break sequence, from `classifyPoints()` in `shared/overlay-client.js`.
- Longest run by either side this game.

**People**
- Every player's goals, assists and combined total for this game, from the goal list directly.
- Tournament totals from `entity=teams` → `players[].total`, plus per-game averages.
- Who is connecting with whom — thrower/scorer pairs are named on every goal, so a recurring connection is countable. This is a genuinely good commentary hook and costs nothing.
- Callahans, flagged per goal.

**Teams**
- Record, points for and against, difference, seed — all in `teams.*`.
- Pool standing from `pool_placements` in `entity=reference`.

**Context**
- Field and venue, via `game.reservation` → `reference.reservations[]`.
- Cap state and the current point cap, from the `half_cap` / `time_cap` game events.
- Timeouts remaining per side, with the halftime reset.

**Tournament block totals: built.** `deftotal` on the `entity=teams` roster row, once an administrator turns on `ShowDefenseStats` (`STUDIO.md` §3.1). The roster shows a `Blk` column and offers a Blocks sort — but only where the field is actually present, so an installation that does not track defence sees neither rather than a column of zeros. Completed games only, which the page says out loud. This is exactly the asymmetry §2 predicted: the same number that is too incomplete for a graphic is useful here, because a commentator can qualify it and a lower third cannot.

**Blocked, and honestly so:** anything needing possession or turnovers (`STUDIO.md` §10.4), and tournament-wide block *leaderboards* (`STUDIO.md` §4) — a ranking asserts completeness the data does not have. Per-*game* block counts are a separate gap: they are in no payload, and reaching them through `GameTeamDefenseBoard()` still waits on the direct-database question.

## 5. Referring to people correctly: numbers, pronunciation, pronouns

**Jersey number is the primary index, not player name.** A commentator sees a number on a shirt and needs the name in the time it takes to say it. Every other lookup is secondary. A page whose rosters are sorted by goals — the obvious way to sort a stats table — is the wrong shape for the job it is actually doing.

Three consequences:

- **Sort rosters by number, always**, with statistics as columns rather than as the ordering.
- **Numbers are per team, not unique.** Both sides can field a #7, so a number alone is ambiguous. Both rosters must be visible at once, side by side and separately labelled, and any number-driven search must resolve within a team rather than across the game.
- **The whole roster must be listed, not only the players who have done something.** A commentator needs #14 before #14 has scored.

**The full roster is available.** `TeamScoreBoard()` selects `FROM uo_player` and LEFT JOINs the goal counts with `COALESCE(done, 0)` (`lib/team.functions.php:820-824`), so `entity=teams&id=` returns every player on the team with `num`, and zeros rather than absence for those who have not scored. This was worth checking: had the API returned only scorers, number lookup would have failed for most of the roster and this section would be blocked rather than cheap.

### Pronunciation

**UltiOrganizer has no pronunciation field anywhere.** Neither `uo_player` nor `uo_player_profile` carries a phonetic spelling, and there is nothing comparable in the schema.

This matters more than a missing statistic. Ultimate is international — a WUCC roster mixes Austrian, Finnish, Colombian and Japanese names in a single game — and a commentator mispronouncing a player's name on a public broadcast is a small disrespect to that player, repeated every time they touch the disc. It is also the kind of error the audience notices and the player remembers.

**Build it as overlay-local state**, exactly the proving-ground pattern in `STUDIO.md` §2.5: a `conf/pronunciations.json` keyed by `player_id`, edited by whoever is preparing the broadcast, gitignored, surviving a Live! upgrade. Properties that make this the right first home:

- It is prepared *before* a game, not under time pressure, so a plain edit surface is enough.
- It is per-installation and low-stakes — a wrong entry is corrected in seconds.
- Nobody has to build it for it to be useful: a roster that shows a phonetic hint when one exists, and nothing when it does not, is useful from the first entry.

Free-text phonetic spelling (*"KLOH-ster-noy-burk"*) beats IPA, because the reader is a commentator under time pressure, not a linguist.

**It probably belongs upstream eventually.** A name's pronunciation is a property of the person, stable across events and useful to every tournament they attend — so `uo_player_profile` is its natural long-term home. That is precisely the graduation path §2.5 of `STUDIO.md` describes: prove it locally, and propose it upstream if the commentary teams actually maintain it.

### Pronouns

The same class of problem as pronunciation, and arguably a sharper one: how to refer to a person correctly, live, in public, at speed. Ultimate has mixed divisions as a core format and a large number of trans and non-binary players, so a commentator who guesses will get it wrong in front of an audience — repeatedly, since a player is referred to every time they touch the disc.

**Do not infer pronouns from `uo_player_profile.gender`.** That column exists, and using it here would be the single worst mistake this feature could make. It is division-eligibility data — it supports mixed-division ratio rules — and it answers a different question from "how does this person wish to be referred to". Deriving one from the other is precisely the mechanism by which people get misgendered on air, and it would do so automatically and at scale.

So:

- **Self-declared, never inferred.** The only acceptable source is the player, ideally through registration; a broadcast team filling these in by guesswork reproduces the problem it is trying to solve.
- **Free text, not an enumeration.** A short field holding `she/her`, `they/them`, `he/him`, `she/they` or anything else a player writes. A fixed dropdown will be wrong for someone.
- **Optional, and absent means absent.** No default, and no placeholder implying a value. This is the same "absent is not zero" rule as `STUDIO.md` §3.3, applied to a person rather than a statistic — and here the failure mode is worse than a wrong number.
- **When it is absent, use the name.** A commentator can carry a whole game on surname and jersey number without a single pronoun. The page should make that the obvious fallback rather than leaving a blank that invites a guess.

Same store as pronunciation (`conf/pronunciations.json` or a sibling), same reasoning for starting overlay-local, same graduation path — and that path is more concrete than it first appeared, because UltiOrganizer already has a player-owned profile with a consent mechanism.

### What registration already provides

Worth establishing, because it answers "who supplies this" better than any local store can.

| piece | what it is |
|---|---|
| `uo_registerrequest`, `uo_users`, `uo_userproperties` | **account** registration — userid, password, email, token. Not player data |
| `uo_enrolledteam` | **team enrollment** into a series: name, club, series, `userid`, `status`, `enroll_time`. Driven by `user/enrollteam.php`, gated by `seasoninfo.enrollopen` / `enroll_deadline`, both exposed by Live! (`live/api/ReferenceData.php:150`) |
| `user/teamplayers.php` | roster management — a team admin puts players on a roster |
| `user/playerprofile.php` + `uo_player_profile` | **a player-owned profile**: nickname, birthplace, nationality, throwing hand, height, weight, position, story, achievements, image |
| `uo_accreditationlog`, `uo_player.accredited` | accreditation — identity checking at the event, gated by `seasoninfo.require_accreditation` |

Two properties of that profile matter here:

- **Players can edit their own.** `hasEditPlayerProfileRight()` (`lib/user.functions.php:712-726`) grants access to `isPlayerAdmin($profile_id)` — the player themselves — as well as team, series, season and super admins. So there is already a surface where a player supplies their own data rather than having it guessed for them, which is exactly what pronouns require.
- **There is already a per-field consent whitelist.** `uo_player_profile.public` is a pipe-separated list of which fields the player agrees to publish, defaulting to `nickname|birthplace|nationality|throwing_hand|height|weight|position|story|achievements|profile_image`. A new field is not published unless the player adds it.

So the upstream home for both pronunciation and pronouns is `uo_player_profile`, self-edited, and published only through the existing `public` whitelist. The overlay-local store remains the right *first* step — it proves whether commentary teams maintain the data at all — but it is a staging area, not the destination.

### On the privacy burden

An earlier draft of this section argued that a local `conf/` file "carries none of that weight" compared with an upstream field. That overstated it. **UltiOrganizer already stores names, emails, birthdates, nationalities and photographs** — it is thoroughly within GDPR scope already, `docs/privacy.md` exists, and the root `AGENTS.md` already requires new player data to be covered by export, anonymisation and deletion, with tables classified in `docs/ai/privacy-coverage/tables.txt`. Adding a field to a record that already identifies a person is marginal: it joins an existing obligation rather than creating a new category. As soon as you hold a name, the regime applies.

The genuine nuance is narrower, and it is a design consequence rather than a legal claim: pronouns can *reveal* gender identity, which is commonly treated as more sensitive than a name. That argues for publishing strictly through the existing `public` opt-in — never by default — and for the field being deletable independently of the rest of the profile. Both are properties `uo_player_profile` already has, which is another reason it is the right home.

### Related profile data that exists but is not exposed

`uo_player_profile` already carries `nickname`, `position`, `throwing_hand`, `height`, `nationality`, `story` and `achievements` — genuinely good commentary material, and there is a `public` column controlling which of those are publishable. **None of it appears in any Live! entity payload**, so it is not reachable today, and `entity=players&id=<id>` returns `{"error":"Invalid ID"}` even for an id the list endpoint itself returned. That looks like a bug or an undocumented parameter rather than a design decision; it is unexplored and worth settling before assuming this data is unavailable.

## 6. Who is on the field

A commentator needs to know which seven are playing this point. Nothing in UltiOrganizer records it (`STUDIO.md` §10.2), so it has to be entered — and entering it is only realistic if it is fast and if two people can share the work.

### This is the first genuinely shared state in the system

Everything else here is read-only, and even the Studio's show state has one writer. A line selection has **several people writing at once**, which is a different problem and the main thing to design for.

The workflow makes it tractable. A commentary duo naturally splits — one per team, or one per side of the field — so **the state is stored per team and writes are scoped to one team**:

```json
{ "702": { "300": [3, 7, 12, 18, 22, 24, 31],
           "301": [1, 4, 9, 11, 15, 20, 27] } }
```

Two commentators who have divided the teams then touch disjoint data and cannot conflict at all. That is worth more than any locking scheme, and it falls out of how they were going to work anyway.

Where they *do* overlap — both editing one team — take last-write-wins and show it: a brief "changed by someone else" note beats a rejected write, because a commentator cannot stop to resolve a conflict mid-point. This is the opposite call from the Studio's `rev` check (`STUDIO.md` §2.4), and deliberately so: there, a silent overwrite changes what is on air; here it changes a private reference panel.

#### How two commentators find each other: a shared code, not a login

**Do not gate this behind the Live! admin session.** That credential exists to control what goes on air. A commentator wants to sync a private reference panel with a colleague; handing them broadcast control to do it is an escalation for no reason — anyone who could sync could also blank the graphics mid-point. Two different jobs, two very different blast radii.

**A shared code is the right shape, and it is worth naming what it is: a namespace, not authentication.** It answers "which shared session am I in", not "am I allowed in". Saying so plainly matters, because nobody should believe it is protecting anything.

That is acceptable *here* and nowhere else in this system, for the reason §1 gives: nothing on this page reaches a viewer. The worst case of a guessed code is that a stranger sees, or edits, a line selection on somebody's private reference screen. Compare show state, where a bad write changes the broadcast — which is exactly why that one *is* admin-gated. The gate should match the consequence, and the consequences differ by orders of magnitude.

Practical properties that make it fit the setting:

- **Zero setup.** Two people agree on a word in the minute before the game.
- **Works with nobody available to authorise it** — a tournament often has no admin at the field, which is the same reason `/c` and `/s/` are readable without a login at all.
- **Scopes naturally**: the room is the code plus the game id, so the same code at two fields does not collide.
- **Opt-in.** A commentator working alone needs no code and should never be asked for one; entering a code is what turns sharing on.

**One thing to guard, and it is genuinely new.** This is the first place in the whole system where an *unauthenticated* request writes to disk — everything else writable sits behind the admin session. On a tournament LAN that is a non-issue; on a public host it is a small abuse surface. So the store carries the boring defences: a short fixed charset and length for codes, a cap on players per team, caps on rooms, pruning of rooms untouched for hours, and a minimum interval between writes to one room.

**Two of those caps were wrong when first written, and the way they were wrong is worth keeping on the page**, because both are the same mistake: a limit that looks like a bound but is not one.

| cap | what it looked like | why it bounded nothing |
|---|---|---|
| `MAX_ROOMS_PER_GAME` | 40 rooms per game | `prune()` globs one game's files, and *any* positive integer is an acceptable game id — it has to be, since an unauthenticated endpoint cannot be given a database lookup to do on demand. So a caller walking `game=1, 2, 3 …` collects a fresh allowance of 40 each time. Measured: 41 distinct game ids produced 41 files and nothing pruned. Fixed by `MAX_ROOMS_TOTAL`, a bound over the whole directory. |
| players per team | `MAX_PER_TEAM` 30 | It caps one team's array, but `saveTeam()` *merges* a team into whatever the room already holds and nothing capped the number of teams. Measured: twelve POSTs with twelve team ids left twelve teams in one room, growing without limit one request at a time. Fixed by `MAX_TEAMS_PER_ROOM`. |

The general lesson for anything else unauthenticated here: **check what the attacker controls, not what the caller is expected to send.** Both caps were correct for the shape of a real request and irrelevant to the shape of a hostile one.

**Assignment should be explicit, not inferred.** A small "I am covering …" control, remembered per browser. Two people silently editing the same team is the one case that produces confusion, and naming who has which team removes it.

### Entry has to be fast

Lines change every point, and a point can end in fifteen seconds. So:

- **Number-first, as §5 establishes.** A grid of jersey numbers per team, tap to toggle. Not a dropdown, not a search field.
- **Seven is the target**, so show a count and flag over- or under-selection without blocking it — a pull can go out before the commentator finishes tapping, and a stubborn form is worse than a wrong count.
- **Keep the previous line visible.** Ultimate rotates O and D lines, but there is real overlap, and re-picking from the last line is much faster than from a full roster.
- **A new point clears nothing automatically.** Tempting, but a score arrives on a poll that may be seconds late (§8), and wiping a line the commentator has just entered is worse than leaving a stale one. Offer a one-tap "new line" instead, and mark the panel stale once the score moves.

### The floating spotter: tracking the disc, and getting turnovers for free

A commentary duo splits the teams; a third person — a **spotter** — can instead track **who currently holds the disc**. That single input is worth more than anything else discussed in these documents, because *possession changes fall out of it implicitly*. Nobody records a turnover: the disc simply passes from a player on one team to a player on the other, and that transition **is** the turnover.

**Why a spotter is the right person, where nobody else was.** Two candidates were considered for possession capture and both had the same flaw (`STUDIO.md` §10.4): the scorekeeper is already tracking goals, assists, timeouts and the clock, and the broadcast operator is directing — for both, possession is *additional* work competing with their real job. A spotter watching the disc is not doing a second job. That is the entire job. It is the first proposal where the capture cost is not stolen from something else.

**Record the turnover, not the holder.** Tracking who has the disc at every moment is *continuous state maintenance* — a tap every few seconds, all game. Recording turnovers is *discrete event capture* — a handful of taps per point. Same output, far less input, and the difference is not only effort:

| | tracking the holder | recording turnovers |
|---|---|---|
| Input rate | every pass | a few per point |
| A mistake | **propagates** — mis-tap once and every derived possession after it is wrong until corrected | **is isolated** — one missing event, nothing downstream corrupted |
| Throwaway vs drop | cannot distinguish: the thrower is the holder in both cases | **can** — the spotter picks who caused it |
| Extra yield | touches per player, possession time, chains | — |

The error-isolation property is the important one. State-tracking is unforgiving in exactly the conditions this runs under: a busy point, an interrupted spotter, a moment of looking away. Event capture degrades gracefully instead — a missed turnover is a gap, not a corruption.

And the attribution point reverses a limitation I had assumed was inherent. Deriving "who lost it" from holder-tracking always blames the thrower, because the thrower is the holder whether they threw it away or the receiver dropped it. A spotter tapping *who caused it* makes that judgement themselves — which is the same judgement conventional ultimate statistics ask a statistician for, and it is the only way to get it.

So the model is:

```
starting offence        already recorded, from the `offence` game event
  + turnover events     spotter taps: when, which team gained, optionally who lost it
  = possession          derived, never tracked
```

Possession state falls out of the sequence exactly as holds and breaks already do in `classifyPoints()`. Nothing needs to be maintained.

**Attribution is optional and separable.** The bare event — *a turnover happened, this team now has it* — already yields break chance, clean holds, turnover counts and offensive efficiency. Adding *who* costs one more tap and yields per-player turnovers. Ship the first without waiting on the second.

#### The spotter interface must be keyboard-driven and eyes-free

This is the design constraint everything else follows from: **a spotter is watching the field, not the screen.** Any interaction that requires locating something visually has already failed — by the time they look up, the thing they were recording is over. So the surface is a keyboard instrument, not a form.

That also argues it should be **its own minimal page, not a panel on the dense commentator view** (§1 makes the commentator page as information-rich as a person can scan). Those are opposite jobs: one is for reading, one is for typing without looking.

What "well thought out" means concretely here:

- **One unmissable key for the primary event.** The space bar is the only key a person reliably finds without looking. A turnover is the most frequent input, so it gets the space bar and nothing else does.
- **Do not ask which team gained the disc.** Possession alternates, so a turnover *flips* it — the team is derivable from the sequence, exactly as holds and breaks already are. Asking would double the input for information already implied.
- **Attribution is a follow-up, not a mode.** After a turnover, typing a jersey number attributes it; typing nothing leaves it unattributed and the event still counts. No confirmation, no dialog, no state the spotter could be stuck in without realising.
- **Use the line to disambiguate numbers.** With seven candidates (§6 above), most digits identify a player uniquely on the first keystroke, so entry can commit immediately instead of waiting to see whether "7" becomes "77". Without line data this needs a commit key and is meaningfully worse — another reason the two features belong together.
- **Undo must be a key, and must be reachable blind.** Mistakes are certain. Backspace, one level minimum, and it must not require finding a button.
- **Feedback must be audible, not visual.** A short click on an accepted event, a different tone on undo, silence on a rejected key. Someone who is not looking at the screen has no other way to know the tap registered — this is the single easiest thing to leave out and the one that decides whether the tool is trusted.
- **No modes, or exactly one with a hard escape.** A spotter cannot discover they are in a submode. If a mode exists at all, Esc leaves it and every key behaves identically otherwise.
- **Debounce and ignore key repeat.** A leaned-on space bar must not log forty turnovers.
- **Own the keyboard globally.** A document-level handler, no focusable inputs competing for it, and `preventDefault` on space so the page never scrolls out from under them.

The screen still shows state — current possession, the last few events, the line — but purely as something to glance at between points, never as something to aim at.

**Holder tracking stays on the shelf.** It is the only route to touches per player and possession time, which nothing else can give — but it is a different order of effort and its failures are worse. Revisit only if someone has run the event-based version through a full game and wants more.

**Line data makes this feasible, which is why the two belong together.** With the line known (§6 above), a holder selector offers *seven* buttons rather than a twenty-player roster — and after a catch the likely next holders are the same seven. Without line data it is a search problem under time pressure; with it, it is a seven-way tap. Neither feature needs the other, but each makes the other markedly more usable.

**One limitation to be honest about.** Tracking the holder distinguishes *which team* lost the disc and *who was holding it*, but not whether a failed pass was a throwaway or a drop — the thrower is the holder in both cases, so the receiver's error is attributed to the thrower. Conventional ultimate statistics separate those. So this yields level A of `STUDIO.md` §10.4 cleanly and only an approximation of level B, and a per-player turnover column built on it would be quietly unfair to throwers.

**Persist it per game, and plan to send it somewhere.** A spotter's full attention across a game is a real cost, and data that is collected and then overwritten by the next fixture wastes it — every event would re-collect from scratch. One file per game and an export are the minimum; the actual destination is UltiOrganizer, so the record survives the broadcast and reaches teams and season statistics rather than sitting on a laptop. `STUDIO.md` §10.4 carries that as part of the upstream request.

**And the same rule as everywhere else: never authoritative.** A spotter's stream is overlay state. It must not be written back into the scoresheet, and putting anything derived from it on air is the separate decision described below. Persisting it does not change that — a kept file is still a broadcast artefact, not a result.

### What it unlocks, and one thing to be careful about

Line data makes several genuinely new things possible: who is on for a key point, matchup notes, how often a player is on the field, and — combined with the goal list — which lines are scoring. None of that exists in ultimate coverage today.

It also answers the "who is on this point" graphic that `STUDIO.md` §10.2 lists as impossible. **But promoting it to air is a separate decision, not a free consequence.** Commentator-entered lines are a personal reference: a missed substitution costs the commentator a moment's confusion. The same error on a lower-third states something false about a named player to the audience. If it is ever put on air, it needs the same all-or-nothing test applied to break chance (`STUDIO.md` §3.4) — and probably an explicit "confirmed" action rather than automatically mirroring whatever the commentators happen to have typed.

## 6b. Gender ratio

Mixed divisions only, decided the way UltiOrganizer's own scoresheet decides it — the division name contains "mixed" (`cust/wfdf/pdfscoresheet.php:142`).

**The pattern is derivable; the labels are not.** WFDF's prescribed ratio runs A B B A · A B B A over successive points, so points 1, 4, 5, 8, 9, 12 … repeat the first point's ratio and the rest carry the other. That is taken from the same source as the printed sheet (`pdfscoresheet.php:1254`, which marks exactly those points with an asterisk), so a commentator reading this screen and a scorekeeper reading the paper cannot disagree.

What UltiOrganizer does *not* record is which ratio was chosen for point 1 — it is circled by hand on the scoresheet and nothing sends it back. So the panel asks once, remembers it per game, and only then names actual ratios. Until it is set it shows the pattern rather than guessing, because guessing wrong is wrong for the entire game rather than for one point.

The ratios themselves come from the season type: outdoor is 4M/3F against 3M/4F, indoor and beach 3M/2F against 2M/3F (`pdfscoresheet.php:461-471`). `seasoninfo.type` is in the game payload already.

It shows the current point and the next three, because the useful call is "this one and the next are 4M/3F, then it flips" rather than a single value.

## 7. The auto-surfacing behaviour

The idea: when something happens — a goal, a block — the person involved is pulled up automatically for a few seconds, with a control to pin it.

This is the feature most worth getting right and the easiest to make actively harmful.

**The risk is attention, not correctness.** A commentator is watching the field. A panel that appears, moves, steals focus or shifts what is under the cursor will make them look down at exactly the moment play restarts — which is worse than never having built it. So:

- **Peripheral, never modal.** A fixed region that changes contents. It must never overlay something the commentator might be reading.
- **Nothing moves.** The panel occupies the same rectangle whether it is empty or full. Reflowing the page on every goal is the single worst thing this could do.
- **Never steals focus**, never opens a dialog, never repositions anything under the pointer.
- **Pinning is one click and obvious**, and a pinned card stays until unpinned — an auto-clear that fires while someone is mid-sentence is the same failure as popping in.
- **A queue, not a race.** Two goals in quick succession must not have the second stamp on the first; the second waits or the panel shows both.
- **Muteable.** A commentator who finds it distracting must be able to turn it off and keep the rest of the page. Assume some will.

**Trigger detection is already solved for goals.** The scoreboard detects a score change and identifies the scorer today (`lastGoalOutcome()` plus the named goal entry) — the same signal drives the HOLD/BREAK flash. A commentator page reuses that mechanism against a slower poll.

**Blocks cannot trigger anything yet.** Tournament totals are now reachable (§4), but a trigger needs to know a block *just happened*, and that means the per-game list — which is in no payload (`STUDIO.md` §3.4) and waits on the direct-database decision. Goal-triggered surfacing is the whole of what can ship first.

## 8. Latency, and why it is not a problem

A commentator's page can be several seconds behind and remain useful — they are describing what they just watched, not reacting to a wire. So it can poll the ordinary cached endpoint on the 30s game-detail cache (`STUDIO.md` §6) without any of the special handling the overlays need.

One caveat worth designing for: **the page will lag the commentator's own eyes.** They will see a goal several seconds before the panel does. So the surfaced card must be labelled with *which* goal it refers to — scorer and score — rather than implying "just now". A card that appears silently after the next point has started, with no indication of what it describes, is confusing in a way a timestamp fixes for free.

## 9. MVP

Deliberately small, and none of it blocked on anything unresolved.

1. `commentator.php`, routed, read-only, public — same reasoning as the Studio page (`STUDIO.md` §2.5): nothing here is secret, and a commentator should not need an admin password to prepare.
2. **Header**: teams, seeds, records, field, cap state, timeouts.

   **Daylight is the default, and that is a readability decision rather than a taste one.** This page is used at the side of a pitch. A screen cannot out-emit the sun, so under a bright sky a dark interface stops being dark: the glass turns into a mirror and the reader sees their own reflection where the text should be. A light background puts the panel's brightest pixels everywhere, so reflected glare is a smaller fraction of what comes back and dark ink survives it — which is why every outdoor sign and e-reader is dark-on-light and not the other way round.

   So the day palette is not the night one inverted. It is built to a different rule: every text pair measured at **7:1 or better** (AAA, not AA), borders thick enough to survive glare rather than the hairlines that read as texture indoors, and column headers raised off 10.88px, which is the first thing sunlight erases. Measured across both modes and the player sheet, the day theme's worst pair is 7.7:1 and the night theme's is 5.23:1.

   **Not `prefers-color-scheme`.** That reports what the reader chose for their operating system months ago, indoors; it says nothing about whether the sun is on the screen right now, which is the only thing that matters here. It is a decision the reader makes on the spot, remembered per device in `localStorage`, and applied in the `<head>` before first paint — otherwise a reader who chose night gets a full white flash, which is exactly the reader least able to tolerate one.

   **Accessibility is carried structurally, not by position.** The visible header names each team once, over that team's column — which works by proximity, and proximity is exactly what a screen reader does not have. So the same naming is carried twice: a visually-hidden `<h1>` states the fixture, each header team name is an `<h2>`, and every panel and roster table below points back at the matching heading with `aria-labelledby`. The away name is flipped to the right edge with `flex-direction: row-reverse` rather than by reversing the nodes, because reversing the DOM makes the heading *read* backwards. One naming, two ways of reaching it.

   **A player with no points yet is the default state, not an exception.** On a fresh game that is most of the roster, so the dimmed `.quiet` row applies to statistics only — never to the jersey number or the name, which are the two things the row is being looked up *by*. Dimming those was both a contrast failure (3.64:1, below WCAG AA at any size here) and backwards as design: it faded the primary index of the page.

   **The header is also the column header for the page.** Everything below it is two columns of team content — team stats, then rosters, then the picker — so the team names sit in the header, home over the left column and away over the right, and no panel underneath repeats them. This started as a space problem (the name appeared three times down a page that has no vertical room to spare) and ended as a structural improvement: one naming of each team, positioned so it labels everything it applies to. The header is sticky, so the label survives scrolling a 28-player roster. On a narrow screen the columns stack and the away name moves to its own row, because a left/right header cannot label a top/bottom layout.
3. **Two rosters, side by side, sorted by jersey number** (§5) — the lookup a commentator performs most, and the reason this ranks above the event feed. Each row: number, name, pronunciation hint and pronouns where present, then goals / assists / total as columns, plus blocks where the installation records them (§4).

   **The player sheet carries everything Live! already publishes about that player**, on the principle that a commentator should not have to leave the page to reach a number a spectator could look up: this game's and the tournament's goals / assists / points, blocks where tracked, callahans, games played, points per game, division, jersey, and a game-by-game history from `entity=playerevents` — opponent, result, and what the player did in it. That history also yields the one derived figure worth the arithmetic: the team-mate this player most often scores with, in either direction, which is the connection worth naming on air. `playerevents` is fetched when a sheet opens rather than with the rosters, because 56 players' histories are a lot of requests for the handful anyone clicks.
4. **Live event feed**: the goal list in reverse, each line naming scorer and assist, with the running score and hold/break classification.
5. **Auto-surface on goal**, obeying §7, with pin and mute.
6. **Line selection** (§6), shared per team — the only piece needing a writable store, and the only one two people use at once.

Explicitly not in the MVP: search, drill-down, milestones, streak detection, bracket rendering, pre-match notes. Each is additive to a page that already works.

**Ordering note:** items 2–4 are a single static page over one payload and are worth building first, because they are useful even if the auto-surfacing (5) is later judged too distracting to keep. Within them, the rosters come before the feed: a commentator can work from rosters alone, but not from a feed alone.

**The pronunciation and pronoun store is a separate, smaller piece**, and can ship before or after the page — an editor over a `conf/` JSON file, with the page showing a hint when one exists and nothing when it does not. Neither field blocks the other, and neither blocks the MVP; both are useful from the first entry.

## 10. Open questions

1. **One screen or two?** A commentator with a laptop can have this beside the broadcast; a commentator with a tablet at a field cannot. Layout should degrade to narrow.
2. **Does `pool_placements` cover bracket play?** Same open question as `PLAN.md` §7.3, and it gates any bracket display here too.
3. **How much does a commentator actually want on screen?** Unknown, and worth answering by watching one work rather than by design. The density guess in this document is the least evidenced thing in it.
4. ~~Is per-game block data worth the direct-database decision on its own?~~ **Partly answered:** tournament block *totals* turned out to need no such decision at all — they are already on the roster row behind `ShowDefenseStats` (`STUDIO.md` §3.1), and are built. The question narrows to per-*game* counts, which are still worth more here than on a graphic because a caveat is possible, and which are what a block-triggered card (§7) would need.
5. ~~Who supplies pronunciation and pronouns?~~ **Answered:** `uo_player_profile`, edited by the player themselves via `user/playerprofile.php`, published through the existing `public` opt-in whitelist (§5). The remaining question is only whether commentary teams will maintain the local staging version long enough to justify proposing the upstream field.
6. **Why does `entity=players&id=<id>` return "Invalid ID"** for an id the list endpoint just returned? If that is a bug, fixing it may expose `uo_player_profile` and remove the need for several local stores.
7. **How many commentators share one game in practice?** The per-team split (§6) assumes two. Three or more, or a floating spotter who edits both teams, would make the last-write-wins choice less comfortable and might justify per-team revisions after all.
8. **Is line entry realistic at all mid-point?** The design assumes a commentator has both hands and a spare few seconds each point. That is an assumption about how people actually work, not a technical constraint, and it is worth watching someone try before building the rest of §6 around it.
