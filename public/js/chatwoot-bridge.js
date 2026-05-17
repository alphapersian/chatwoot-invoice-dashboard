(() => {
  const params = new URLSearchParams(window.location.search);
  const forceChatwoot = params.get('chatwoot') === '1';
  const isEmbed = window.self !== window.top;

  if (!isEmbed && !forceChatwoot) {
    return;
  }

  document.body.classList.add('embed-chatwoot');

  const manualSection = document.getElementById('client-section-manual');
  const chatwootApp = document.getElementById('chatwoot-app');
  const form = document.querySelector('form.card');
  const pageTitle = document.getElementById('page-title');
  const pageSubtitle = document.getElementById('page-subtitle');
  const newBtn = document.getElementById('chatwoot-new-invoice-btn');
  const backBtn = document.getElementById('chatwoot-back-btn');
  const invoicesList = document.getElementById('chatwoot-invoices-list');
  const formAlert = document.getElementById('chatwoot-form-alert');
  const serverAlerts = document.getElementById('server-alerts');
  const createdSection = document.getElementById('created-invoice-section');
  const postInvoicesSection = document.getElementById('client-invoices-list');

  if (manualSection) {
    manualSection.hidden = true;
    manualSection.querySelectorAll('[required]').forEach((el) => el.removeAttribute('required'));
  }
  if (form) {
    form.hidden = true;
  }
  if (serverAlerts) {
    serverAlerts.hidden = true;
  }
  if (createdSection) {
    createdSection.hidden = true;
  }
  if (postInvoicesSection) {
    postInvoicesSection.hidden = true;
  }

  if (pageTitle) {
    pageTitle.textContent = 'Invoices';
  }
  if (pageSubtitle) {
    pageSubtitle.hidden = true;
  }

  const els = {
    status: document.getElementById('chatwoot-status'),
    phoneInput: document.getElementById('phone'),
    nameInput: document.getElementById('client_name'),
    emailInput: document.getElementById('email'),
    chatwootIdInput: document.getElementById('chatwoot_contact_id'),
    sourceInput: document.getElementById('source'),
  };

  let currentContact = null;

  const setStatus = (text, type = 'loading') => {
    if (!els.status) {
      return;
    }
    els.status.textContent = text;
    els.status.className = `chatwoot-status chatwoot-status--${type}`;
    els.status.hidden = text === '';
  };

  const setFormAlert = (text, type = 'error') => {
    if (!formAlert) {
      return;
    }
    if (!text) {
      formAlert.hidden = true;
      formAlert.textContent = '';
      return;
    }
    formAlert.hidden = false;
    formAlert.textContent = text;
    formAlert.className = `alert alert-${type}`;
  };

  const showHome = () => {
    if (chatwootApp) {
      chatwootApp.hidden = false;
    }
    if (form) {
      form.hidden = true;
    }
    if (backBtn) {
      backBtn.hidden = true;
    }
    if (pageTitle) {
      pageTitle.textContent = 'Invoices';
    }
    setFormAlert('');
  };

  const showForm = () => {
    if (chatwootApp) {
      chatwootApp.hidden = true;
    }
    if (form) {
      form.hidden = false;
    }
    if (backBtn) {
      backBtn.hidden = false;
    }
    if (pageTitle) {
      pageTitle.textContent = 'New invoice';
    }
    setFormAlert('');
  };

  const renderInvoices = (invoices) => {
    if (!invoicesList) {
      return;
    }

    if (window.InvoiceDetail?.renderInvoiceTable) {
      invoicesList.innerHTML = window.InvoiceDetail.renderInvoiceTable(
        invoices,
        'No invoices yet for this client.'
      );
    } else if (!invoices?.length) {
      invoicesList.innerHTML = '<p class="empty">No invoices yet for this client.</p>';
    }
  };

  const fillHiddenFields = (contact) => {
    const phone = contact.phone_number || contact.phone || '';
    const name = contact.name || '';
    const email = contact.email || '';
    const id = contact.id != null ? String(contact.id) : '';

    if (els.phoneInput) {
      els.phoneInput.value = phone;
    }
    if (els.nameInput) {
      els.nameInput.value = name;
    }
    if (els.emailInput) {
      els.emailInput.value = email;
    }
    if (els.chatwootIdInput) {
      els.chatwootIdInput.value = id;
    }
    if (els.sourceInput) {
      els.sourceInput.value = 'chatwoot';
    }
  };

  async function syncToInvoiceNinja(contact) {
    const res = await fetch('/api/chatwoot-sync.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contact }),
    });
    const json = await res.json();
    if (!res.ok) {
      throw new Error(json.error || 'Could not sync client');
    }
    return json;
  }

  function collectFormItems() {
    const rows = form?.querySelectorAll('.line-item-row') ?? [];
    const items = [];
    rows.forEach((row) => {
      const product_name = row.querySelector('[data-field="product_name"]')?.value?.trim() ?? '';
      const notes = row.querySelector('[data-field="notes"]')?.value?.trim() ?? '';
      const quantity = row.querySelector('[data-field="quantity"]')?.value?.trim() ?? '1';
      const cost = row.querySelector('[data-field="cost"]')?.value?.trim() ?? '';
      if (product_name) {
        items.push({ product_name, notes, quantity, cost });
      }
    });
    return items;
  }

  async function createInvoice() {
    if (!currentContact) {
      setFormAlert('Contact not loaded from Chatwoot.');
      return;
    }

    const items = collectFormItems();
    const submitBtn = form?.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
    }
    setFormAlert('');

    try {
      const res = await fetch('/api/create-invoice.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contact: currentContact, items }),
      });
      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.error || 'Could not create invoice');
      }

      renderInvoices(json.invoices);
      showHome();
      setStatus('', '');
    } catch (err) {
      setFormAlert(err.message || 'Could not create invoice', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
      }
    }
  }

  async function onContact(contact) {
    currentContact = contact;

    if (!contact) {
      setStatus('No contact in this conversation.', 'error');
      if (newBtn) {
        newBtn.disabled = true;
      }
      return;
    }

    fillHiddenFields(contact);

    const phone = contact.phone_number || contact.phone || '';
    const email = contact.email || '';
    if (!phone && !email) {
      setStatus('Contact has no phone or email in Chatwoot.', 'error');
      if (newBtn) {
        newBtn.disabled = true;
      }
      return;
    }

    setStatus('Loading invoices…', 'loading');
    if (newBtn) {
      newBtn.disabled = true;
    }

    try {
      const result = await syncToInvoiceNinja(contact);
      renderInvoices(result.invoices);
      setStatus('', '');
      if (newBtn) {
        newBtn.disabled = false;
      }
      if (chatwootApp) {
        chatwootApp.hidden = false;
      }
      showHome();
    } catch (err) {
      setStatus(err.message || 'Could not load invoices', 'error');
      if (newBtn) {
        newBtn.disabled = true;
      }
    }
  }

  newBtn?.addEventListener('click', () => {
    showForm();
  });

  backBtn?.addEventListener('click', () => {
    showHome();
  });

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    createInvoice();
  });

  function parseMessage(data) {
    if (typeof data === 'string') {
      try {
        data = JSON.parse(data);
      } catch {
        return null;
      }
    }
    if (!data || typeof data !== 'object') {
      return null;
    }
    if (data.event === 'appContext' && data.data) {
      return data.data;
    }
    if (data.data?.contact || data.data?.conversation) {
      return data.data;
    }
    return null;
  }

  function extractContact(context) {
    if (!context) {
      return null;
    }
    if (context.contact) {
      return context.contact;
    }
    return context.conversation?.meta?.sender ?? null;
  }

  window.addEventListener('message', (event) => {
    const context = parseMessage(event.data);
    const contact = extractContact(context);
    if (contact) {
      onContact(contact);
    }
  });

  if (window.parent !== window) {
    window.parent.postMessage('chatwoot-dashboard-app:fetch-info', '*');
  }
})();
