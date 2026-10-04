/*
 * Small interface feedback (transitions.dev recipes). No library, loaded with `defer`.
 *
 * 1. While a lookup form waits for the server (GitHub can take a second or two), its button
 *    label swaps to "Looking up…" and shimmers: the "text states swap" and "shimmer text" recipes.
 * 2. A field the server marked invalid stops looking invalid as soon as you edit it.
 */
'use strict';

/** transitions.dev text swap: the old text exits up with a blur, the new one enters from below. */
function swapText(label, next, working) {
    const duration = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--text-swap-dur')) || 150;
    label.classList.add('is-exit');
    setTimeout(() => {
        label.textContent = next;
        label.dataset.text = next; // the shimmer's ::before layer copies this text
        label.classList.toggle('t-shimmer', working);
        label.classList.remove('is-exit');
        label.classList.add('is-enter-start');
        void label.offsetHeight; // force a reflow so the next change animates
        label.classList.remove('is-enter-start');
    }, duration);
}

document.querySelectorAll('form[data-pending]').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    const label = button ? button.querySelector('.t-text-swap') : null;
    if (!label) return;
    const idleText = label.textContent;

    form.addEventListener('submit', (event) => {
        if (button.getAttribute('aria-disabled') === 'true') {
            event.preventDefault(); // already waiting: ignore a second click
            return;
        }
        button.setAttribute('aria-disabled', 'true');
        swapText(label, form.dataset.pending, true);
    });

    // The Back button can restore this page from the browser's cache, still "waiting": reset it.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        button.removeAttribute('aria-disabled');
        swapText(label, idleText, false);
    });
});

document.querySelectorAll('input[aria-invalid="true"]').forEach((input) => {
    input.addEventListener('input', () => input.removeAttribute('aria-invalid'), { once: true });
});
