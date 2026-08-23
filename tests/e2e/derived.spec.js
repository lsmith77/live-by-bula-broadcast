// @ts-check
/**
 * Things the overlays work out rather than read.
 *
 * Every case here covers a number that exists nowhere in UltiOrganizer and is
 * derived from something else: turnover counts and break-chance conversion from
 * the possession log, the game on a field from a reservation join, the gender
 * ratio from the point number. Derived numbers are exactly where a broadcast can
 * assert something untrue, so the assertions are as much about what these
 * refuse to claim as about what they show.
 */
const { test, expect } = require('@playwright/test');
const {
  GAME_ID, loginAsAdmin, readPossession, writePossession,
  writeShow, withShowRestored,
} = require('./helpers');

/** Put the possession log into a known state, and restore it afterwards. */
async function withPossession(page, events, body) {
  const before = await readPossession(page);
  try {
    // Disable first: turning the mode ON does not clear the log, so without
    // this a test inherits whatever the last one (or a human) left behind and
    // its counts are somebody else's.
    await writePossession(page, { enabled: false, game: GAME_ID });
    await writePossession(page, { enabled: true, game: GAME_ID });
    for (const e of events) {
      await writePossession(page, { game: GAME_ID, score: e.score, defence: e.d });
    }
    await body();
  } finally {
    // Disabling drops the log, which is the only way to clear it.
    await writePossession(page, { enabled: false, game: GAME_ID });
    if (before.enabled) await writePossession(page, { enabled: true, game: GAME_ID });
  }
}

test.describe('possession-derived numbers', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('the ribbon counts turnovers, and only once there are enough to matter', async ({ page }) => {
    // One change of hands is noise; the ribbon stays quiet below two.
    await withPossession(page, [{ score: '9-6', d: true }], async () => {
      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await page.waitForTimeout(2500);
      await expect(page.locator('#ribbon')).not.toContainText('turnover');
    });

    await withPossession(page, [
      { score: '9-6', d: true }, { score: '9-6', d: false },
      { score: '9-6', d: true }, { score: '9-6', d: false },
    ], async () => {
      await page.goto(`/s/${GAME_ID}`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await expect(page.locator('#ribbon')).toContainText('4 turnovers this point', { timeout: 8000 });
    });
  });

  test('an untracked game says nothing about turnovers', async ({ page }) => {
    await writePossession(page, { enabled: false, game: GAME_ID });
    await page.goto(`/s/${GAME_ID}`);
    await expect(page.locator('#scoreboard')).toBeVisible();
    await page.waitForTimeout(2500);
    // Not "0 turnovers": nobody was watching, so there is nothing to report.
    await expect(page.locator('#ribbon')).not.toContainText('turnover');
  });

  test('a break chance survives into the compact bug', async ({ page }) => {
    await withPossession(page, [{ score: '9-6', d: true }], async () => {
      await page.goto(`/s/${GAME_ID}?size=compact`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      const callout = page.locator('.callout:not(.hide)').first();
      await expect(callout).toContainText(/break chance/i, { timeout: 8000 });
      await expect(callout).toBeVisible();
    });
  });

  test('the standing tag stays out of the compact bug', async ({ page }) => {
    // ON DEFENCE is ambient context, true all point. The compact variant is
    // chosen for being unobtrusive, so only real events earn space in it.
    await withPossession(page, [{ score: '9-6', d: false }], async () => {
      await page.goto(`/s/${GAME_ID}?size=compact`);
      await expect(page.locator('#scoreboard')).toBeVisible();
      await page.waitForTimeout(2500);
      await expect(page.locator('.callout.standing')).toBeHidden();
    });
  });
});

test.describe('break-chance conversion on the summary cards', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page, test); });

  test('the card carries the denominator with the number', async ({ page }) => {
    await withShowRestored(page, async () => {
      await withPossession(page, [
        { score: '0-0', d: true }, { score: '1-0', d: true }, { score: '1-1', d: false },
      ], async () => {
        await writeShow(page, [
          { id: 'postgame', slot: 'center', visible: true, params: {} },
        ], { game: GAME_ID });

        await page.goto(`/s/${GAME_ID}/overlay`);
        const card = page.locator('.summarycard');
        await expect(card).toBeVisible({ timeout: 15000 });

        // "n of m brk" rather than a bare break count, once anything is tracked.
        await expect(card.locator('.summarymeta').first()).toContainText(/\d+ of \d+ brk/);

        // And the footer must say how much of the game that covers. A rate over
        // three points reads identically to one over the whole game without it.
        await expect(card.locator('.summaryfoot'))
          .toContainText(/possession tracked for \d+ of \d+ points/);
      });
    });
  });

  test('with nothing tracked the card shows breaks and claims no rate', async ({ page }) => {
    await withShowRestored(page, async () => {
      await writePossession(page, { enabled: false, game: GAME_ID });
      await writeShow(page, [
        { id: 'postgame', slot: 'center', visible: true, params: {} },
      ], { game: GAME_ID });

      await page.goto(`/s/${GAME_ID}/overlay`);
      const card = page.locator('.summarycard');
      await expect(card).toBeVisible({ timeout: 15000 });
      await expect(card.locator('.summarymeta').first()).toContainText(/\d+ brk/);
      await expect(card.locator('.summarymeta').first()).not.toContainText(' of ');
      await expect(card.locator('.summaryfoot')).not.toContainText('possession tracked');
    });
  });
});

