// Form feedback: swap the submit label to "Looking up..." while the server works,
// and clear the invalid state on a field as soon as it's edited.
"use strict";

function swapText(label, next, working) {
  const duration =
    parseFloat(
      getComputedStyle(document.documentElement).getPropertyValue(
        "--text-swap-dur",
      ),
    ) || 150;
  label.classList.add("is-exit");
  setTimeout(() => {
    label.textContent = next;
    label.dataset.text = next; // used by the shimmer's ::before
    label.classList.toggle("t-shimmer", working);
    label.classList.remove("is-exit");
    label.classList.add("is-enter-start");
    void label.offsetHeight; // reflow so the enter transition runs
    label.classList.remove("is-enter-start");
  }, duration);
}

document.querySelectorAll("form[data-pending]").forEach((form) => {
  const button = form.querySelector('button[type="submit"]');
  const label = button ? button.querySelector(".t-text-swap") : null;
  if (!label) return;
  const idleText = label.textContent;

  form.addEventListener("submit", (event) => {
    if (button.getAttribute("aria-disabled") === "true") {
      event.preventDefault(); // ignore double submits
      return;
    }
    button.setAttribute("aria-disabled", "true");
    swapText(label, form.dataset.pending, true);
  });

  // reset if the page comes back from the bfcache still in the "waiting" state
  window.addEventListener("pageshow", (event) => {
    if (!event.persisted) return;
    button.removeAttribute("aria-disabled");
    swapText(label, idleText, false);
  });
});

document.querySelectorAll('input[aria-invalid="true"]').forEach((input) => {
  input.addEventListener("input", () => input.removeAttribute("aria-invalid"), {
    once: true,
  });
});
