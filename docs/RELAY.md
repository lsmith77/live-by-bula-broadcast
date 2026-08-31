# State in the browser, server as a relay

**A future direction, not a plan. Nothing here is built and nothing here is scheduled.**

[`STANDALONE.md`](STANDALONE.md) is the near-term work and stays deliberately server-based: a small PHP server on the network, the stores as flat files, the architecture that exists. This document is a different shape the project *could* take, written down because the question came up and because the answer turned out to be more encouraging than expected — and because §8 makes clear it is a decision about what the project is, not an optimisation to slip in later.

The question: could the standalone mode's state live entirely in the participating browsers, with a server that only passes messages between them and stores nothing — making it cheap to offer as a service, with no responsibility for anybody's data, and able to run on a local network with no internet at all?

**Short answer: yes, and more comfortably than expected — the whole thing turns on one hardware question that nobody has answered yet.**

- **The data model is ready.** The stores are already nearly CRDTs, not by design but because each was fixed after "two people wrote and one lost" (§1). Because they merge without coordination, a late joiner can take a snapshot from *any* peer, which is what makes the peer-to-peer version work at all (§2).
- **On one LAN, peer-to-peer needs no STUN, no TURN and no internet** (§6). The usual "peer-to-peer is not serverless" objection does not apply to a single subnet.
- **Everything hinges on whether a switcher's browser source can hold a peer connection.** If it can, the design is complete. If it cannot, something on the network must serve it over HTTP — which is a laptop running `php -S`, the deployment [`STANDALONE.md`](STANDALONE.md) §6 already describes and which is offline today. That is the one experiment worth running before anything else (§9).
- **What offline buys is fault tolerance, not a broadcast.** No internet means no stream, whatever the overlays do. What survives an outage is **local recording**, and the graphics staying correct through it (§6).
- **"No responsibility for data" is a separate claim** and statelessness alone does not earn it; end-to-end encryption does, and this project is unusually well placed for it (§3).

Two reframings worth keeping:

**Peer-to-peer is not what makes offline possible — a local server already does. What it buys is not needing one.**

**The real cost is not the code, it is a second implementation of every rule** (§8). Migrating the stores is smaller than it looks — the pages are already JavaScript applications with a PHP header, and much of the store code is file locking that disappears when there is no file — but hosted mode keeps its PHP authority and peer-to-peer mode gets a JavaScript one, and the same forty checks then exist twice in two languages.

**And the destination may not be here.** The capability this is really about — hold state locally, reconcile when a network appears — is the same one UltiOrganizer's Scorekeeper needs and does not have: server-rendered form posts with no offline storage of any kind, on a phone, at a pitch with one bar. Built here it makes the covered games fault-tolerant; built there it fixes every game at every tournament and the overlays inherit it. Noted in [`UPSTREAM.md`](UPSTREAM.md) as shared ground rather than as an ask.

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

**The join problem, and how far a peer answers it.** A client arriving late has no state and must get it from somewhere. The natural answer is *from a peer* — and here that answer is unusually solid, because of §1: the stores merge without coordination, so **any** peer's copy is a legitimate snapshot. There is no "the authoritative one" to elect, and a late joiner that pulls from whoever answers first and then merges whatever else arrives is correct rather than approximately correct. That is a real strength of this data model and it removes most of the objection.

What it does not remove is *who* the late client is. It is not a laptop — it is the **scoreboard running as a browser source inside a video switcher**. It joins when OBS starts, when a source is re-enabled, when the switcher reboots between rounds, and when the browser source crashes and reloads mid-game. It has no keyboard and nobody can refresh it.

So the join problem narrows to one question, and it is a question about hardware rather than architecture: **can that device hold a peer connection at all?** If it can, it joins the mesh, asks a peer, and the objection is gone. If it cannot, something on the network has to answer it over plain HTTP — and that is §7's first experiment, not a thing to reason about further.

**Meanwhile, the two failure windows are narrower than they look.** A stateless relay means the overlay shows nothing if it reloads while no authoring client is connected — at 9am before the operator's laptop is up, or during thirty seconds when a browser is closed. Both are real; neither is common; and an in-memory snapshot on the relay (§4) removes them entirely for the cost of a few KB per room.

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

