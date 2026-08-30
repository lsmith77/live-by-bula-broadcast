# Score and clock — where they are kept, and by whom

A concept, not a plan of record. Nothing here is built.

It arose from the standalone question ([`STANDALONE.md`](STANDALONE.md)), where nothing keeps the score, but it is **not standalone-specific**: hosted mode has the same problem wearing different clothes, and §8 is about that.

## 1. The question

Standalone mode has to get the score and the clock from somewhere, and "somewhere" is a person with a device. Which person, and which device, changes with how many people showed up — and a broadcast crew is one, two, three or four people depending on the day, the round and who did not turn up.

The wrong way to answer this is to pick a crew size and design for it. Every surface in this project is already shaped by *whose job it is*, and that is the question that survives a crew of any size.

## 2. The principle is already settled

`STUDIO.md` §3.5, on why possession declaration belongs with the scorekeeper rather than the operator, states the axis plainly:

> *the operator's attention is not spare capacity.* Their job is directing — choosing graphics, timing them, watching the programme output. Adding "press a button on every turnover" competes with that job rather than riding along with it, and it competes hardest exactly when the broadcast matters most.

and scores the options on one row that matters more than the others:

> **Competes with the capturer's main job** — yes / no, *it is their main job*.

So the question is not "where is there room on a screen". It is **whose eyes are already on the thing being recorded**.

For score and clock that answer is unusually clear: *whoever is watching the game*. Which is the commentator, the scorekeeper, and — only incidentally, and least reliably — the operator.

## 3. Scoring is cheap to do and expensive to get wrong

Worth stating precisely, because the intuition is backwards.

**The volume is tiny.** A game to 15 is about 30 goals over 80–100 minutes: one input every three minutes. The clock is start, halftime, restart, and the odd stoppage — under a dozen presses a game. Compare that with the surfaces this project already asks people to drive: line selection is seven picks per point (~200 a game), and possession is several presses per point.

**The consequence is the largest on the page.** The score is the one number every viewer independently knows, and a wrong one is not a degraded graphic but a visibly false one. It is also the input with the widest blast radius internally: the `goals` array drives the point number, the ABBA slot, hold/break, the progression card and the per-point strip.

So this is a **low-frequency, high-consequence** input, and that combination has design consequences:

- It can ride along with somebody else's job without competing with it — unlike possession, and unlike line selection.
- It cannot be allowed to be entered twice.
- It is worth interrupting somebody to correct, and therefore worth making correctable in one press.

## 4. The real crux: a goal is not an increment

This is the part that decides the design, and it is easy to miss until two people are pressing buttons.

**`+1` is not safe to send.** Line selection is last-write-wins and possession presses are dropped when they change nothing, because both are *statements of current state*. A goal button that means "add one" is a statement of a **delta**, and two people pressing it for the same goal produces 2–0 from one point. So does one person pressing it twice on a laggy connection, which is the likelier case.

**So a goal write must state the result, not the change.** The store already works this way everywhere else: possession events are filed under the score they were made at (`score: "9-6"`), notes skip a write that would change nothing, and the line store now does too. A goal should carry the point it creates — "home scored point 10, making it 6–4" — and the store should accept it only if point 10 is the next one to exist.

That single rule makes the whole crew question tractable:

- Two people pressing for the same goal: the second is a no-op, not a second goal.
- A retry after a timeout: safe, by construction.
- A phone that was asleep and comes back: it can send what it thinks and be told no.

It also means **more than one surface can hold the button without anybody having to own it**, which is what makes the crew matrix below work.

## 5. Decided for now: one phone-optimised surface

**A separate page, built for a phone, and nothing embedded anywhere.** That is the v1, and the rest of this section is why that is a scoping decision rather than a limitation, and what has to be true now so that it stays one.

`/m/<game>` — its own URL, following the convention the root `.htaccess` already states for `/c/`: *"a different job and a different person: /s/ is the operator deciding what goes on air, /c/ is the person talking over it."* Keeping score is a third job and often a third person.

**What is on it:** the two teams with a large press each, the current score, an undo, and a clock with start / pause / half. A visible sync state. Nothing else — every feature added here competes with the one job the surface exists for.

**Phone-optimised is the requirement, not the fallback.** The person keeping score is frequently standing at the pitch rather than sitting at a desk: behind a camera, beside the scoresheet, away from the laptop. Designing for the phone first and letting it work on a laptop is the right way round; the reverse produces a page with buttons too small to press without looking.

