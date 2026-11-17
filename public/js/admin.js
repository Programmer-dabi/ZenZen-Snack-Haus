// Initialize management-page interactions only after Twig has rendered the dialog and its form.
document.addEventListener('DOMContentLoaded', () => {

  // Step: Open the requested admin dialog without changing its server access checks.
  const modal = document.querySelector('[data-admin-modal]');
  if (!modal) return;

  // Promote the rendered dialog to a native modal when supported; otherwise keep its existing open fallback.
  if (typeof modal.showModal === 'function') {
    modal.removeAttribute('open');
    modal.showModal();
  }
  // Treat Escape as leaving the selected management dialog and return to the dashboard.
  modal.addEventListener('cancel', (event) => {
    event.preventDefault();
    window.location.assign('/admin');
  });

  // Only the inventory dialog has a variant selector that needs to synchronize stock fields.
  const form = modal.querySelector('[data-admin-stock-form]');
  if (form) {
    const variant = form.querySelector('[data-stock-variant]');
    // Step: Refresh the displayed stock and expected value when a variant changes.
    variant.addEventListener('change', () => {
      // Use the selected option's saved stock to update both the visible value and stale-form comparison value.
      const stock = variant.selectedOptions[0].dataset.stock;
      form.querySelector('[data-expected-stock]').value = stock;
      form.querySelector('[data-current-stock]').textContent = stock;
      form.querySelector('[data-new-stock]').value = stock;
    });
  }
});