## 6. Running with no internet at all

Assume what a venue can usually be made to provide: every client on one local wifi, reliably. Then the interesting claim is that **once the interfaces have loaded, the whole thing runs with no internet** — and that claim is largely true, with one condition that decides it.

**Peer-to-peer on a LAN needs no STUN and no TURN.** Those exist to get through NAT and to relay when direct connection fails. Two browsers on the same subnet exchange *host candidates* — their own local addresses — and connect directly. `iceServers: []` is a working configuration for this case. So the usual "peer-to-peer is not serverless" objection (§5) does not apply here: on one LAN, it genuinely is.

**Two things still need care.**

*Signalling is a rendezvous, not a server.* Peers must exchange a session description once, at join. That is a few KB, one time, and it does not need the internet — it needs something both peers can reach. Anything on the LAN will do, including whatever served the page.

*Browsers hide local addresses behind mDNS.* For privacy, current browsers publish host candidates as `<uuid>.local` rather than as raw IPs, and resolving those needs working mDNS on the network. It usually is; it is also exactly the sort of thing a corporate or venue AP disables without telling anybody. It belongs on the same test as client isolation.

### The condition that decides it

Everything above works for the desks. The question is whether the **overlay** is inside or outside the mesh, and the two answers give very different systems.

**If the switcher's browser source can hold a peer connection**, the picture is complete and rather elegant: load every interface once, and from then on the operator's laptop, the commentary desks and the overlays exchange state directly over the LAN. A reloading overlay asks a peer and gets a snapshot (§2). Nothing needs the uplink.

**If it cannot**, something on the LAN must serve it over HTTP — and a browser cannot listen on a port, so that something is a small server. Which is to say: **a laptop running `php -S`, exactly the deployment [`STANDALONE.md`](STANDALONE.md) §6 already describes.** That configuration is offline today, with no peer-to-peer anywhere in it.

That symmetry is worth sitting with, because it reframes what peer-to-peer is actually for. **It is not what makes offline possible — a local server already does that.** What it buys is not needing the local server: no machine to designate, nothing to install, nobody wondering which laptop is the one that must stay awake. That is a genuine and underrated benefit at a tournament, and it is a different claim from "works without internet".

### What that is actually worth, which is not "broadcasting offline"

**No internet means no stream.** Whatever the overlays are doing on the local network, nothing reaches an audience without an uplink, and it would be easy to read the section above as claiming otherwise. It does not.

What running offline actually buys is two narrower things, and the second is the real one.

**Local recording.** A venue with no usable uplink can still record a properly graphicked programme to disk and publish it afterwards — which is what a great many tournaments do anyway. Today that fails for the same reason a stream would: the overlays stop updating, so the recording is of a scoreboard that stopped.

**Fault tolerance against an uplink that comes and goes**, which is the common case and much more valuable than the rare one. A venue's internet does not usually fail cleanly at the start; it degrades in the second half. A local-first system carries on: the desks keep talking, the score keeps updating, the overlays keep drawing, the recording stays correct, and when the connection returns the stream resumes with graphics that never stopped being true. A system routing every message through a hosted relay loses all of that and — worse, in this project's terms — loses it *silently*, with a scoreboard that looks entirely normal while asserting a score from four minutes ago.

That reframing matters for prioritising: this is not a feature that unlocks a new kind of event. It is **insurance on the one dependency nobody at a sports venue controls.**

### Where it matters most: the uplink dying mid-broadcast

The strongest case for peer-to-peer here is not a venue with no internet. It is a venue whose internet **stops working during the second half**, which is far more common and much worse — because a system that loaded fine and then silently stops updating is the failure this project keeps naming.

A local-first design degrades correctly there: the desks keep talking, the score keeps updating, the overlays keep drawing, and the only thing lost is whatever the remote service was doing. A design that routes every message through a hosted relay loses the broadcast.

**And it argues for a service worker**, which is cheap and independently useful. Cache the interfaces on first load and a browser source that reloads with no uplink still comes up — instead of a blank canvas, which is what it does today.