**A second device is an acceptable ask, and that is what makes this simple.** Even a solo crew can be assumed to have a laptop and a phone. Without that assumption, v1 would have to be embedded somewhere, and then the question of *where* has to be answered for every crew size before anything can ship.

**Not called "Scorekeeper".** UltiOrganizer already ships `scorekeeper/`, `timekeeper/` and `spiritkeeper/`, and in hosted mode its Scorekeeper is the authority this must not pretend to be (§8).

### What this defers, and what it must not foreclose

Bringing score to the other two surfaces is the obvious later move — it would let a two-person crew keep score without a third device, and give any crew a fallback when the phone dies. Deferred, not rejected.

**And when it comes, it is probably keys rather than a panel.** The commentator page is already keyboard-driven and has a reference dialog listing every shortcut: digits type a shirt number, `L`/`R` open a sheet, `O`/`D`/`I`/`U` drive possession. Two more keys cost no screen space on a page whose play view is deliberately ordered so nothing scrolls during a point — where a panel would cost the field rows.

That is a genuinely lighter change than embedding a control, but it carries a risk a panel does not, and the project has already been bitten by the general form of it. The quick-card design records retiring a letter-row mapping because *"it was a single blind keypress and it was wrong"* — for a different reason, but the caution transfers: **the score is the highest-consequence thing on the page and a mis-press reaches air.** So if keys are added:

- **Opt-in**, not on by default. A commentator who is not keeping score should not have live score keys under their fingers.
- **Undo in one press**, which §3 already requires of every mounting.
- **Pick keys that are not neighbours of anything destructive**, and add them to the Keys dialog in the same change — `AGENTS.md` now requires that pairing precisely because a gesture missing from that dialog does not exist as far as a user is concerned.

**Deferring it is free if, and only if, §4 holds.** Because a goal is written as *the point it creates* rather than as `+1`, a second surface can be added later without touching the store and without any risk of double entry: whichever press lands first creates point 10, and anything else claiming point 10 becomes a no-op. If instead v1 ships a `+1` endpoint — which is the tempting shortcut when there is only one surface and it obviously cannot race itself — then embedding later means changing the write protocol, migrating whatever has been stored, and re-testing every consumer.

So the rule to hold now, while it costs nothing:

> **Write the result, not the change — even though only one surface can currently send it.** The single-surface simplification is in the UI, never in the store.

Two smaller consequences of the same discipline:

- **Build it as a module with a page around it**, not as a page. If the control is a self-contained thing the page mounts, embedding later is a second caller rather than a second implementation — the same shape as `render()` having a post-production caller.
- **Gate it by capability, not by page.** The room code already grants a commentator exactly one power without granting admin, and `possession.php` documents the two doors that make that safe. Score and clock become another grant of the same kind. That costs nothing extra now and is the whole mechanism later.

## 6. The crew, at each size

With one phone surface, the allocation question becomes simply *who is holding the phone*.

| People | Who holds the phone | Devices | Notes |
|---|---|---|---|
| **1** | The operator | Laptop + phone | The phone matters most here: a solo operator is away from the desk for much of the game. |
| **2** | The **commentator** | Two laptops + a phone | Their job is already watching every point; one press every three minutes rides along, where the operator's job does not (§2). Costs this crew a third device, which is the clearest price of the v1 scoping — and the case embedding would later remove. |
| **3** | A dedicated keeper, or a team volunteer at the pitch | Three + phone | Frees the commentator's hands for lines and possession, the high-frequency inputs. |
| **4** | A dedicated keeper | Four + phone | Operator, two commentators, keeper. |

**The two-person case is the load-bearing one**, because it is the most common and because the obvious allocation is wrong. Giving score to the operator "because they have the admin login" optimises for the permission model instead of for attention.

**The keeper is often not broadcast crew at all.** At three or more the natural candidate is the person already keeping the paper scoresheet. They should be able to hold the score capability without being able to touch what is on air — exactly the shape the room code already has, and the strongest argument for capability grants over a single admin password.

## 7. Timeouts, which only the scoreboard knows about

Raised while writing this, and worth recording because the answer turned out to be "no".

**The scoreboard shows timeouts remaining, on air, and neither desk shows anything.** `scoreboard.php` derives them properly — the allowance from `poolinfo.timeouts`, the count from `gameevents` entries of type `timeout` carrying `ishome`, with `timeoutsper: "half"` resetting at the `half_cap` event. Every occurrence of the word in `commentator.php`, `index.php` and `stage.php` is a JavaScript `setTimeout`. The concept is absent from both.

