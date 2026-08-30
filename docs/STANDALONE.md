# Standalone mode — the overlays without UltiOrganizer

These overlays are built for an UltiOrganizer installation with Live! by BULA underneath. **They also run without one.** Every surface — the Studio, the stage, the scoreboard and the commentary desk — will render a game from a recorded payload with no host, no database and no network, and CI proves it on every push.

This document is the current state: what works, how to run it, what is missing, and what the next step is. The reasoning that produced the design is in the code, next to the code.

## 1. Why it exists

A tournament scored on paper. A club streaming a friendly. A showcase game outside the event that owns the software. A federation running Live! where this particular pitch is not in it. The overlays would work in every one of those, and the only thing missing was something to answer "what is game 702".

## 2. What runs today

| | |
|---|---|
| **Reading Live!** | `shared/provider.js` is the one way any page reads the API — seven call sites across three files became one module with one error contract |
| **Reading a recording** | `Provider.recorded()` answers the same questions from a capture on disk. `Provider.fromConfig()` picks, on one setting |
| **Making a recording** | `tests/capture.mjs` drives `Provider.live()` against an instance and writes down what comes back |
| **Serving the pages** | `app.php` — a front controller with an explicit allow-list, the short URLs (`/s/702`, `/c/702`), and the `conf/` rules restated because `php -S` reads no `.htaccess` |
| **Deciding who may change what is on air** | `shared/auth.php`. One function, two answers: Live!'s session when there is a Live!, a local one against a hash in `conf/` when there is not |
| **Signing in** | `login.php`, which 404s under a host because the host owns that door |
| **Knowing where it lives** | `shared/mode.php` — asset and endpoint URLs, rather than `/live/overlays/` written into every page |

Twenty-four tests drive it over HTTP against a tree with no UltiOrganizer above it. They cover the routing, the guards, the login, and a page rendering real payloads with no API call made at all.

## 3. Running it

```
node tests/capture.mjs --game 702 --out fixtures/payloads/dev   # record a game
php -S 0.0.0.0:8080 -t . app.php                                # serve
```

`conf/local-config.php` holds the two settings, and is gitignored and denied over HTTP:

```php
<?php

return [
    'capture' => 'fixtures/payloads/dev',
    'admin_hash' => '<bcrypt hash>',
];
```

Generate the hash with `php -r 'echo password_hash("your password", PASSWORD_BCRYPT);'`. Without `capture` the pages read Live! as usual; without `admin_hash` nothing can change what is on air.

**`app.php` must be the router**, not just a file in the directory. `php -S` reads no `.htaccess`, so the rules that keep `conf/` off the network live in that router — serve the directory without it and the commentary desk's prepared notes, which are notes about named people, are served on request.

## 4. What is missing

Ordered by what would bite first.

| Gap | Consequence | Size |
|---|---|---|
| **No way to create `conf/local-config.php`** | A new installation has no administrator until somebody hand-writes a PHP file containing a hash they generated themselves. It is the first thing anyone hits | small |
| **`.htaccess` still says `RewriteBase /live/overlays/`** | Short URLs work under `php -S`, because `app.php` restates them, and **not** on a standalone Apache | small |
| **The stage requests a Live!-relative logo path** | `entity=config` carries `TV_SCREEN_LOGO_PATH` pointing into `live/conf/`, which is not there — one 404 per load, no tournament logo | small |
| **`tests/selftest` is in the allow-list** | A standalone installation has to ship `tests/` to serve the switcher diagnostic, which is otherwise dev-only | small |
| **Nothing checks a capture is current** | `tests/capture-check.mjs` proves a recording is *whole*, not that it matches today's fixture. That needs a live instance, which CI has not got | small |
| **No editor** | Everything replays a recording. Nothing lets a person create an event, and that is the difference between "runs without a host" and "a tournament can use this" | **large** |

## 5. The next steps

**First, the four small ones.** They are an afternoon between them and they are what makes the current state installable by somebody who is not holding this document. The install path matters most: a mode nobody can configure is a mode nobody has.

**Then the editor, which is the actual project.** It needs, roughly in order:

