/**
 * Every relative link in the documentation resolves.
 *
 * The docs here are load-bearing — most of what they hold is *why* something is
 * the way it is — and they cross-reference each other heavily. A rename breaks a
 * link silently: nothing fails until somebody follows it and finds nothing.
 *
 * This lived as a shell one-liner inside `.github/workflows/ci.yml`, which had
 * two problems. It could not be run locally without copying it out of YAML, so
 * in practice it was copied out of YAML repeatedly; and a check nobody can run
 * is a check nobody trusts.
 *
 *   node tests/links.mjs          # report and exit non-zero on a broken link
 *   node tests/links.mjs --list   # every link it checked, for confidence
 */
import { readFileSync } from 'node:fs';
import { globSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.join(import.meta.dirname, '..');

/**
 * Extensions worth checking. Deliberately a list rather than "anything with a
 * dot": a link to `example.com` in prose is not a file, and treating it as one
 * would make this noisy enough to be switched off.
 */
const CHECKED = /\.(md|sql|conf|png|gif|jpg|svg|sh|php|js|mjs|json|yml|yaml|css|txt)$/i;

const files = globSync('**/*.md', {
  cwd: ROOT,
  exclude: (name) => name === 'node_modules' || name === 'vendor' || name === 'test-results',
});

let broken = 0;
let checked = 0;
const listing = process.argv.includes('--list');

for (const rel of files.sort()) {
  const full = path.join(ROOT, rel);
  const dir = path.dirname(full);
  const body = readFileSync(full, 'utf8');

  // Markdown inline links only. Reference-style links and bare URLs are not
  // paths, and an image's ![alt](src) matches the same shape.
  for (const m of body.matchAll(/\]\(([^)\s]+)\)/g)) {
    const raw = m[1];
    if (/^(https?:|mailto:|#|data:)/i.test(raw)) continue;

    // A fragment is a position inside the target, not part of its name.
    const target = raw.split('#')[0];
    if (target === '') continue;
    if (!CHECKED.test(target)) continue;

    checked += 1;
    const resolved = path.resolve(dir, decodeURIComponent(target));
    let ok = true;
    try {
      readFileSync(resolved);
    } catch (e) {
      // A directory is a legitimate target and reads as EISDIR, not ENOENT.
      ok = e.code === 'EISDIR';
    }

    if (!ok) {
      console.error(`${rel}: broken link -> ${raw}`);
      broken += 1;
    } else if (listing) {
      console.log(`ok  ${rel} -> ${raw}`);
    }
  }
}

if (checked === 0) {
  // A check that silently examines nothing passes forever. This has happened
  // to this project before, in a different check, which is why it is here.
  console.error('No links were checked — the matcher is wrong, not the docs.');
  process.exit(1);
}

if (broken) {
  console.error(`\n${broken} broken link${broken === 1 ? '' : 's'} in ${files.length} documents.`);
  process.exit(1);
}

console.log(`${checked} relative links across ${files.length} documents all resolve.`);
