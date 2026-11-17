document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input || input.form !== toggle.form) return;

    const updateVisibility = () => {
      input.type = toggle.checked ? 'text' : 'password';
    };
    toggle.addEventListener('change', updateVisibility);
    updateVisibility();

    // Hide passwords again when returning through browser history.
    window.addEventListener('pageshow', () => {
      toggle.checked = false;
      updateVisibility();
    });
  });
});
