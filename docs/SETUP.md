# Setup, checks and teardown

A concept, not a plan of record. Nothing here is built.

It covers a whole broadcast rather than only its start: getting ready (§1–§6), staying honest while live (§7), and packing up (§8) — where the two most expensive mistakes in the whole day are waiting.

## 1. The docs have asked for this five times

Scattered across the documentation, in five places, written by somebody who kept hitting the same wall:

- `README.md` §"Confirm the scorekeeper starts the clock" — *"Worth a line on the pre-game checklist."*
- `README.md`, again, in the constraints list — the **same item, written twice**, which is what a missing home for something looks like.
- `STUDIO.md` §10.3 — *"Worth a pre-game checklist item."*
- `STUDIO.md` §11, on diagnostics painted onto the broadcast canvas — *"accept it and make 'check the overlay on a laptop first' a checklist item."*
- `AGENTS.md` on `tests/selftest.php` — *"worth running before a first broadcast."*

`PLAN.md` has a "Before a first broadcast" section with two items in it. There is no checklist anywhere, and no place to put one.

That is the case for building something. What follows is mostly the case for building the *right* something, because this is a feature that could easily turn into a notes app nobody opens.

## 2. Why a fixed checklist is wrong, and a blank one is useless

**Fixed is wrong.** Every rig differs, and not by a little: one camera on a tripod versus four with a hardware switcher; OBS on a laptop versus a Yolobox versus an ATEM Mini; a venue LAN versus a phone hotspot; hosted versus standalone; one pitch versus six. A list built for one of those is noise in the others, and a checklist that is mostly noise gets skipped entirely — which is worse than not having one, because now nobody is checking the two items that mattered.

**Blank is useless.** "Type your own list" is a text file, and a text file is free. If the software offers no more than storage, the crew should use paper — which is what they are doing now, and which works.

So the design question is narrow and answerable: **what can this software contribute that a shared document cannot?**

## 3. The answer: it can check some of them itself

The distinction that makes this worth building:

> **Verified items**, which the software determines and ticks on its own. **Asserted items**, which a human ticks because only a human can see them.

A checklist that is entirely asserted is a document. A checklist where a real fraction of the items tick themselves is a **readiness display**, and that is a different and much more useful thing — because the items that tick themselves are exactly the ones a tired crew forgets, and the ones whose failure is silent.

This is also the shape the project already reaches for. `tests/selftest.php` is not documentation about switcher compatibility; it is a page you point the switcher at, which tells you *which of five distinct causes* is behind "the overlay does not update". The instinct is right and this generalises it.

## 4. What can actually be verified — the proof this is not hypothetical

Every item below is knowable from state this project already reads. That is the argument: the verified fraction is large, not token.

| Check | Known from | Why it is silent today |
|---|---|---|
| **The clock is running** | `game_result.timer_start` is non-null | The item the docs ask for twice. Nobody starts it, and the overlay falls back to a status word rather than complaining |
| **The right game is on air** | `conf/show.json` versus the live game in the list | An overlay outliving its game shows a finished score over a live picture |
| **The overlay is reachable from the device** | `tests/selftest.php` | Already built, already distinguishes five causes — this would link to it rather than reimplement it |
| **Kit colours are set for both teams** | `conf/team-colors.json` | Defaults are not wrong, they are just not the teams' — and it is unfixable once you are live |
| **A commentator is actually connected** | possession's *"pages polling with this code right now"*, which the Studio already shows | A desk that typed the code wrong looks identical to a desk that has not opened the page |
| **Rosters loaded for both teams** | `teams.*.players` non-empty | An empty roster makes the commentary page useless and is invisible until somebody opens it |
| **Logos present** | `teams.*.photos` | Silent placeholder |
| **Mixed: matchings imported** | any note carrying `matching` | Without them the picker cannot group, count or hide — the page says so, but only once you are on it |
| **Mixed: ratio and line size declared** | the declared values in `conf/possession-<game>.json` | The ABBA run and every quota are wrong or absent until someone declares them |
| **The API is healthy** | consecutive poll failures | Already tracked; currently only surfaces as error text painted on the canvas |

Ten items, all machine-checkable, none currently surfaced anywhere a crew looks before a game.

The asserted remainder is the genuinely human half — camera framed, audio levels, tripod locked, SD card in, battery charged, tally working, stream key set, upload tested. **Those are exactly what varies per rig**, which is where configurability belongs.

## 5. Configurability: profiles, because every setup is different

