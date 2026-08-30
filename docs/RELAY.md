# State in the browser, server as a relay

A concept, not a plan of record. Nothing here is built.

The question: could the standalone mode's state live entirely in the participating browsers, with a server that only passes messages between them and stores nothing — making it cheap to offer as a service, with no responsibility for anybody's data?

**Short answer: yes for the data model, which is unusually ready for it; no for "fully stateless", which breaks on the one client that cannot be fixed mid-broadcast; and "no responsibility" is a licensing-and-privacy claim that statelessness alone does not earn.** All three are worth going through, because the first is genuinely encouraging.

## 1. The data model is already most of the way there

This is the surprising part. The stores were not designed as CRDTs, but they were designed by somebody repeatedly fixing "two people wrote and one lost", and that converges on the same shapes.

| Store | Shape today | As a merge type |
|---|---|---|
| `notes/<code>.json` | `{players: {id: {field: value}}, touched}`, saved as **deltas** — a key you send sets, a key you omit rides along | last-write-wins map, per player per field |
| `lines/<code>.json` | one player list per `(game, side)` | last-write-wins register, per side |
| `possession-<game>.json` `events` | append-only, each entry filed under the score it happened at | grow-only set, keyed |
| `possession` `ratio1`, `size` | one declared value each | last-write-wins register |
| `show.json` | one document, one writer, `rev` rejects a stale write | single register with optimistic concurrency |
| goals (proposed) | keyed by **the point number they create**, never `+1` | keyed map — idempotent by construction |

Five of those six merge without coordination. **That is not luck.** The delta save exists because a stale page was clearing fields nobody had edited. The score rule exists because `+1` pressed twice is a real 2–0 from one point. Each was a fix for a concurrency bug in the current architecture, and each is exactly what a distributed one would have required anyway.

`shared/declared.js` goes further and already implements the reconciliation such a system needs: a value declared locally, replaced when a shared one arrives, with a one-shot note telling the person it happened. That is a hand-rolled merge policy, written before there was anything to distribute.

**The exception is `show.json`**, and it is a benign one: what is on air has exactly one writer, the operator. A single-writer register does not need a CRDT, it needs the `rev` check it already has.

## 2. What "fully stateless" breaks

A relay that stores nothing can only deliver messages to clients that are connected when they are sent. Everything else follows from that.

**The join problem, and who it hurts.** A client arriving late has no state and must get it from a peer. In this system the late client is not a laptop — it is the **scoreboard running as a browser source inside a video switcher**. It joins when OBS starts, when a source is re-enabled, when the switcher reboots between rounds, and when the browser source crashes and reloads mid-game. It has no keyboard and nobody can refresh it.

So a stateless relay means: *the overlay shows nothing until an authoring client happens to be online to answer it.* At 9am, the person with the laptop must be up before the pitch. Mid-game, if the operator's browser is closed for thirty seconds, a reloading overlay gets nothing. That is a worse failure than the one the architecture was built to avoid.

**The clock genuinely wants a server.** It is three absolute unix timestamps, currently written with the server's `time()`. Peer-to-peer, every participant's clock is slightly wrong and none of them is authoritative — so either the relay stamps messages, which is a small amount of state and logic, or clients negotiate an offset, which is a distributed-clock problem for a scoreboard.

**The device risk is the real blocker, and it is already documented.** `tests/selftest.php` exists because embedded browser engines in switchers — Magewell, Yolobox — publish nothing about what they support, and the failure it detects is not "an overlay looks wrong" but *"an overlay never updates"*. It distinguishes five causes, including a device that **rasterises the page once and never runs a timer again**.

Today's design polls HTTP because that is the lowest common denominator, and `conf/show.json` is served as a **static file** so that even the cheapest client can read it. A design built on WebSockets or WebRTC data channels raises the floor on the least capable, least documented device in the chain — the one you find out about at a tournament. That is not a reason not to do it. It is a reason to run `selftest.php` on the actual hardware before designing around a transport, which is advice this project already gives for a different reason.

## 3. "No responsibility for data" is a stronger claim than statelessness

Worth separating two things that sound alike.

**Not storing is not the same as not processing.** The commentary desk's prepared notes are notes about named players — the reason `conf/` is gitignored, denied over HTTP, and auto-deleted after seven days. A relay that fans those out in cleartext handles personal data on every message. It holds none at rest, which genuinely reduces exposure and obligation, but "we store nothing" is not "this is not our problem".