test.describe('following a field', () => {
  test('resolves to the game in progress', async ({ page }) => {
    // Field 1 carries both a finished game and the live one.
    await page.goto('/s/field/1');
    await expect(page.locator('#scoreboard')).toBeVisible({ timeout: 15000 });
    const names = await page.locator('.team-name').allTextContents();
    expect(names.filter(Boolean).length).toBe(2);
    await expect(page.locator('#errorMessage')).not.toContainText('No game found');
  });

  test('falls forward to the next scheduled game when none is live', async ({ page }) => {
    // Field 2 has only a scheduled game. Showing who is about to play beats
    // going blank while a camera operator frames up.
    await page.goto('/s/field/2');
    await expect(page.locator('#scoreboard')).toBeVisible({ timeout: 15000 });
    const names = await page.locator('.team-name').allTextContents();
    expect(names.filter(Boolean).length).toBe(2);
  });

  test('refuses to guess when the field has no games', async ({ page }) => {
    // The failure that matters: silently following a different field would put
    // the wrong game on air and look entirely normal.
    await page.goto('/s/field/99');
    await expect(page.locator('#errorMessage')).toContainText('No game found on field 99', { timeout: 15000 });
  });

  test('the stage follows a field too', async ({ page }) => {
    await page.goto('/s/field/1/overlay');
    await expect(page.locator('.mount.shown').first()).toBeVisible({ timeout: 15000 });
  });
});

test.describe('gender ratio', () => {
  test('the ABBA pattern matches UltiOrganizer’s own scoresheet', async ({ page }) => {
    await page.goto(`/c/${GAME_ID}`);
    await page.waitForTimeout(1500);

    // pdfscoresheet.php marks points 1, 4, 5, 8, 9, 12 … as repeating point 1's
    // ratio. Same rule, so a commentator and a scorekeeper cannot disagree.
    const slots = await page.evaluate(() => {
      const abba = (i) => (i % 4 === 0 || (i - 1) % 4 === 0) ? 'A' : 'B';
      return Array.from({ length: 13 }, (_, k) => abba(k + 1)).join('');
    });
    expect(slots).toBe('ABBAABBAABBAA');
  });

  test('is silent in a division that is not mixed', async ({ page }) => {
    // The fixture division is Open. A ratio panel there would be nonsense.
    await page.goto(`/c/${GAME_ID}`);
    await page.locator('#tabPlay').click();
    await page.waitForTimeout(1500);
    await expect(page.locator('.abba')).toHaveCount(0);
  });
});
