# Broadcast overlays for Live! by BULA

A broadcast graphics layer for Ultimate tournaments, extending [Live! by BULA](https://github.com/layoutd/live-by-bula). It turns the data an event is already keeping — score, clock, rosters, goals and assists — into graphics a video switcher can put on air, and gives the people running the broadcast somewhere to control them from.

Three surfaces, for three different people:

- **Scoreboard** — a broadcast bug on a transparent 1920×1080 canvas. One URL, point a browser source at it, done.
- **Studio** — a full-frame stage hosting several cards at once, plus the control page that decides what is on it.
- **Commentator** — a second screen, never on air: rosters, stats, who is on the field.

Everything reads through Live!'s public JSON API. No overlay touches the database, which is what makes this a drop-in that survives a Live! upgrade.

## Requirements

A working [Live! by BULA](https://github.com/layoutd/live-by-bula) 3.0.6 installation with a published season. Live! v3 in turn requires [UltiOrganizer](https://github.com/ktolonen/ultiorganizer) 4, PHP 8.3+ and MariaDB 10.11+.

Note that Live! by BULA has its own prerequisite: its [Terms of Use](https://github.com/layoutd/live-by-bula/blob/main/Terms%20of%20Use%20-%20Live%20by%20BULA.pdf) must be signed and returned to live@beachultimate.org before use.

## Install

Unzip or clone into `live/overlays/` — beside Live!'s own code, the same way Live! is installed beside UltiOrganizer's — and make `conf/` writable by the web server. There is no build step.

Full instructions, URLs, and the reasoning behind the design: **[docs/README.md](docs/README.md)**.

## Licence

CC BY-NC-ND 4.0, matching Live! by BULA. See [LICENSE.txt](LICENSE.txt).