**The thing that would actually earn the claim is end-to-end encryption, and this project is unusually well placed for it.** A room code already exists as a shared secret between the people in a room, and the relay already has no legitimate need to read anything — it routes by room, not by content. Encrypt in the browser, key never sent to the server, and the relay is carrying ciphertext it genuinely cannot read.

The mechanics fit too: a key can ride in the **URL fragment**, which browsers do not send to servers, so a link handed to a commentator carries the key without the relay ever seeing it. The five-character room code is far too short to *be* a key, but it is already the thing people exchange, so a longer secret behind the same gesture is a UI problem rather than a new concept.

That is the version worth wanting. It is also strictly harder: search, recovery, and "the operator lost the link" all become impossible by design, which is the point and the cost.

## 4. What I would actually build

**Nearly stateless, rather than stateless.** A relay that keeps one in-memory snapshot per room — no disk, no database, a TTL of a few hours, gone on restart — solves the join problem completely and keeps every operational benefit that matters:

- no backups, no migrations, no per-tenant storage
- nothing at rest to leak, subpoena, or be asked to delete
- a process restart loses a room, which for a live broadcast means the clients re-publish and it refills in a second
- one small box serves many tournaments, because the payload is a few KB per room and the message rate is a handful per minute

That is not a compromise so much as an admission of where the state has to be: **something has to answer a client that was not there.** In this system that client is a browser source in a switcher, and it is the one participant that cannot be told to try again.

**And keep the HTTP path.** The relay should be able to answer a plain `GET` with the room's current state, because that is what a device with no WebSocket support can do, and because it is what makes the existing static-file poll a special case rather than a different design. A cheap client polls; a capable one subscribes; both see the same state.

## 5. Peer-to-peer between the desks

A sharper version of the idea: let the authoring clients — the Studio, the commentary desks, match control — talk **directly to each other** over WebRTC, and leave the server out of the conversation entirely.

**The instinct is right, and the reason is that it splits the participants by capability rather than by role.** The desks are laptops and phones running current browsers. The overlays are browser sources inside video switchers whose engines publish nothing about themselves. Those are not the same client and there is no reason to make them speak the same protocol. A mesh of three or four modern browsers is trivial — the message rate is a handful a minute and the payload is a few KB — and the hard constraint from §2 applies only to the overlay.

Three things temper it.

**Peer-to-peer is not serverless.** WebRTC needs signalling to introduce peers, which is a server; it needs STUN to discover addresses; and when direct connection fails it needs **TURN**, which relays the traffic — at that point a server is carrying the data anyway, with more moving parts and more bandwidth than the few KB of JSON a plain relay would have carried.

**And the local network is the worst case, which is unfortunate because this is a local-network product.** Venue and conference wifi very often has client isolation switched on, so two laptops on the same SSID sitting next to each other at the same desk cannot address each other at all. That is precisely the deployment this is for — a commentary position and an operator on one venue network — and it is the environment in which peer-to-peer most reliably fails over to TURN. A product whose happy path is "two machines in the same room" should be suspicious of a transport that is hardest between two machines in the same room.

**It does not touch the actual constraint.** The overlay still has to read state from somewhere, and it is the client that cannot be helped: no keyboard, unknown engine, joins late, reloads unattended. Whatever the desks agree among themselves, one of them must publish a result the switcher can `GET`. So peer-to-peer removes the server from the *conversation* and not from the *architecture* — and the part it removes is the cheap part.

**Where it genuinely wins is privacy, not cost.** With a mesh and end-to-end encryption, the commentary desk's prepared notes — notes about named people — never transit a server in any form. That is a stronger claim than §3's, and it is the only argument for this that does not evaporate under examination. If the goal is "we cannot read your data because we never have it", peer-to-peer between the desks plus a published, non-personal projection for the overlays is the shape that delivers it.

**What that would look like:** the desks mesh and hold the private state — notes, matchings, line selections, the identity fields. One of them publishes only what actually reaches air — score, clock, on-air card state, the current line as numbers — to a small endpoint the overlay polls. The personal data stays in the room; the broadcast data was always public. That split is real rather than cosmetic, and it is roughly the split `conf/` already makes between the two files served statically and everything behind a PHP door.

## 6. Two tiers, because they are not the same client

Everything above keeps running into one asymmetry, and it is worth stating as a rule rather than rediscovering per feature.

