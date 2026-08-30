# Setup and pre-game checks

A concept, not a plan of record. Nothing here is built.

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

## 7. Where it would live

**Its own view, linked from the Studio**, rather than a panel inside it. The Studio is used during a broadcast and this is used before one; the toolbar is already carrying four controls and §2 of `MATCHCONTROL.md` applies here too — the operator's attention during a game is not the place for a setup surface.

It should be reachable **without the admin session**, read-only, because the person checking that camera 3 is framed is not the person holding the password — the same argument that made the commentator's room code a capability rather than a login.

And it should link to `tests/selftest.php` rather than absorb it. That page has to be opened *by the switcher*, which is a thing no other surface here does, and it already answers its question better than a tick-box could.

## 8. Open questions

- **Does a profile belong to a field, a rig, or a crew?** Fields are stable across a tournament and already named in the payload (`entity=reference`), which makes them the cheapest key — but the kit moves between fields between rounds, and it is the kit the list describes.
- **Does the verified half want to run continuously or on demand?** Continuously makes it a readiness display and costs polls; on demand makes it a checklist and can be stale by the time it matters.
- **Is there a first-broadcast list distinct from a per-game one?** `PLAN.md` §"Before a first broadcast" is clearly the former — run the selftest, decide the diagnostics policy — and those are done once per venue, not once per game. Two lists or one list with a "once" flag.
- **Standalone changes the balance.** With no Live!, several verified checks disappear and several asserted ones appear (has the roster CSV come back, has the game been created). Worth designing so the check library can be per-mode rather than assuming hosted.