**A profile is a rig.** "Field 1, two cameras, ATEM Mini, venue LAN." A tournament runs several at once and they are not alike — the show pitch has four cameras and a commentary booth, field 6 has a phone on a pole. One list cannot serve both and neither can one setting.

What a profile holds:

- **Which verified checks apply.** A standalone rig has no scorekeeper to nag about, so that item should not be red all morning — a check that is permanently failing teaches the crew to ignore the whole list.
- **Its own asserted items**, free text, ordered, in the crew's words.
- **Repeated items, per camera.** The one place the structure earns something over a flat list: a four-camera rig wants "framed · white balance · tally · audio" four times, and typing that out four times is how it gets to be wrong on camera 3. A count and a per-instance tick is small and worth it.
- **Nothing else.** No assignees, no due dates, no history, no sign-off.

**Profiles are shared, runs are not.** The profile is the reusable thing and belongs to the installation. A *run* is one game's ticks and is thrown away after. Keeping those separate is what stops last Saturday's completed list from looking like today's readiness.

**Where the state lives:** `conf/`, alongside show state, kit colours and the room codes. This is the pattern `STUDIO.md` §2.5 already names — *"operator-maintained state is a way to add features that would otherwise need core changes"* — and it is why kit colour exists at all despite UltiOrganizer having no team colour. No schema, no upstream ask, no database.

## 6. What this must not become

The failure mode is obvious and worth naming before anyone writes code.

**It must not become a project-management app.** Assignees, deadlines, completion history, per-tournament reporting, "85% ready" — every one of those is a plausible next feature and none of them helps anybody get a picture on air. The scoping rule:

> An item earns its place if the software can **check** it, or if getting it wrong puts something **false or missing on air**. Everything else belongs on paper.

"Battery charged" passes that test. "Confirm the commentator has had lunch" does not, however true it is that the broadcast depends on it.

**It must not nag.** A check that fails for a legitimate reason — standalone with no scorekeeper, a friendly with no logos — must be switchable off per profile, because a permanently red list is a list nobody reads, and then the one real failure is invisible among the noise.

**It must not become a gate.** Nothing here should block going on air. A crew that is behind and knows it does not need the software refusing to help. Show the state; let them decide.

**It must not claim a check it did not do.** A tick for "the overlay is reachable from the switcher" cannot be inferred from the Studio being able to reach it — the Studio is a laptop and the switcher is the device in question. That is the whole reason `selftest.php` runs *on the device*. Any item the software cannot honestly determine is an asserted one, and should look different from a verified one so the distinction stays visible.

## 7. Reminders during the broadcast

A checklist is a thing you do once. The more interesting version is one that keeps going — *"have you looked at the output lately?"* — and it needs handling carefully, because it runs straight into the principle everything else here defers to.

**The case for it is the strongest case in the project.** `AGENTS.md` names the characteristic failure: *"not a crash but a graphic quietly asserting something untrue — a tab outliving its point, a rate without its denominator, a field-following overlay silently showing the wrong game. Those look completely normal in a screenshot."* Nothing announces those. An operator forty minutes into a close final is watching the play, and a wrong graphic can sit there for a whole half because it looks exactly like a right one.

**The case against is `MATCHCONTROL.md` §2.** The operator's attention is not spare capacity, and it is contended hardest precisely when the broadcast matters most. A timer that fires every ten minutes fires during points, and a prompt that interrupts a point is worse than the thing it is guarding against. Worse still, it trains the reflex that closes it unread — and then the one real alert is invisible.

Three things resolve that, and they are all applications of rules already in this codebase.

### Fire at breaks, not on a timer

**The system already knows when play stops.** The score changing ends a point. `half_cap` is the break. `timeout` events are stoppages, and `shared/stoppage.js` already computes whether one is active. Those are moments when the operator's attention is genuinely free, and they arrive several times a game without a clock being involved.

So a reminder should be scheduled in *game* events rather than in wall-clock minutes: "at the next break after fifteen minutes have passed". That converts an interruption into something that rides along, which is the same §2 axis applied to output instead of input. It also fixes the cadence problem for free — a scrappy game with many stoppages offers more moments than a fast one, which is roughly when a distracted operator needs more of them.

### Prefer a check to a nudge

The verified/asserted line from §3 applies here too, and harder: **do not remind a human to look for something the software can see.**

