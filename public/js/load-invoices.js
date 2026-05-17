(() => {
  const loadBtn = document.getElementById('load-invoices-btn');
  const listSection = document.getElementById('loaded-invoices-section');
  const listWrap = document.getElementById('loaded-invoices-wrap');
  const listStatus = document.getElementById('load-invoices-status');
  const phoneInput = document.getElementById('phone');
  const nameInput = document.getElementById('client_name');
  const emailInput = document.getElementById('email');

  if (!listWrap) {
    return;
  }

  if (document.body.classList.contains('embed-chatwoot')) {
    if (loadBtn) {
      loadBtn.hidden = true;
    }
    return;
  }

  let lookupTimer = null;
  let lastLookupKey = '';

  const setStatus = (text, type = '') => {
    if (!listStatus) {
      return;
    }
    listStatus.textContent = text;
    listStatus.className = 'load-invoices-status' + (type ? ` load-invoices-status--${type}` : '');
    listStatus.hidden = text === '';
  };

  const normalizePhoneKey = (phone) => (phone || '').replace(/\D/g, '');

  function fillClientFields(client) {
    if (!client) {
      return;
    }
    if (nameInput && client.name) {
      nameInput.value = client.name;
    }
    if (emailInput && client.email) {
      emailInput.value = client.email;
    }
    if (phoneInput && client.phone && !phoneInput.value.trim()) {
      phoneInput.value = client.phone;
    }
  }

  function renderInvoiceList(invoices) {
    if (window.InvoiceDetail?.renderInvoiceTable) {
      listWrap.innerHTML = window.InvoiceDetail.renderInvoiceTable(
        invoices ?? [],
        'No invoices for this client yet.'
      );
    } else {
      listWrap.innerHTML = '<p class="empty">Invoice list UI not available.</p>';
    }
  }

  async function loadClientAndInvoices({ phone, email, silent = false } = {}) {
    phone = phone ?? phoneInput?.value?.trim() ?? '';
    email = email ?? emailInput?.value?.trim() ?? '';

    if (!phone && !email) {
      if (!silent) {
        setStatus('Enter a phone number or email first.', 'error');
      }
      return null;
    }

    const lookupKey = `${normalizePhoneKey(phone)}|${email.toLowerCase()}`;
    if (lookupKey === lastLookupKey && lookupKey !== '|') {
      return null;
    }
    lastLookupKey = lookupKey;

    if (loadBtn) {
      loadBtn.disabled = true;
    }
    setStatus('Looking up client…', 'loading');

    try {
      const params = new URLSearchParams();
      if (phone) {
        params.set('phone', phone);
      }
      if (email) {
        params.set('email', email);
      }

      const res = await fetch(`/api/invoices-by-phone.php?${params}`);
      const json = await res.json();

      if (!res.ok) {
        throw new Error(json.error || 'Could not load client');
      }

      fillClientFields(json.client);

      if (listSection) {
        listSection.hidden = false;
      }

      if (json.client) {
        setStatus(
          `Client found: ${json.client.name ?? '—'} · ${json.count} invoice(s)`,
          'success'
        );
      } else {
        setStatus(
          'No client in Invoice Ninja yet — name and email will be used when you create the invoice.',
          'loading'
        );
        listWrap.innerHTML = '<p class="empty">No invoices yet for this number.</p>';
        return json;
      }

      renderInvoiceList(json.invoices);
      return json;
    } catch (err) {
      setStatus(err.message || 'Lookup failed', 'error');
      if (listSection) {
        listSection.hidden = true;
      }
      return null;
    } finally {
      if (loadBtn) {
        loadBtn.disabled = false;
      }
    }
  }

  function schedulePhoneLookup() {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(() => {
      const phone = phoneInput?.value?.trim() ?? '';
      if (normalizePhoneKey(phone).length < 7) {
        return;
      }
      loadClientAndInvoices({ phone, silent: true });
    }, 500);
  }

  if (phoneInput) {
    phoneInput.addEventListener('input', schedulePhoneLookup);
    phoneInput.addEventListener('blur', () => {
      const phone = phoneInput.value.trim();
      if (normalizePhoneKey(phone).length >= 7) {
        lastLookupKey = '';
        loadClientAndInvoices({ phone, silent: true });
      }
    });
  }

  if (emailInput) {
    emailInput.addEventListener('blur', () => {
      const email = emailInput.value.trim();
      if (email && !phoneInput?.value?.trim()) {
        lastLookupKey = '';
        loadClientAndInvoices({ email, silent: true });
      }
    });
  }

  loadBtn?.addEventListener('click', () => {
    lastLookupKey = '';
    loadClientAndInvoices({ silent: false });
  });
})();
