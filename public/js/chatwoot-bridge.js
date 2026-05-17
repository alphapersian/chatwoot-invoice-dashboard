(() => {
  const params = new URLSearchParams(window.location.search);
  const forceChatwoot = params.get('chatwoot') === '1';

  let isEmbed = false;
  try {
    isEmbed = window.self !== window.top;
  } catch {
    isEmbed = true;
  }

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
  let contextReceived = false;

  const showEmbedUi = () => {
    if (chatwootApp) {
      chatwootApp.hidden = false;
    }
  };

  const setStatus = (text, type = 'loading') => {
    if (!els.status) {
      return;
    }
    if (!text) {
      els.status.hidden = true;
      els.status.textContent = '';
      return;
    }
    els.status.hidden = false;
    els.status.textContent = text;
    els.status.className = `chatwoot-status chatwoot-status--${type}`;
    showEmbedUi();
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
    showEmbedUi();
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

  function contactHasDetails(contact) {
    if (!contact) {
      return false;
    }
    const phone = contact.phone_number || contact.phone || '';
    const email = contact.email || '';
    const name = contact.name || '';
    const contactId = contact.id != null ? String(contact.id) : '';
    return Boolean(phone || email || name || contactId);
  }

  async function onContact(contact) {
    currentContact = contact;
    contextReceived = true;

    if (!contactHasDetails(contact)) {
      setStatus('No contact in this conversation.', 'error');
      if (newBtn) {
        newBtn.disabled = true;
      }
      return;
    }

    fillHiddenFields(contact);

    const phone = contact.phone_number || contact.phone || '';
    const email = contact.email || '';
    const name = contact.name || '';

    setStatus('Syncing client…', 'loading');
    if (newBtn) {
      newBtn.disabled = true;
    }

    try {
      const result = await syncToInvoiceNinja(contact);
      renderInvoices(result.invoices);
      if (result.created) {
        const clientName = result.client?.name || name || 'Client';
        setStatus(`Created Invoice Ninja client: ${clientName}`, 'success');
      } else {
        setStatus('', '');
      }
      if (newBtn) {
        newBtn.disabled = false;
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

  function parseMessage(raw) {
    let data = raw;
    if (typeof data === 'string') {
      const trimmed = data.trim();
      if (trimmed === '' || trimmed === 'chatwoot-dashboard-app:fetch-info') {
        return null;
      }
      try {
        data = JSON.parse(trimmed);
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
    if (data.contact || data.conversation) {
      return data;
    }
    return null;
  }

  function extractContact(context) {
    if (!context) {
      return null;
    }

    const sender = context.conversation?.meta?.sender ?? null;
    const contact = context.contact ?? null;

    if (contactHasDetails(contact)) {
      return contact;
    }
    if (contactHasDetails(sender)) {
      return sender;
    }

    return contact || sender || null;
  }

  function handleAppContext(raw) {
    const context = parseMessage(raw);
    if (!context) {
      return;
    }
    const contact = extractContact(context);
    if (contact) {
      onContact(contact);
    }
  }

  function requestChatwootContext() {
    if (window.parent !== window) {
      window.parent.postMessage('chatwoot-dashboard-app:fetch-info', '*');
    }
  }

  window.addEventListener('message', (event) => {
    handleAppContext(event.data);
  });

  // Show UI immediately — Chatwoot only renders the iframe after the tab is opened.
  setStatus('Loading contact from Chatwoot…', 'loading');
  renderInvoices([]);

  requestChatwootContext();
  [250, 750, 1500, 3000, 5000].forEach((ms) => {
    setTimeout(requestChatwootContext, ms);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      requestChatwootContext();
    }
  });

  window.addEventListener('focus', requestChatwootContext);

  setTimeout(() => {
    if (!contextReceived) {
      setStatus(
        'Still waiting for Chatwoot. Open a conversation, then click this app tab again.',
        'error'
      );
    }
  }, 8000);
})();
