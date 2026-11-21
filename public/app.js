const form = document.querySelector("[data-review-form]");

form?.addEventListener("submit", () => {
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  button.firstChild.textContent = button.dataset.loadingLabel;
  form.querySelector('[role="status"]').hidden = false;
  form.setAttribute("aria-busy", "true");
});

window.addEventListener("pageshow", () => {
  if (!form) return;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = false;
  button.firstChild.textContent = button.dataset.label;
  form.querySelector('[role="status"]').hidden = true;
  form.removeAttribute("aria-busy");
});