## 7. Two tiers, because they are not the same client

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

## 8. What it would cost in code, and what it would cost strategically

Two objections that arrive together, and the second is the serious one.

### "Does this mean moving all the PHP into JavaScript?"

Less than it sounds, and the shape of the answer is more interesting than the size.

**The pages are already JavaScript applications.** `commentator.php` is 4,476 lines of which about 80 run in PHP — the rest is markup, CSS and browser code. Same for the Studio and the stage. What the PHP does there is inject config and validate a query parameter. Those files do not need migrating; they need their header replaced by a fetch.

**The reading side has already moved.** `shared/` holds 2,408 lines of JavaScript against 2,713 of PHP, and the JavaScript is the part that decides things: the ratio and its ABBA pattern, the stoppage window, the line grouping, timeouts remaining, the payload provider, declared-value reconciliation. `AGENTS.md` has been pushing derivations there for a while, for testing reasons, and the effect is that the interesting logic is already in the language this design would need it in.

**A large slice of the PHP would not move, it would vanish.** Every store carries file locking, atomic temp-file-and-rename, room eviction and staleness sweeps — around a dozen lines each of pure storage concern, plus the structure around them. With no file, there is no `flock`, no partial write to guard against, no LRU.

**What genuinely has to move is the validation**, and it is the part worth being careful about: roughly forty entry points across the three big stores. Dropping a card in a slot it does not fit, refusing a ratio that is not a ratio, truncating a field, cleaning an event list, rejecting a stale `rev`. That is not much code. It is, however, the code with the history in it.

### The strategic cost, which is larger

**Doing this creates a second implementation of every rule, in a second language.** Hosted mode keeps its PHP stores, because there is a server and it is the authority. Peer-to-peer mode gets JavaScript ones, because there is not. Same rules, twice, diverging quietly — which is precisely the failure this project has spent its whole life paying down: `readJson` in three places with only one of them marking a failure fatal, the ratio printed two different ways before `shared/ratio.js`, the URL layout written into every page.

There are only two honest ways out, and both cost something.

**Either the JavaScript becomes the only implementation** and the PHP stores are reduced to dumb byte buckets that accept whatever a client sends. That keeps the rules in one place — but it contradicts a rule this project holds deliberately: *"the store is the authority, not the UI. Never enforce a rule only in `index.php`."* That rule exists because a check that lives only in a page can be bypassed by anything that is not that page.

**Or peer-to-peer stays a mode of the standalone build only**, where the participants are a small room that already shares a code, and hosted mode never gets it. Narrower, honest, and it leaves the duplication as a real cost rather than a hidden one.

### And it changes the relationship with Live! — in both directions

This is the part worth deciding deliberately rather than discovering.

**The concern.** Today this is a **bridge**. It reads Live!, adds what Live! does not record, and [`UPSTREAM.md`](UPSTREAM.md) asks for those things to be recorded upstream — possession, the first point's ratio, players per side, FMP/MMP matchings. `STUDIO.md` §3.5 states the posture plainly: build the overlay-local version because it works today, and *prefer the upstream data the moment it exists*. A browser-held, peer-to-peer, JavaScript-native system is not a bridge; it is a **system of record** that happens to read Live!. It is harder to argue Live! should record possession when the thing asking has built its own place to put it, and upstream capture is better on every axis except build cost — it survives the broadcast and reaches every game rather than only the covered ones.

**The counter, which is stronger.** The local-first capability is not a divergence from Live!. It is **the most useful thing this project could offer it.**

UltiOrganizer's Scorekeeper runs on a phone, at a pitch, and pitches are in parks and on playing fields where coverage is one bar. It is 21 files of server-rendered form posts — `<form method='post' data-ajax='false'>` and a redirect — with **no service worker, no manifest, and no `localStorage`, `IndexedDB` or `navigator.onLine` anywhere in it**. Every goal, timeout and halftime needs a working connection at the moment it is pressed. That is the same dependency this section is about insuring against, in the place where it does the most damage: a clock nobody could start, and goals entered from memory twenty minutes later that every consumer downstream then treats as fact.

