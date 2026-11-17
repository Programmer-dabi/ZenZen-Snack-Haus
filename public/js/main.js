// Wait until the page HTML exists before looking up forms and attaching event handlers.
// Each section checks for its own elements because this script is shared by several pages.
document.addEventListener("DOMContentLoaded", () => {
  // Keep the current search when switching the menu category.
  // On the menu page, submit the current search form when its category changes.
  const catalogCategory = document.querySelector('[data-catalog-category]');
  if (catalogCategory) {
    catalogCategory.addEventListener('change', () => catalogCategory.form.requestSubmit());
  }

  /* ============================================================
     HAMBURGER MENU
     ============================================================ */
  // Locate desktop/mobile navigation controls and watch the breakpoint used by the stylesheet.
  const Hamburger = document.querySelector('.Hamburger');
  const navLinks = document.querySelector('.navLinks');
  const mediaQuery = window.matchMedia("(max-width: 900px)");

  if (Hamburger && navLinks) {
    // Keep the menu visibility, button styling, and screen-reader state synchronized.
    function setMenuOpen(open) {
      Hamburger.classList.toggle('activated', open);
      Hamburger.setAttribute('aria-expanded', String(open));
      Hamburger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      navLinks.classList.toggle('showNav', open);
      navLinks.hidden = !open;
    }

    // Close the menu when the viewport crosses the mobile breakpoint.
    mediaQuery.addEventListener('change', () => setMenuOpen(false));
    // Toggle navigation only when the mobile layout is active.
    Hamburger.addEventListener('click', () => {
      setMenuOpen(mediaQuery.matches && navLinks.hidden);
    });
    // Close mobile navigation after a link is chosen.
    navLinks.addEventListener('click', (event) => {
      if (event.target.closest('a')) setMenuOpen(false);
    });
    // Allow Escape to close the mobile menu and return focus to its toggle button.
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !navLinks.hidden) {
        setMenuOpen(false);
        Hamburger.focus();
      }
    });

    // Set initial state on load
    setMenuOpen(false);
  }

  /* ============================================================
     ADD-ONS
     ============================================================ */
  // Only initialize add-on editing when the current page contains its list and Add button.
  const addonList = document.getElementById('addonList');
  const addAddonButton = document.getElementById('addAddon');

  if (addonList && addAddonButton) {
    // Create one editable add-on row using the field names the product controller expects.
    function addAddon() {
      // Build the new row in memory before adding it to the visible form.
      const addonRow = document.createElement('div');
      addonRow.classList.add('addon-row');

      // Require a name for the added extra; the controller applies its own server validation too.
      const addonName = document.createElement('input');
      addonName.type = 'text';
      addonName.name = 'addon_name[]';
      addonName.placeholder = 'Add-on name';
      addonName.required = true;

      // Use a non-negative two-decimal input for the extra price shown in the administrator form.
      const addonPrice = document.createElement('input');
      addonPrice.type = 'number';
      addonPrice.name = 'addon_price[]';
      addonPrice.placeholder = 'Price';
      addonPrice.min = '0';
      addonPrice.step = '0.01';
      addonPrice.required = true;

      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.classList.add('remove-addon');
      removeButton.textContent = 'Remove';

      // Remove this newly created add-on row when its button is pressed.
      removeButton.addEventListener('click', function () {
        addonRow.remove();
      });

      // Insert the finished row into the form so its inputs are included on submission.
      addonRow.append(addonName, addonPrice, removeButton);
      addonList.appendChild(addonRow);
    }

    addAddonButton.addEventListener('click', addAddon);

    // Delegated remove handler (also catches pre-rendered rows)
    addonList.addEventListener('click', function (event) {
      // Delegation also allows removal of rows that Twig rendered before this script ran.
      if (event.target.classList.contains('remove-addon')) {
        event.target.closest('.addon-row').remove();
      }
    });
  }

  /* ============================================================
     PRODUCT VARIANTS
     ============================================================ */
  // Build a new variant row with label, additional price, stock, and removal controls.
  function createVariantRow() {
    const row = document.createElement("div");
    row.classList.add("admin-tr", "variant-row");

    // The historical variant_oz field now holds a text label rather than requiring a numeric ounce size.
    const ozInput = document.createElement("input");
    ozInput.type = "text";
    ozInput.name = "variant_oz[]";
    ozInput.placeholder = "Variant Name eg. Size(oz)";
    ozInput.min = "0.1";
    ozInput.step = "0.1";
    ozInput.required = true;

    // The variant price is added to the product base price rather than replacing it.
    const priceInput = document.createElement("input");
    priceInput.type = "number";
    priceInput.name = "variant_price[]";
    priceInput.placeholder = "Price";
    priceInput.min = "0";
    priceInput.step = "0.01";
    priceInput.required = true;

    // New variant stock is entered as a non-negative whole number.
    const stockInput = document.createElement("input");
    stockInput.type = "number";
    stockInput.name = "variant_stock[]";
    stockInput.placeholder = "Stock";
    stockInput.min = "0";
    stockInput.step = "1";
    stockInput.required = true;

    const removeButton = document.createElement("button");
    removeButton.type = "button";
    removeButton.classList.add("remove-variant");
    removeButton.textContent = "Remove";

    // Return the assembled row to the caller, which decides where to insert it.
    row.append(ozInput, priceInput, stockInput, removeButton);
    return row;
  }

  // Set up the product editor only on pages that contain all required variant controls.
  function initProductVariants() {
    const category = document.getElementById("category");
    const variantsSection = document.getElementById("variantsSection");
    const variantList = document.getElementById("variantList");
    const addVariantButton = document.getElementById("addVariant");

    // Bail out if this page doesn't contain the variants form.
    if (!category || !variantsSection || !variantList || !addVariantButton) {
      return;
    }

    // Read supported category values from the server-rendered select instead of duplicating the category list.
    const variantCategories = Array.from(category.options, (option) => option.value).filter(Boolean);

    // Show variants for a selected product category and create an initial row when none exists.
    function updateVariants() {
      const needsVariants = variantCategories.includes(category.value);
      variantsSection.hidden = !needsVariants;

      // Start with one editable variant without replacing any saved rows already rendered by Twig.
      if (needsVariants && variantList.children.length === 0) {
        variantList.appendChild(createVariantRow());
      }
    }

    category.addEventListener("change", updateVariants);

    // Append another variant row without submitting the product form.
    addVariantButton.addEventListener("click", () => {
      variantList.appendChild(createVariantRow());
    });

    // Use the list to handle removal for both existing rows and rows added later.
    variantList.addEventListener("click", (event) => {
      if (event.target.classList.contains("remove-variant")) {
        event.target.closest(".variant-row").remove();
      }
    });

    updateVariants();
  }

  initProductVariants();

  // Connect category changes to the flavour and side-snack availability fieldsets.
  const productCategory = document.getElementById('category');
  const flavourAvailability = document.querySelector('[data-flavour-availability]');
  if (productCategory && flavourAvailability) {
    const flavourCategories = JSON.parse(flavourAvailability.dataset.flavourCategories);
    // Hide and disable flavour settings for categories that do not support flavours.
    function updateFlavourAvailability() {
      const hasFlavours = flavourCategories.includes(productCategory.value);
      flavourAvailability.hidden = !hasFlavours;
      flavourAvailability.disabled = !hasFlavours;
    }
    productCategory.addEventListener('change', updateFlavourAvailability);
    updateFlavourAvailability();
  }

  const sideSnackAvailability = document.querySelector('[data-side-snack-availability]');
  if (productCategory && sideSnackAvailability) {
    // Only Mix-n-Match exposes side-snack availability; disabled fields are omitted from form submission.
    function updateSideSnackAvailability() {
      const mixAndMatch = productCategory.value === 'Mix-n-Match';
      sideSnackAvailability.hidden = !mixAndMatch;
      sideSnackAvailability.disabled = !mixAndMatch;
    }
    productCategory.addEventListener('change', updateSideSnackAvailability);
    updateSideSnackAvailability();
  }

  // Initialize each product gallery independently so thumbnails update only their own main image.
  document.querySelectorAll('.gallery').forEach((gallery) => {
    const mainPhoto = gallery.querySelector('.main-photo img');
    const thumbnails = gallery.querySelectorAll('.thumbs button');

    if (!mainPhoto) return;

    // Clicking a thumbnail copies its image and description into the main gallery position.
    thumbnails.forEach((button) => {
      button.addEventListener('click', () => {
        const image = button.querySelector('img');
        if (!image) return;

        mainPhoto.src = image.src;
        mainPhoto.alt = image.alt;

        // Update the selected thumbnail styling and aria-pressed state for accessibility.
        thumbnails.forEach((thumbnail) => {
          const selected = thumbnail === button;
          thumbnail.classList.toggle('selected', selected);
          thumbnail.setAttribute('aria-pressed', String(selected));
        });
      });
    });
  });

  /* ============================================================
     CHECKOUT PAYMENT
     ============================================================ */
  // Checkout shows different required fields for pickup and delivery payment methods.
  const checkoutForm = document.querySelector('.checkout-form');
  if (checkoutForm) {
    const paymentOptions = checkoutForm.querySelectorAll('[name="payment_method"]');
    const pickupFields = checkoutForm.querySelector('[data-pickup-fields]');
    const pickupInput = checkoutForm.querySelector('[name="pickup_at"]');
    const deliveryFields = checkoutForm.querySelector('[data-delivery-fields]');
    const addressInputs = deliveryFields ? deliveryFields.querySelectorAll('input') : [];

    if (pickupFields && pickupInput) {
      // Enable only the relevant inputs: pickup time for store pickup, or an address for delivery.
      function updatePickupFields() {
        const selected = checkoutForm.querySelector('[name="payment_method"]:checked');
        const storePickup = selected?.value === 'store_pickup';
        pickupFields.hidden = !storePickup;
        pickupInput.disabled = !storePickup;
        pickupInput.required = storePickup;
        const needsDelivery = selected?.value === 'gcash' || selected?.value === 'cash_on_delivery';
        if (deliveryFields) deliveryFields.hidden = !needsDelivery;
        // Disabled fields do not submit stale values; required fields guide normal browser interaction.
        addressInputs.forEach((input) => {
          input.disabled = !needsDelivery;
          input.required = needsDelivery;
        });
      }

      paymentOptions.forEach((option) => option.addEventListener('change', updatePickupFields));
      // Reapply payment field state when a browser restores the checkout page from history.
      window.addEventListener('pageshow', updatePickupFields);
      updatePickupFields();
    }
  }

  /* ============================================================
     ORDER TRACKING
     ============================================================ */
  // Find the GCash information dialog and its opening button on supported tracking pages.
  const gcashPayButton = document.querySelector('[data-gcash-pay]');
  const gcashPaymentModal = document.getElementById('gcash-payment-modal');
  if (gcashPayButton && gcashPaymentModal) {
    // Open the payment information dialog; this button itself does not verify or change payment status.
    gcashPayButton.addEventListener('click', () => gcashPaymentModal.showModal());
    // Close the dialog when a click lands outside its visible rectangle.
    gcashPaymentModal.addEventListener('click', (event) => {
      const bounds = gcashPaymentModal.getBoundingClientRect();
      if (event.target === gcashPaymentModal &&
          (event.clientX < bounds.left || event.clientX > bounds.right ||
           event.clientY < bounds.top || event.clientY > bounds.bottom)) {
        gcashPaymentModal.close();
      }
    });
  }

  // Read the private polling URL from the tracking page rendered by Twig.
  const trackingPage = document.querySelector('[data-tracking-status-url]');
  if (trackingPage) {
    const heading = trackingPage.querySelector('[data-tracking-heading]');
    const badge = trackingPage.querySelector('[data-tracking-status]');
    const progress = trackingPage.querySelector('[data-tracking-progress]');
    const note = trackingPage.querySelector('[data-tracking-update-note]');
    const steps = trackingPage.querySelectorAll('[data-tracking-step]');
    // Track an active request and a stopped poller so refreshes do not overlap or continue after the page is left.
    let refreshing = false;
    let stopped = false;
    let timer;

    // Fetch current order progress without a full page reload.
    async function refreshTracking() {
      // Skip polling while a request is active, polling has stopped, or the document is hidden.
      if (refreshing || stopped || document.hidden) return;
      refreshing = true;
      try {
        // Request fresh JSON with no cache and a ten-second timeout; the session identifies the customer.
        const response = await fetch(trackingPage.dataset.trackingStatusUrl, {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store',
          signal: AbortSignal.timeout(10000),
        });
        // Stop polling when access expires or the order is no longer available, then show a useful status note.
        if (response.status === 401 || response.status === 403 || response.status === 404) {
          stopped = true;
          clearInterval(timer);
          note.textContent = 'This order is no longer available. Please sign in to view your orders.';
          return;
        }
        if (!response.ok) throw new Error('Could not refresh order status.');
        const state = await response.json();
        // Validate the expected JSON fields and step count before using them to update the page.
        if (!state.success || !Number.isInteger(state.progress_step) || state.progress_step < 0 || state.progress_step > steps.length ||
            !Array.isArray(state.tracking_steps) || state.tracking_steps.length !== steps.length || state.tracking_steps.some((label) => typeof label !== 'string') ||
            typeof state.status !== 'string' || typeof state.status_class !== 'string' || typeof state.tracking_heading !== 'string') {
          throw new Error('Unexpected order status response.');
        }
        // Use textContent for returned text so a status message cannot be interpreted as HTML.
        heading.textContent = state.tracking_heading;
        badge.textContent = state.status;
        badge.className = `status ${state.status_class}`;
        progress.hidden = state.progress_step === 0;
        // Mark reached steps, identify the current step, and update labels without rebuilding the whole list.
        steps.forEach((step) => {
          const number = Number(step.dataset.trackingStep);
          const current = number === state.progress_step;
          step.querySelector('[data-tracking-step-label]').textContent = state.tracking_steps[number - 1];
          step.classList.toggle('active', number <= state.progress_step);
          step.querySelector('[data-tracking-marker]').textContent = number <= state.progress_step ? '✓' : String(number);
          if (current) step.setAttribute('aria-current', 'step');
          else step.removeAttribute('aria-current');
          // Show helper text only for the current stage and the next stage.
          const stepNote = step.querySelector('[data-tracking-step-note]');
          stepNote.textContent = current ? 'Current status' : (number === state.progress_step + 1 ? 'Up next' : '');
          stepNote.hidden = stepNote.textContent === '';
        });
        note.textContent = '';
      } catch {
        // Keep the last displayed status and explain that a temporary polling failure will be retried.
        note.textContent = 'Could not refresh your order status. Retrying…';
      // Release the busy state even when a request fails, restoring the controls for another attempt.
      } finally {
        refreshing = false;
      }
    }

    // Poll every five seconds; refreshTracking itself prevents overlapping and hidden-page requests.
    timer = setInterval(refreshTracking, 5000);
    // Refresh promptly when the user returns to the tab.
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshTracking();
    });
    // Clear the timer when leaving the page so background polling stops.
    window.addEventListener('pagehide', () => {
      clearInterval(timer);
      stopped = true;
    });
    // Restart polling when the browser restores this page from its back/forward cache.
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        stopped = false;
        // Poll every five seconds; refreshTracking itself prevents overlapping and hidden-page requests.
        timer = setInterval(refreshTracking, 5000);
        refreshTracking();
      }
    });
    refreshTracking();
  }

  /* ============================================================
     CART QUANTITY
     ============================================================ */
  // Enhance ordinary cart quantity forms with JSON updates while preserving their server endpoints.
  const cartPage = document.getElementById('cart');
  if (cartPage) {
    const forms = cartPage.querySelectorAll('[data-cart-quantity-form]');
    const status = cartPage.querySelector('[data-cart-update-status]');
    // Format returned peso amounts consistently with two decimal places.
    const money = new Intl.NumberFormat('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    let updating = false;

    // Update each plus/minus submission value and disable quantities outside 1 to 99 or during a request.
    function updateButtons() {
      // Attach one submit handler to each existing saved cart line.
      forms.forEach((form) => {
        const quantity = Number(form.querySelector('[data-cart-quantity]').textContent);
        form.querySelectorAll('[data-quantity-step]').forEach((button) => {
          const nextQuantity = quantity + Number(button.dataset.quantityStep);
          button.value = String(nextQuantity);
          button.disabled = updating || nextQuantity < 1 || nextQuantity > 99;
        });
      });
    }

    // Attach one submit handler to each existing saved cart line.
    forms.forEach((form) => {
      // Intercept the form submit so the existing endpoint can update the page using JSON.
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        // Prevent a second quantity request until the current one finishes, and require a clicked submit button.
        if (updating || !event.submitter) return;

        // Read the form fields using their existing names; quantity forms also include their CSRF token.
        const data = new FormData(form);
        // FormData does not automatically include the clicked button here, so copy its requested quantity explicitly.
        data.set('quantity', event.submitter.value);
        updating = true;
        updateButtons();
        status.textContent = 'Updating cart…';

        try {
          // Submit to the form's existing action and request the response format used by this handler.
          const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: data,
          });
          // Step: Read the server response and place messages with textContent, not HTML.
          const result = await response.json();
          // If sign-in expired, follow the login URL supplied by the server.
          if (response.status === 401) {
            // After a successful save, follow the cart URL supplied by the server.
            window.location.assign(result.redirect_url || '/login');
            return;
          }
          // Show the server validation message when the HTTP request or business operation failed.
          if (!response.ok || !result.success) {
            throw new Error(result.message || 'Could not update your cart.');
          }

          // Use saved item IDs to refresh each displayed quantity and line total from the authoritative server response.
          forms.forEach((quantityForm) => {
            const row = quantityForm.closest('[data-cart-item]');
            const item = result.items[row.dataset.cartItem];
            if (!item) return;
            quantityForm.querySelector('[data-cart-quantity]').textContent = String(item.quantity);
            row.querySelector('[data-cart-line-total]').textContent = `₱${money.format(item.line_total)}`;
          });
          // Update the summary and all cart badges using server totals rather than recalculating prices in the browser.
          cartPage.querySelector('[data-cart-subtotal]').textContent = `₱${money.format(result.subtotal)}`;
          cartPage.querySelector('[data-cart-total]').textContent = `₱${money.format(result.total)}`;
          cartPage.querySelector('[data-cart-summary-count]').textContent =
            `${result.cart_count} ${result.cart_count === 1 ? 'item' : 'items'} waiting for you`;
          document.querySelectorAll('[data-cart-count]').forEach((count) => {
            count.textContent = String(result.cart_count);
          });
          status.textContent = 'Cart updated.';
        // Explain malformed JSON separately from other request failures so the customer can retry.
        } catch (error) {
          status.textContent = error instanceof SyntaxError
            ? 'The server returned an unexpected response. Please try again.'
            : error.message || 'Could not update your cart. Please try again.';
        // Release the busy state even when a request fails, restoring the controls for another attempt.
        } finally {
          updating = false;
          updateButtons();
        }
      });
    });
    updateButtons();
  }

  /* ============================================================
     ADD TO CART
     ============================================================ */
  // Initialize option selection and JSON submission for each product form on the page.
  document.querySelectorAll('[data-cart-form]').forEach((form) => {
    // Read server-rendered availability and locate the controls used by the product price preview.
    const productUnavailable = form.dataset.productUnavailable === 'true';
    const submitButton = form.querySelector('[type="submit"]');
    const status = form.querySelector('[data-cart-status]');
    const minusButton = form.querySelector('#minusButton');
    const plusButton = form.querySelector('#addButton');
    const quantityDisplay = form.querySelector('[data-quantity]');
    const quantityInput = form.querySelector('[name="quantity"]');
    const totalInput = form.querySelector('[name="totalPrice"]');
    let quantity = 1;
    let submitting = false;

    // Calculate in centavos using only prices passed into the template.
    function toCentavos(price) {
      return Math.round(Number(price) * 100);
    }

    // Preview base price plus the selected variant and extras, multiplied by the chosen quantity.
    // The server independently calculates the trusted price when saving the cart.
    function updatePrice() {
      const variant = form.querySelector('[name="variant_id"]:checked:not(:disabled)');
      let unitPrice = form.dataset.basePrice ? toCentavos(form.dataset.basePrice) : 0;

      // A selected variant contributes an additional price on top of the base price.
      if (variant) unitPrice += toCentavos(variant.dataset.price);

      // Add the preview price of each checked, enabled extra.
      form.querySelectorAll('[name="addon_ids[]"]:checked:not(:disabled)').forEach((addon) => {
        unitPrice += toCentavos(addon.dataset.price);
      });

      // Calculate the quantity total in centavos, then show it as a two-decimal peso amount.
      const total = (unitPrice * quantity / 100).toFixed(2);
      quantityDisplay.textContent = String(quantity);
      quantityInput.value = String(quantity);
      minusButton.disabled = quantity === 1;
      totalInput.value = total;
      if (submitButton) {
        submitButton.value = productUnavailable ? 'this product is currently unavailable' : `Add to Cart ₱${total}`;
        // Do not send a cart request for a product marked unavailable on this page.
        if (productUnavailable) submitButton.disabled = true;
        submitButton.dataset.totalprice = total;
      }
    }

    // Decrease the preview quantity without allowing it to drop below one.
    minusButton.addEventListener('click', () => {
      quantity = Math.max(1, quantity - 1);
      updatePrice();
    });

    // Increase the preview quantity; the existing server validation still enforces the upper limit.
    plusButton.addEventListener('click', () => {
      quantity += 1;
      updatePrice();
    });

    // Recalculate the preview when the chosen variant or extras change.
    form.addEventListener('change', (event) => {
      if (event.target.matches('[name="variant_id"], [name="addon_ids[]"]')) {
        updatePrice();
      }
    });
    updatePrice();

    // Intercept the form submit so the existing endpoint can update the page using JSON.
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      // Do not send a cart request for a product marked unavailable on this page.
      if (productUnavailable) {
        status.textContent = 'this product is currently unavailable';
        return;
      }
      // Send guests to sign-in before attempting to save a customer-owned cart item.
      if (form.dataset.loginRequired === 'true') {
        window.location.assign('/login');
        return;
      }
      // Prevent duplicate submissions and ask the browser to report missing required choices.
      if (submitting || !form.reportValidity()) return;

      // Read the form fields using their existing names; quantity forms also include their CSRF token.
      const data = new FormData(form);
      const variant = data.get('variant_id');
      const sugar = data.get('sugar_level');
      const flavour = data.get('flavour');
      const sideSnack = data.get('side_snack');

      // A product with variant controls needs an enabled selection before submission.
      if (form.querySelector('[name="variant_id"]') && variant === null) {
        status.textContent = 'Please choose an in-stock variant.';
        return;
      }

      // Require an available flavour when this product exposes a flavour chooser.
      if (form.querySelector('[name="flavour"]') && flavour === null) {
        status.textContent = 'Please choose an available flavour.';
        return;
      }

      // Require a side snack when the Mix-n-Match chooser is present.
      if (form.querySelector('[name="side_snack"]') && sideSnack === null) {
        status.textContent = 'Please choose an available side snack.';
        return;
      }

      // Step: Send option IDs and flavour; the server calculates trusted prices.
      // Convert form strings into the JSON types the controller expects; send IDs and options, not trusted prices.
      const payload = {
        flavour,
        variant_id: variant === null ? null : Number(variant),
        side_snack: sideSnack,
        ice_level: data.get('ice_level'),
        sugar_level: sugar === null ? null : Number(sugar),
        addon_ids: data.getAll('addon_ids[]').map(Number),
        quantity: Number(data.get('quantity')),
      };

      // Disable repeat submissions while this selection is being saved.
      submitting = true;
      submitButton.disabled = true;
      status.textContent = 'Sending product options…';

      try {
        // Submit to the form's existing action and request the response format used by this handler.
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: JSON.stringify(payload),
        });
        // Step: Read the server response and place messages with textContent, not HTML.
        const result = await response.json();

        // If sign-in expired, follow the login URL supplied by the server.
        if (response.status === 401) {
          // After a successful save, follow the cart URL supplied by the server.
          window.location.assign(result.redirect_url || '/login');
          return;
        }

        // Show the server validation message when the HTTP request or business operation failed.
        if (!response.ok || !result.success) {
          throw new Error(result.message || 'Could not send product options.');
        }

        status.textContent = result.message;
        // After a successful save, follow the cart URL supplied by the server.
        window.location.assign(result.redirect_url || '/cart');
      // Explain malformed JSON separately from other request failures so the customer can retry.
      } catch (error) {
        status.textContent = error instanceof SyntaxError
          ? 'The server returned an unexpected response. Please try again.'
          : error.message || 'Could not send product options. Please try again.';
      // Release the busy state even when a request fails, restoring the controls for another attempt.
      } finally {
        submitting = false;
        submitButton.disabled = productUnavailable;
      }
    });
  });

});