That is a gap on the surfaces where it matters most:

- **The commentator** says "they have one left, and they will want it" as a matter of course. It is exactly the kind of fact this page exists to hold — known, derivable, and impossible to keep in your head across two teams and a half.
- **The operator** is timing graphics around stoppages. A timeout is the most predictable break in play there is, and the Studio already has a stoppage toggle without knowing anything about the allowance behind it.
- **A timeout stops the clock**, so it belongs to whoever holds the clock. If match control owns start/pause, it should show what the pause was for and what it costs the team taking it.

**The derivation should move to `shared/` before it is used twice.** It is currently inline in `scoreboard.php`, and `AGENTS.md` is explicit: *put a derivation in `shared/` and test it there*. Extracting `timeoutsRemaining(pool, gameEvents, isHome)` is small, makes it testable without a browser, and is a precondition for showing it anywhere else rather than growing a second copy that drifts — which is exactly how the ratio came to be printed two different ways before `shared/ratio.js` existed.

This is independent of standalone mode and worth doing on its own. In standalone the events would have to be authored rather than read, which makes taking a timeout a fourth thing match control records — and an argument for the surface being about *the match* rather than only about score and clock.

## 8. Hosted mode has the same problem, and one real gap

In hosted mode score and clock come from UltiOrganizer's Scorekeeper, driven by a tournament volunteer at the pitch. That separation is healthy and should not be dissolved: **the scoresheet is the tournament's record and the overlay is a picture of it.** Standalone recreates the picture; it must not quietly become the record for a game the tournament also records elsewhere.

So hosted mode does not want a second score input. It does want the same surface with different powers:

| | standalone | hosted |
|---|---|---|
| Score | authoritative | **read-only** — shows what the Scorekeeper recorded |
| Clock | authoritative | read-only **while UO has one** |

The exception is worth building, because the gap is already documented. `STUDIO.md` §10.3 records it: *"`timer_start` is only written by Scorekeeper. A scorekeeper who never starts the clock means no clock on air, and the overlay silently falls back to a status word. Worth a pre-game checklist item."*

A pre-game checklist item is a hope. The same surface, offering a **local clock only when UO has none**, turns a silent failure into a one-press recovery — and it corrupts nothing upstream, because it writes to overlay-local state and never to UltiOrganizer. That makes match control worth building for hosted mode even if standalone never ships.

It should say which it is doing, in words, on the screen. A clock that is the overlay's own and looks identical to the Scorekeeper's is a graphic asserting something it does not know.

## 9. Failure modes to design for, not discover

- **Venue wi-fi.** A phone at the pitch will lose the network. The surface must show the score it believes locally, retry, and say plainly when it is not synced — the notes box already has the pattern and the wording: *"Not shared — kept on this screen."* Silence is the one unacceptable response.
- **Double entry.** Solved structurally by §4, not by discipline or by locking a surface to one person.
- **The wrong goal.** Undo must be one press and must be reachable while the next point is being played. The possession store already scopes corrections to the current point for exactly this reason, and the same reasoning applies: correcting the point being played changes a number nobody has read out; correcting an earlier one silently rewrites what a commentator already said.
- **A sleeping phone.** It comes back with a stale view. It must reconcile to the store rather than push what it remembers — the same rule as every declared value: shared wins, local only fills a gap.
- **Nobody pressing anything.** The overlay must be able to say the clock is not running rather than draw a stopped one, which is the honest version of the §7 gap.

## 10. Open questions

- **Does the keeper also hold the ratio and line size?** They already exist as declared values and are currently the desk's. The person with the paper scoresheet is the one who can actually see the circled ratio, which argues for moving them — but the desk is who needs them. Probably both, since they are already capability-gated declared values and reconcile cleanly.
- **Do timeouts belong to match control or to the desks?** §7 says both should *see* them; who *records* one in standalone is open. It stops the clock, which argues for match control; it is announced, which argues for the desk noticing.
- **Does match control subsume the Timekeeper?** UltiOrganizer ships a standalone Timekeeper for WFDF time-limit signalling. Overlapping it would be duplication; ignoring it means two clocks at one pitch. Read it before designing the clock.
- **One game or a field?** A keeper at a pitch covers consecutive games on it. A surface bound to one game id means re-navigating between rounds; one bound to a field follows whatever is live, as `/s/field/<n>/` already does.
- **Is undo enough, or is a full edit needed?** Undo covers the last goal. A score that has drifted by two over ten minutes needs a correction surface, which is a different and more dangerous thing.
