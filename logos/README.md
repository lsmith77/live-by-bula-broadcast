# Team logos

Drop a file named after the team id. Nothing to configure — the file being here is the configuration.

    300.svg 301.png

Accepted: `svg`, `png`, `webp`, `jpg`, `jpeg`, `gif`. SVG and PNG carry transparency, which is what a broadcast overlay wants.

The scoreboard falls back to Live!'s own `teams.*.photos[]` when no file is present, but those are usually wide squad photos rather than a square mark.

**Contents are gitignored.** Team ids are meaningful only within one installation, so these files are operator data, not code. For the development fixture, copy the sample marks in:

    cp fixtures/logos/*.svg logos/
