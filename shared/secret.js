/**
 * A code field that is not readable over somebody's shoulder.
 *
 * The room code is the only thing standing between a passer-by and a commentary
 * desk's shared state — the line selection, and the prepared notes, which are
 * notes about named players (docs/COMMENTATOR.md section 5a). On the Studio it is
 * also the possession-write credential, and possession reaches air.
 *
 * It was rendered in plain text, permanently, on both surfaces. A commentary
 * booth and an operator's station are the two most-walked-past screens at a
 * tournament, and a five-character code is memorable at a glance. Nothing about
 * the code being "a namespace, not a credential" makes that acceptable: a
 * namespace nobody else can guess is exactly what its guarantees rest on, and a
 * code on display is not unguessable, it is published.
 *
 * So: masked by default, revealed deliberately, and re-masked on its own.
 *
 * The auto-hide is the part that matters. "Reveal, read it out, forget to hide
 * it again" is the actual failure mode — a manual toggle alone would spend most
 * of a tournament in the revealed state and be no better than plain text.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Secret = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /** Long enough to read five characters out and have them typed back. */
    var HIDE_AFTER_MS = 30000;

    /**
     * Wire an input and a toggle button into a reveal-and-re-hide pair.
     *
     * Returns { reveal, hide, isRevealed }. Callers should think twice before
     * using `reveal()`: the auto-hide already guarantees the field cannot be left
     * exposed, so "reveal it for them" is a convenience and never a safeguard.
     * The Studio briefly revealed on generating a code and no longer does — the
     * confirmation message already carries the code for six seconds, so revealing
     * the field as well was a second copy of the same thing and a special case
     * for no gain.
     *
     * `opts.label` names the field in the button's accessible label.
     * `opts.hideAfterMs` overrides the auto-hide, and 0 disables it.
     */
    function guard(input, button, opts) {
        opts = opts || {};
        var label = opts.label || 'code';
        var after = opts.hideAfterMs === undefined ? HIDE_AFTER_MS : opts.hideAfterMs;
        var timer = null;
        var shown = false;

        function paint() {
            // Toggling `type` is the portable way to do this. CSS text-security
            // is not standard, and rendering a row of bullets into a text field
            // would have to be undone before every read of `.value`.
            input.type = shown ? 'text' : 'password';
            button.textContent = shown ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', shown ? 'true' : 'false');
            button.setAttribute('aria-label', (shown ? 'Hide the ' : 'Show the ') + label);
            button.title = shown
                ? 'Hide it again'
                : 'Show it long enough to read out. It hides itself again.';
        }

        function hide() {
            if (timer) { window.clearTimeout(timer); timer = null; }
            shown = false;
            paint();
        }

        function reveal() {
            shown = true;
            paint();
            if (timer) { window.clearTimeout(timer); timer = null; }
            if (after > 0) { timer = window.setTimeout(hide, after); }
        }

        button.type = 'button';
        button.addEventListener('click', function () {
            if (shown) { hide(); } else { reveal(); }
        });

        // A password field invites a password manager to offer to save it, which
        // is noise on a shared tournament laptop and would put the code somewhere
        // nobody intended.
        input.autocomplete = 'off';
        input.setAttribute('autocorrect', 'off');
        input.spellcheck = false;
        input.removeAttribute('name');

        paint();
        return { reveal: reveal, hide: hide, isRevealed: function () { return shown; } };
    }

    return { guard: guard, HIDE_AFTER_MS: HIDE_AFTER_MS };
}));
