# Adding an overlay after the fact

**Status:** designed, partly built. The deterministic render mode (`?at=`) exists and is tested; the command-line tool described here does not exist yet.

Most games are recorded by somebody with a camera and no video switcher. The footage is fine, the tournament data is in UltiOrganizer, and there is no scoreboard on the picture. This is about putting one there before the video is uploaded — using the same overlay the live broadcast would have used, driven by the same configuration the Studio exports.

## The problem is alignment, and it is the whole problem

The video has one timeline and the game has another. Everything else here is mechanical; getting these two to agree is not.

UltiOrganizer offers **two** timelines per goal, and both reach the API:

| field | what it is | catch |
|---|---|---|
| `time` | game-clock seconds | the game clock **pauses**, and the video does not |
| `timestamp` | wall clock when the goal was *entered* | lags the goal by however long the scorekeeper took |

Wall clock is the natural match for video, and aligning against `timestamp` needs no pause modelling at all. But it is only meaningful when the game was scored **live**: `GameAddScore()` is also called from `user/addscoresheet.php`, the paper-sheet entry form, so a game typed up afterwards has every goal stamped within a minute of the others. And `hide_time_on_scoresheet` is a per-season setting, so some tournaments record no goal times whatsoever.

Three data qualities follow, and the tool has to detect which it has rather than assume:

| | `timer_start` | goal `time` | goal `timestamp` | what alignment costs |
|---|---|---|---|---|
| **Live Scorekeeper** | set | good | good | one anchor, fully automatic |
| **Sheet typed up later** | null | approximate | useless, all clustered | one anchor, plus care across pauses |
| **Times hidden or blank** | null | absent | useless | one anchor **per goal** — there is no data to interpolate from |

The third row is not a failure mode to engineer around. There is genuinely nothing to derive from, and the honest answer is that somebody marks the goals while scrubbing. For a fifteen-point game that is fifteen marks.

## Anchors

An anchor says "goal *n* happens at *this* position in the video". **One is required; more are optional.**

- **One anchor** fixes an offset. Video position = goal time + offset, scale 1.
- **Two or more** give a piecewise fit between them, which is also how the half-time break is handled: the game clock stops there and the video does not, so an anchor either side keeps both halves honest.
- **One per goal** is the degenerate case above, and needs no special mode — the same mechanism, used exhaustively.

Every goal that is *not* an anchor becomes a free check. That is the point of allowing more than one.

## Residuals, and when to refuse

A scoreboard whose score changes well after the goal looks **broken** — worse than no scoreboard at all. So the tool reports where the fit disagrees with the data, against these thresholds:

| residual | verdict |
|---|---|
| under 10s | fine, silent |
| 10–20s | warned |
| over 20s | refuse to render |

```
aligned on goals 1 and 15   scale 1.0004   offset +187.2s
  goal  2   predicted 04:07   residual +0.4s
  goal  7   predicted 21:55   residual -1.1s
  goal 11   predicted 34:02   residual +9.8s   <-- check this
```

This is what catches the things that actually happen: a recording split across files, dropped frames, a scorekeeper who fell behind for a point, an anchor placed on the wrong goal.

**Build the contact sheet before the renderer.** One frame rendered at each goal moment, tiled into a single image: ten seconds of looking confirms the alignment before anyone commits to a full render. It is the cheapest useful part of this whole tool.

## Rendering

Reuse the overlay. A second renderer would drift from the live one, and then a post-produced broadcast would not look like a live one — which defeats the purpose.

The mechanism is `?at=<clock seconds>&goals=<count>&phase=pre|live|final` (STUDIO.md §9.4): the scoreboard fetches its payload once, draws one frame of the state it was handed, and stops. It deliberately does **not** work out which goals have happened by the time given — that is the alignment question, and it lives out here where the anchors are.

Two things make frame generation cheap:

- **One frame per second, not per video frame.** The bug only changes when the clock ticks. Ninety minutes is 5,400 frames, not 135,000; ffmpeg holds each one for the right duration.
- **Render the crop, not the canvas.** The bug's true bounding box is already computed for the README screenshots. Compositing a few hundred KB of small PNGs at a fixed offset beats writing 5,400 full-frame images with alpha.

Output either burned in with ffmpeg's `overlay` filter, for the upload-and-done case, or as ProRes 4444 / WebM with alpha for somebody who edits.

## Configuration

The Studio's export (STUDIO.md §9.5) is the input. The card set and auto timings a broadcast used live are the ones its recording should get, so authoring them twice would only be a way to make them differ.

```json
{
  "kind": "live-by-bula-broadcast/postproduce",
  "version": 1,
  "baseUrl": "http://localhost:8080",
  "defaults": {
    "stage": { "kind": "live-by-bula-broadcast/stage", "version": 1, "cards": [] },
    "fps": 25
  },
  "videos": [
    {
      "file": "field1-semi.mp4",
      "game": 702,
      "anchors": [{ "goal": 1, "at": "03:07" }, { "goal": 15, "at": "48:22" }],
      "out": "field1-semi-overlay.mp4"
    }
  ]
}
```

A file rather than flags, because the same defaults apply to every game from one tournament and only the anchors change per video.

Three subcommands, in the order they are useful: `check` (fit and report residuals), `contact` (the sheet), `render` (frames plus composite).

## What is not solved

- **A game with no recorded goal times still needs a mark per goal.** No amount of tooling substitutes for data that was never captured.
- **The pre-game, half-time and full-time cards need their own anchors**, or a rule about where they sit relative to the first and last goal. The half-time card in particular wants the break, and the break is exactly the part of the timeline UltiOrganizer does not describe.
- **Nothing here validates that the video is of the game it claims to be.** The contact sheet is the only check, and it is a human one.
