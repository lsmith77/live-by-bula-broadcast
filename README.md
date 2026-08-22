# Broadcast overlays for Live! by BULA

A broadcast graphics layer for Ultimate tournaments, built on top of
[UltiOrganizer](https://github.com/ktolonen/ultiorganizer) 4.0 and the
**Live! by BULA** addon. It turns the data an event is already keeping — score,
clock, rosters, goals and assists — into graphics a video switcher can put on
air, and gives the people running the broadcast somewhere to control them from.

Three surfaces, for three different people:

- **Scoreboard** — a broadcast bug on a transparent 1920×1080 canvas. One URL,
  point a browser source at it, done.
- **Studio** — a full-frame stage hosting several cards at once, plus the
  control page that decides what is on it.
- **Commentator** — a second screen, never on air: rosters, stats, who is on the
  field.

Everything reads through Live!'s public JSON API. No overlay touches the
database, which is what makes this a drop-in that survives a Live! upgrade.

## Install

Unzip or clone into `live/overlays/` of an UltiOrganizer + Live! installation,
and make `conf/` writable by the web server. There is no build step.

Full instructions, URLs, and the reasoning behind the design:
**[docs/README.md](docs/README.md)**.