Several of the "quietly untrue" failures are detectable. A card that has been up longer than any card should be. A field-following overlay pointed at a game that has finished. A payload that has not changed in longer than the poll interval should allow. Possession state left standing across a score change — which already auto-clears, and whose *staleness ceiling* is the precedent here: the break-chance flag already expires by itself as *"belt-and-braces against an operator who sets it and looks away."*

Every one of those should be a specific detection with a specific message, not a generic "check the output". A reminder is what remains **after** the checkable things have been checked — and what remains is genuinely un-checkable from inside the browser: is the picture actually reaching the stream, is audio live, is the camera still framed, has the tripod been knocked.

That is a short list, which is the right size for something that interrupts.

### It has to reach somebody who is not looking at the page

This is the constraint most reminder designs would get wrong, and this codebase has already discovered it twice. The operator is watching the programme output, not the Studio — which is why possession is a keypress and why the off-air flash is `role="status"` with `aria-live="polite"`, *"announced, not just shown: an operator watching the program monitor is not looking at this bar."*

A reminder rendered quietly into the Studio's toolbar would therefore be seen by nobody, and would look like it worked in every test.

### Why the obvious answer — a beep — is usually the wrong one

**Audio leaks into the commentary microphone.** A laptop chime at a desk with an open mic is on the broadcast, and unlike a graphic it cannot be taken back. Nothing in these overlays has ever made a sound; that is not an oversight to correct casually.

**And it interacts badly with the rule above.** Firing at breaks in play is right for attention and wrong for audio: **a break is exactly when the mic is hot.** The commentators are filling the gap between points, which is when they talk most and when a beep is most certain to be heard. "Fire at breaks" and "use a sound" are each sensible and jointly wrong, which is the kind of thing that only shows up on the broadcast.

Worth contrasting with the sibling app: UltiOrganizer's Timekeeper *does* make sound, because there the audio **is** the product — it signals a time limit to players on the pitch, and everybody hearing it is the point. Here, everybody hearing it is the failure.

So the ladder runs silent-first:

1. **A large visual change, not a small one.** Peripheral vision picks up area, not detail. If the Studio is on a second screen anywhere in view, a whole-page wash is noticed where a toolbar chip is invisible — and it costs nothing and leaks nothing.
2. **A desktop notification.** Reaches an operator whose Studio window is behind something else, silently, and survives the page not being focused. It needs a permission grant, which is itself a setup-checklist item — pleasingly recursive, and a good example of a check that must be *asserted at setup* so it is not discovered missing mid-game.
3. **A phone buzz**, if the match-control surface (`MATCHCONTROL.md`) is in somebody's hand. Silent, physical, and reaches a person who is nowhere near a screen. The honest limit: the Vibration API works on Android browsers and **not on iOS Safari**, so it is a bonus rather than a mechanism to rely on.
4. **A sound, last, opt-in, and off by default** — for the rigs where no open microphone is near the operator.

**Whether audio is safe is a fact about the rig, which is what a profile is for** (§5). A commentary booth acoustically separate from the operator can beep freely; a two-person crew sharing a table cannot. Nobody but the crew knows which they are, so nobody but the crew should be setting it — and the default has to be the safe one.

### The limit worth stating plainly

**A crew watching a hardware multiview cannot be reached by a browser at all.** No notification, wash or buzz appears on an ATEM's monitor output. For that setup the reminder can only reach somebody sitting at the laptop, and if nobody is, it reaches nobody.

That is not a bug to design around; it is a boundary to admit. It also points the same way as `MATCHCONTROL.md` §7 did: if the Studio ever grows a preview of the output, the operator has a reason to look at the page, and every one of these problems gets easier at once.

### Rules, so it stays useful past the first hour

- **Off by default, on per profile.** A "remind me" mode is opted into by a crew that wants it, which is also the answer to every rig being different — and audio is a second, separate opt-in inside that, because a crew can want reminders and still have an open mic.
- **Never a gate, never on air.** It informs; it does not block, and it never touches the broadcast canvas — that canvas already has one diagnostics problem (`STUDIO.md` §11) and must not get a second.
- **Dismissible, and silent once dismissed for that game.** An operator who has decided is not asked again.
- **Cadence in the profile, not in the code.** A four-camera rig with a spare pair of hands wants more prompts than a solo operator who cannot act on them anyway.
- **Nothing that fires more than a handful of times a game.** If the answer is "every two minutes", the honest reading is that the thing wants detecting, not reminding.

## 8. After the game: disassembly and post-production