1. **A store** — `conf/standalone/`, holding an event, its teams, and per-game files. Written in **Live!'s payload shape**, which is the constraint everything else rests on (§7).
2. **An authoring surface** for the things that do not change during a game: the event, the teams with their short names and colours, a game with its field, target score and caps.
3. **Rosters through the CSV that already exists.** The bio round trip already sends each team a file with `Number` and `Name` columns, already refuses another team's file, already validates matchings. Hosted, those columns are decoration. Standalone they become the source of truth — a promotion, not a new mechanism.
4. **Score and clock**, which is [`MATCHCONTROL.md`](MATCHCONTROL.md) and is a surface of its own. The clock is three integers; the score is the `goals` array, and the rule that makes it safe is that a goal is written as *the point it creates*, never as `+1`.

**What can wait:** accumulating totals across games within a standalone event. It is real — it is every "Tournament" number a player sheet shows — but it turns a per-game store into an event database, which is the thing this mode exists to avoid needing.

## 6. What a server needs

The point of this section is that the answer is small. Everything below was read off the code rather than assumed, and the headline is that **standalone needs no database and no Composer** — the two things that make the hosted deployment heavy.

### The floor

| | Requirement | Why |
|---|---|---|
| **PHP** | 8.3 or 8.4 | What CI lints and what the host runs. The code uses no syntax newer than typed properties and arrow functions, so a lower floor is likely and simply untested — do not claim one without testing it. |
| **Extensions** | `json`, `pcre`, `mbstring`, `filter` | The complete list of extension-dependent calls in the project is `json_encode`/`json_decode`, `preg_match`/`preg_replace`, `mb_substr`, `filter_input`, `flock` and `random_int`. All but `mbstring` are bundled and enabled by default. |
| **Composer** | **none** | `vendor/autoload.php` is required by exactly three files, solely to reach `Api\ConfigManager` and `Api\SeasonAccess`. The project's own classes are `require_once`d directly. Replace the auth seam (§3) and the autoloader has nothing left to load. |
| **Database** | **none** | Nothing in this project opens one, in either mode. Hosted mode reaches the database only through Live!'s API over HTTP. |
| **Web server** | Apache with `mod_rewrite` and `AllowOverride All`, or nginx with the rules translated | The `.htaccess` does two jobs: routing the short URLs, and refusing HTTP access to `conf/` except for the one file the stage polls as a static asset. On nginx both become `location` blocks, and **the `conf/` denial is the one that must not be forgotten** — it is what keeps the desk's notes about named players out of a browser. |
| **Filesystem** | `conf/` writable by the web server, on a filesystem where `flock` works | `show.php`, `colors.php`, `possession.php` and `notes.php` do their read-modify-write under `flock` on a shared lock file. NFS and some container volume drivers do not implement it faithfully, and the failure is silent interleaving rather than an error. Note that **`lines.php` has no such lock** — a known gap recorded in `AGENTS.md`, not a decision, and one standalone would inherit unchanged. |
| **TLS** | Needed in practice | A browser source loading an overlay over HTTP from a page served over HTTPS is blocked as mixed content, and some switcher browsers refuse plain HTTP outright. |

### What it has to withstand

Not much, but the shape is unusual: **many small polls, no bursts, and a hard latency requirement on one file.**

| Poller | Interval | What it hits |
|---|---|---|
| Stage — what is on air | ~1s | `conf/show.json` as a **static file**, deliberately not through PHP |
| Possession, and the shared line | 2s | Two routed PHP endpoints |
| Game data | 10s | The payload provider |
| Prepared notes | 15s | A routed PHP endpoint |

One field in use is roughly **one scoreboard browser, one stage browser and one or two commentary desks**, so about 4–6 pollers per pitch. Ten pitches is at most a few hundred requests a minute, nearly all of them conditional GETs for small JSON. Any PHP host from the last decade handles this; a Raspberry Pi on the venue LAN handles this. **The requirement is not throughput, it is the ~1s file.** `conf/show.json` is served by the web server rather than by PHP precisely so that an operator's click feels instant, and anything in front of it — a proxy, a CDN, an aggressive `Cache-Control` — that adds a second of staleness is a second of a graphic staying on air after it was taken off.

### What standalone adds