| | **Broadcast surfaces** — scoreboard, stage | **Desk surfaces** — Studio, commentary, match control |
|---|---|---|
| Runs on | an embedded engine in a switcher that publishes nothing about itself | a laptop or a phone somebody is holding |
| If it breaks | it is on air, there is no keyboard, and nobody can refresh it | the person presses reload |
| Can you choose the browser? | no | yes, and you can say so in the requirements |
| Therefore | lowest common denominator, no dependencies, degrade rather than fail | **modern browser, dependencies allowed** |

`AGENTS.md` already establishes that the *syntax* baseline is Chrome 80 — Live!'s own frontend is an ES-module Vite bundle using `??` and `?.`, so any device on which Live! works can run modern JavaScript, and the ES5 style in these pages is convention rather than compatibility. What that rule does not yet say is that **the two tiers may diverge on purpose**, and they should.

**What it unlocks, concretely.** The honest motivation is not convenience: it is that §1's merge rules are five separate implementations that happen to be compatible, and hand-rolling a real CRDT is a well-known way to be subtly wrong for months. A desk that may take a dependency can use a library that has already been got right — Yjs or Automerge — while the overlay, which only ever *reads* a projection, needs none of it. Same for WebRTC helpers in §5: complexity that belongs on the tier that can afford it.

**The constraint that must survive.** `docs/README.md` says *"there is no build step and nothing to compile. The directory is the installation"*, and [`STANDALONE.md`](STANDALONE.md) §6 leans on it hard — the deployable artefact is a directory of PHP files and a writable `conf/`, which is what makes shared hosting and a laptop at a venue realistic. A bundler would take that away.

It does not have to. **ES modules plus vendored dependencies keep both**: `import` from a file in the repository, no bundler, no toolchain, still a directory you copy. That is the shape to hold — modern JavaScript and real libraries are compatible with "no build step"; a build pipeline is a separate decision and a worse one for this project.

**And the line inside `shared/`.** Several modules there — `ratio.js`, `stoppage.js`, `possession.js`, `timeouts.js`, `provider.js` — are loaded by both tiers. Those stay dependency-free and conservative, because a dependency added for the commentary desk that arrives on the scoreboard has quietly moved the floor on the device nobody can debug. If a desk-only module needs a library, it is a desk-only module, and `shared/` is not where it goes.

## 7. What it would cost to find out

The three things that would settle it, in order of what they would rule out:

1. **Run `tests/selftest.php` on real switcher hardware**, with a WebSocket panel added. It already answers "does this device run JS at all"; extending it to "does this device hold a socket" is small, and it is the answer everything else depends on. If the boxes people actually broadcast with cannot hold a connection, the relay design is settled before it is designed.
2. **Write the merge rules down as merge rules.** They exist as five separate store implementations that happen to be compatible. Stating them once — per-field LWW here, grow-only there, single-writer with a rev for show state — is worth doing whether or not any of this is built, because it is the thing a second implementation would get wrong.
3. **Prototype the room protocol against the existing stores**, not against a new model. If a relay can drive today's `notes`, `lines` and `possession` shapes unchanged, the claim in §1 is true; if it cannot, it was optimism.
4. **Test a peer connection on venue wifi**, not on a home network or a hotspot. Client isolation is the thing that decides whether §5 is a design or a wish, and it cannot be discovered anywhere except on the kind of network the product runs on.

## 8. The honest cost comparison

The current design is already close to free: flat files and PHP, no database, no Composer ([`STANDALONE.md`](STANDALONE.md) §6). A small box runs many tournaments today.

So the saving from a relay is not compute — it is **obligation**. No data at rest, no retention policy, no restore path, no "can you delete my tournament". For a service run by a volunteer or a federation rather than a company, that is the difference between something you can offer and something you have to administer. That is a real reason to want it, and a better one than cost.

## 9. Open questions

- **Who is authoritative when two people disagree about the score?** §1 says the goal map merges, but merging is not agreeing: two people entering different scorers for point 10 produces a last-writer, not a resolution. Today one person keeps score and that is the answer; a relay does not change it, but it makes disagreement invisible rather than impossible.
- **Does the payload come from a capture, a peer, or the relay?** A recording is a few hundred KB — too big to gossip on every join, small enough to fetch from a URL. Probably it stays an HTTP asset and only the *live* state goes over the room.
- **Does this dissolve the licensing question or sharpen it?** [`STANDALONE.md`](STANDALONE.md) §9 already flags that a mode running without Live! is a question for the people who signed its Terms of Use. Offering it as a service to others is a larger version of the same question, and it should be asked before anything is built rather than after.
