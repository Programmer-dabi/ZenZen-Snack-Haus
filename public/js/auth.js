// Attach password visibility controls after the sign-in or registration form is available.
document.addEventListener('DOMContentLoaded', () => {
  // Each checkbox identifies its own password field so password and confirmation toggles work independently.
  document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    const input = document.getElementById(toggle.dataset.passwordToggle);
    // Ignore a toggle unless its target input exists in the same form.
    if (!input || input.form !== toggle.form) return;

    // Change only the input display type; the actual password value is kept in the same field.
    const updateVisibility = () => {
      input.type = toggle.checked ? 'text' : 'password';
    };
    // Apply visibility immediately and whenever the checkbox changes.
    toggle.addEventListener('change', updateVisibility);
    updateVisibility();

    // Hide passwords again when returning through browser history.
    // When history restores a form, uncheck the toggle and hide its password again.
    window.addEventListener('pageshow', () => {
      toggle.checked = false;
      updateVisibility();
    });
  });
});