Capture in the phone and sync when wifi appears is precisely the problem §1 and §2 solve, and the sport's data shape makes it unusually safe: a scoresheet is goals keyed by the point number they create, so a retry cannot double-count and two scorekeepers converge instead of fighting.

**So the honest reading is that this work has two possible destinations, and the better one is upstream.** Built here, it makes the overlays fault-tolerant for the games somebody is covering. Built in Scorekeeper, it fixes *every* game at every tournament, and the overlays inherit it along with everybody else — and one of the main reasons to build a parallel system of record here disappears.

That is written up in [`UPSTREAM.md`](UPSTREAM.md) as a point of shared interest rather than a request: nobody is waiting on it, the overlays work without it, and the reason to mention it at all is that both projects would otherwise solve the same problem twice.

It does not settle whether to build the relay. It does mean the question is no longer "does this pull us away from Live!" but **"where should this capability live?"** — and that is a much better question.

## 9. What it would cost to find out

The three things that would settle it, in order of what they would rule out:

1. **Run `tests/selftest.php` on real switcher hardware**, with a WebSocket panel added. It already answers "does this device run JS at all"; extending it to "does this device hold a socket" is small, and it is the answer everything else depends on. If the boxes people actually broadcast with cannot hold a connection, the relay design is settled before it is designed.
2. **Write the merge rules down as merge rules.** They exist as five separate store implementations that happen to be compatible. Stating them once — per-field LWW here, grow-only there, single-writer with a rev for show state — is worth doing whether or not any of this is built, because it is the thing a second implementation would get wrong.
3. **Prototype the room protocol against the existing stores**, not against a new model. If a relay can drive today's `notes`, `lines` and `possession` shapes unchanged, the claim in §1 is true; if it cannot, it was optimism.
4. **Test a peer connection on venue wifi**, not on a home network or a hotspot. Client isolation and mDNS resolution are what decide whether §5 and §6 are designs or wishes, and neither can be discovered anywhere except on the kind of network the product runs on.

The first of those is the one that matters most, and it is worth saying why: **it is the only question here whose answer changes the architecture rather than the effort.** If a switcher's browser source can hold a data channel, the offline design in §6 is complete. If it cannot, every version of this needs a small server on the network, and that server already exists.

## 10. The honest cost comparison

The current design is already close to free: flat files and PHP, no database, no Composer ([`STANDALONE.md`](STANDALONE.md) §6). A small box runs many tournaments today.

So the saving from a relay is not compute — it is **obligation**. No data at rest, no retention policy, no restore path, no "can you delete my tournament". For a service run by a volunteer or a federation rather than a company, that is the difference between something you can offer and something you have to administer. That is a real reason to want it, and a better one than cost.

## 11. Open questions

- **Who is authoritative when two people disagree about the score?** §1 says the goal map merges, but merging is not agreeing: two people entering different scorers for point 10 produces a last-writer, not a resolution. Today one person keeps score and that is the answer; a relay does not change it, but it makes disagreement invisible rather than impossible.
- **Does the payload come from a capture, a peer, or the relay?** A recording is a few hundred KB — too big to gossip on every join, small enough to fetch from a URL. Probably it stays an HTTP asset and only the *live* state goes over the room.
- **Which project is this?** §8's last point is the one to settle first, because it decides whether the rest is worth costing. A bridge that runs standalone when it must, or a standalone product that can import from Live!. Everything else here follows from the answer.
- **Can the rules live in one place?** If peer-to-peer validation is written in JavaScript and the PHP stores stay authoritative, the same forty checks exist twice. If the PHP is reduced to a byte bucket, they exist once and `AGENTS.md`'s "the store is the authority, not the UI" stops being true. There may be a third option — the same rules compiled or transcribed with a conformance test both sides must pass — but it should be found before the second implementation is written, not after.
- **Does this dissolve the licensing question or sharpen it?** [`STANDALONE.md`](STANDALONE.md) §9 already flags that a mode running without Live! is a question for the people who signed its Terms of Use. Offering it as a service to others is a larger version of the same question, and it should be asked before anything is built rather than after.