- **A writable store for the authored data** — `conf/standalone/`, same permissions and the same HTTP denial as the rest of `conf/`.
- **An admin credential of its own.** The host already keeps a bcrypt hash in a PHP config file (`live/conf/LocalConfig.php`), which is exactly the shape to copy: a hash in `conf/`, never a plaintext password, never a value in the repository.
- **A front controller**, which is a single file that defines `UO_ROUTED_VIEW` and dispatches `?view=`. The ten guards stay as they are.

### Where it could run that hosted mode cannot

Worth stating, because it is most of the practical appeal: with no database and no Composer, the deployable artefact is **a directory of PHP files and a writable `conf/`**. That runs on shared hosting, on a laptop with `php -S` at a venue with no uplink, or in a container built `FROM php:8.3-apache` with one `a2enmod rewrite`. A tournament running standalone on a laptop behind the commentary desk is a realistic deployment, and it is the one that makes the mode worth building.

The offline case deserves care rather than a footnote: if the venue has no uplink, team logos, fonts and any CDN asset have to be local already. Worth auditing before promising it.

## 7. The rules that hold all of this together

Four, and they are the reason the modes have not diverged.

**One renderer, one payload shape, two providers.** Nothing above `shared/provider.js` knows which it is talking to. The local shape is Live!'s, warts included — where Live! is inconsistent, standalone is inconsistent the same way. A store that got to be tidy would be a second contract every consumer branches on, and `docs/PLAN.md` already lists the field names that have caught people out.

**Absent is not zero.** Standalone has no tournament totals, no game-by-game history, no blocks, no seeds, no standings, no spirit — there is no history to have. Those fields are *omitted*, never sent as `0`, and every consumer already does the right thing with an omission. A player sheet showing `0 G · 0 A` for somebody who has scored nine over three days is a lie told on air; one showing this game's numbers and no tournament row is true.

**Never write the URL layout into a page.** Ask `Overlays\Mode`. `/live/overlays/` was in every page's asset helper and in five endpoint URLs, and all of it broke the moment this directory was served from a document root.

**`conf/` is closed by default and opened one file at a time.** Two files are public because the stage polls them as static assets at about one second. Everything else has a PHP front door. That rule exists twice — in `.htaccess` and in `app.php`'s router — because `php -S` reads no `.htaccess`, and both copies are tested by making the request.

## 8. Recording format

A capture is a flat directory, one file per request, named after the request so a person can read it — which matters, because the first thing anyone does with a bug report is look inside. For one game that is the game list, the game's detail, the team list, both rosters, `reference`, `config`, and one `playerevents` per player: around sixty files for two 28-player squads.

`manifest.json` carries **when each game was recorded**, and it is not bookkeeping. `timer_start` is absolute unix seconds and the scoreboard computes `now - timer_start`, so a payload recorded on Saturday and replayed on Tuesday shows a game that has been running for three days. `Provider.recorded()` rebases by the age of the capture on every read, which holds a recording at the minute it was taken — a recording of the 14th minute is the 14th minute whenever it is played, and a test asserting on it cannot go stale overnight. `rebase: 'run'` lets the clock advance instead, which is what a demo wants.

Each game keeps its own instant: one directory can hold several recorded minutes apart, and replaying both from a single timestamp puts one of the clocks out by the gap without anything saying so.

**A capture is not a fixture to hand-edit.** Adjust one and it stops being evidence of what Live! sends and becomes a fake with extra steps. Variation belongs in a mutation layer over the recording, which is how `shared/demo.js` already works.

## 9. Open questions

- **The licence.** Live! by BULA is distributed under a signed Terms of Use, and these overlays were built for it. A mode that runs without it is not obviously a circumvention — it serves games Live! was never going to hold — but that is a question for the people who signed, not one to settle by shipping.
- **Where do game ids come from?** Every store here is keyed by them, so they have to be stable. Operator-assigned integers are fine and boring.
- **Does standalone become the more capable mode for mixed?** Several entries in [`UPSTREAM.md`](UPSTREAM.md) are "Live! does not record X" — possession, the first point's ratio, players per side, FMP/MMP matchings. Standalone *is* the system of record, so it could simply record them. That is an odd position for a bridge to end up in, and it makes the upstream asks stronger rather than weaker.