The list does not end at the first pull. Two of the most costly mistakes available all day happen in the ten minutes after the last point, when everybody is tired and the kit is being coiled.

### Disassembly

Three of these the software can check, and the first is the same failure this project keeps naming.

| Item | Checkable | Why it matters |
|---|---|---|
| **The overlay is off air** | yes — `conf/show.json` | An overlay left up over a finished game is precisely a graphic *"quietly asserting something untrue"*. It looks completely normal. The stage already announces going off air; nothing announces having forgotten to |
| **The commentator room code is revoked** | yes — the possession store | That code authorises writes that reach air. Leaving it live means a screen in a packed-down booth, or anybody who read it during the day, can still write. `possession.php` already documents the code as the capability it is |
| **Prepared notes: export them or lose them** | partly | Notes are deleted seven days after the last edit, by design — they are notes about named people. That is correct, and it means a crew who wants them for the next game against that team has a **window**. The bio CSV export already exists and is the way out |
| Cards pulled, batteries out, cables, the thing left in the grass | no | Human, per-rig, and exactly what a profile's asserted items are for |

### Post-production: note the anchors before you leave

This is the item worth building the whole feature for, because the information is **unrecoverable later** and almost nobody would think to write it down.

`POSTPRODUCTION.md` aligns the video timeline to the game timeline using **anchors** — *"goal n happens at this position in the video"*. One is required; more are free checks. Without one, footage cannot be scored at all by the deterministic renderer.

**And how many you need depends on data the software can already assess, at exactly the moment somebody could still act on it.** `POSTPRODUCTION.md` §2 sets it out:

| How the game was scored | `timer_start` | Goal times | Anchors needed |
|---|---|---|---|
| Live Scorekeeper | set | good | **one**, fully automatic |
| Sheet typed up later | null | approximate | **one**, plus care across pauses |
| Times hidden or blank | null | useless, clustered | **one per goal** — there is no data to interpolate from |

So the teardown step is not a generic "note the timecode". It is a computed instruction: *"This game has no `timer_start` and its goal times are clustered. Post-production will need an anchor per goal — note them now, or this footage cannot be aligned."* That is a sentence the software can write and a person cannot, because working out which of the three rows you are in means looking at the payload.

**Halftime is the other one.** The game clock stops at the break and the video does not, so an anchor either side keeps both halves honest — and the break is *"exactly the part of the timeline UltiOrganizer does not describe."* If the crew notes anything at all, the video position of the last goal before the break and the first after it is worth more than everything else on this list.

**Why this lives here and not in `POSTPRODUCTION.md`.** That document describes the tool. This describes the five minutes in which the tool's only required input is still obtainable — and the tool does not exist yet, which makes capturing the input *now* worth more, not less. Footage shot this season is alignable whenever it gets written.

## 9. Where it would live

**Its own view, linked from the Studio**, rather than a panel inside it. The Studio is used during a broadcast and this is used before one; the toolbar is already carrying four controls and §2 of `MATCHCONTROL.md` applies here too — the operator's attention during a game is not the place for a setup surface.

It should be reachable **without the admin session**, read-only, because the person checking that camera 3 is framed is not the person holding the password — the same argument that made the commentator's room code a capability rather than a login.

And it should link to `tests/selftest.php` rather than absorb it. That page has to be opened *by the switcher*, which is a thing no other surface here does, and it already answers its question better than a tick-box could.

## 10. Open questions

- **Does a profile belong to a field, a rig, or a crew?** Fields are stable across a tournament and already named in the payload (`entity=reference`), which makes them the cheapest key — but the kit moves between fields between rounds, and it is the kit the list describes.
- **Does the verified half want to run continuously or on demand?** Continuously makes it a readiness display and costs polls; on demand makes it a checklist and can be stale by the time it matters.
- **Is there a first-broadcast list distinct from a per-game one?** `PLAN.md` §"Before a first broadcast" is clearly the former — run the selftest, decide the diagnostics policy — and those are done once per venue, not once per game. Two lists or one list with a "once" flag.
- **Do reminders belong to the checklist at all?** They share the profile and the "what can be verified" question, which is why they are here — but a pre-game list and an in-game nudge are used by different people at different moments, and bundling them could produce a surface that serves neither.
- **Standalone changes the balance.** With no Live!, several verified checks disappear and several asserted ones appear (has the roster CSV come back, has the game been created). Worth designing so the check library can be per-mode rather than assuming hosted.
