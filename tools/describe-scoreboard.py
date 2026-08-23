#!/usr/bin/env python3
"""
Describe a scoreboard screenshot numerically.

Written because a reference design cannot always be looked at directly — but its
measurable properties are what actually transfer into CSS anyway: palette,
corner radius, how the bug divides horizontally, where the heavy type sits, and
how much of the frame it occupies.

    live/overlays/tests/describe-scoreboard.py reference.png [more.png ...]

Reports, per image:
  - size and aspect
  - dominant colours with coverage, as hex
  - background colour and estimated corner radius
  - a column profile: where ink is dense, which is where the blocks are
  - a row profile: the horizontal bands (name row, score row, status row)
  - contrast of the lightest ink against the background
"""

# Needs Pillow, which is not declared anywhere: this is an occasional design
# tool rather than part of the suite. `pip install Pillow` if the import fails.
import sys
from collections import Counter

try:
    from PIL import Image
except ImportError:
    sys.exit("Pillow is required: pip install Pillow")


def hex_of(rgb):
    return "#%02X%02X%02X" % rgb[:3]


def luma(rgb):
    return 0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]


def contrast_ratio(a, b):
    def channel(c):
        c = c / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4

    def rel(rgb):
        r, g, bl = (channel(x) for x in rgb[:3])
        return 0.2126 * r + 0.7152 * g + 0.0722 * bl

    la, lb = rel(a), rel(b)
    lo, hi = sorted((la, lb))
    return (hi + 0.05) / (lo + 0.05)


def quantise(rgb, step=24):
    return tuple(min(255, (c // step) * step + step // 2) for c in rgb[:3])


def bar(value, peak, width=48):
    filled = 0 if peak == 0 else int(round(width * value / peak))
    return "#" * filled + "." * (width - filled)


def describe(path):
    img = Image.open(path).convert("RGB")
    w, h = img.size
    px = img.load()

    print("=" * 72)
    print(f"{path}")
    print(f"  {w}x{h}  aspect {w/h:.2f}:1")
    print(f"  at 1920x1080 this is {w/1920*100:.1f}% of frame width, "
          f"{(w*h)/(1920*1080)*100:.2f}% of frame area")

    # -- palette ----------------------------------------------------------
    counts = Counter()
    for y in range(h):
        for x in range(w):
            counts[quantise(px[x, y])] += 1
    total = w * h

    print("\n  dominant colours")
    for rgb, n in counts.most_common(8):
        share = n / total * 100
        if share < 0.8:
            break
        print(f"    {hex_of(rgb)}  {share:5.1f}%  {bar(share, 100, 30)}")

    background = counts.most_common(1)[0][0]
    print(f"\n  background (most common): {hex_of(background)}  luma {luma(background):.0f}")

    # -- ink: pixels that differ meaningfully from the background ---------
    def is_ink(rgb):
        return abs(luma(rgb) - luma(background)) > 40

    # brightest and most saturated ink, i.e. type and accent colours
    brightest = None
    accents = Counter()
    for y in range(h):
        for x in range(w):
            c = px[x, y]
            if not is_ink(c):
                continue
            if brightest is None or luma(c) > luma(brightest):
                brightest = c
            mx, mn = max(c), min(c)
            if mx - mn > 60:           # saturated: a brand or state colour
                accents[quantise(c)] += 1

    if brightest:
        print(f"  brightest ink: {hex_of(brightest)}  "
              f"contrast vs background {contrast_ratio(brightest, background):.1f}:1"
              f"  (4.5 is the readable floor)")
    if accents:
        print("  saturated accents:")
        for rgb, n in accents.most_common(4):
            if n / total * 100 < 0.15:
                break
            print(f"    {hex_of(rgb)}  {n/total*100:5.2f}%")

    # -- corner radius: how far in the fill starts on the top edge ---------
    radius = 0
    for x in range(min(40, w // 2)):
        if is_ink(px[x, 0]) or px[x, 0] != px[w // 2, 0]:
            radius += 1
        else:
            break
    print(f"  estimated corner inset: ~{radius}px "
          f"({radius/h*100:.0f}% of height)")

    # -- row bands: horizontal strips carrying ink ------------------------
    rows = []
    for y in range(h):
        rows.append(sum(1 for x in range(w) if is_ink(px[x, y])))
    peak = max(rows) or 1

    print("\n  row profile (horizontal bands — name / score / status rows)")
    step = max(1, h // 24)
    for y in range(0, h, step):
        chunk = rows[y:y + step]
        avg = sum(chunk) / len(chunk)
        print(f"    y{y:4d}  {bar(avg, peak)}  {avg/w*100:4.1f}% ink")

    # -- column bands: vertical blocks ------------------------------------
    cols = []
    for x in range(w):
        cols.append(sum(1 for y in range(h) if is_ink(px[x, y])))
    cpeak = max(cols) or 1

    # Collapse into runs of "has ink" vs "gap" to expose the block structure.
    threshold = cpeak * 0.08
    runs = []
    state = cols[0] > threshold
    start = 0
    for x, v in enumerate(cols):
        now = v > threshold
        if now != state:
            runs.append((state, start, x - 1))
            state, start = now, x
    runs.append((state, start, w - 1))

    print("\n  column blocks (inked spans and the gaps between them)")
    for inked, a, b in runs:
        if b - a < 3:
            continue
        kind = "block" if inked else "gap  "
        print(f"    {kind} x{a:4d}-{b:<4d} {b-a+1:4d}px  {(b-a+1)/w*100:5.1f}% of width")

    print()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    for path in sys.argv[1:]:
        describe(path)
