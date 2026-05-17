(() => {
  const modal = document.getElementById('invoice-modal');
  const body = document.getElementById('invoice-modal-body');

  if (!modal || !body) {
    return;
  }

  const escapeHtml = (s) =>
    String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

  const formatMoney = (n) => {
    const num = Number(n);
    return Number.isFinite(num) ? num.toFixed(2) : '—';
  };

  function renderInvoiceTable(invoices, emptyMessage = 'No invoices yet.') {
    if (!invoices?.length) {
      return `<p class="empty">${escapeHtml(emptyMessage)}</p>`;
    }

    const rows = invoices
      .filter((inv) => inv.id)
      .map(
        (inv) => `
      <tr class="invoice-row" data-invoice-id="${escapeHtml(inv.id)}" tabindex="0" role="button" title="View invoice details">
        <td class="invoice-row__number">${escapeHtml(inv.number ?? '—')}</td>
        <td>${escapeHtml(formatMoney(inv.amount))}</td>
        <td>${escapeHtml(formatMoney(inv.balance))}</td>
        <td>${escapeHtml(inv.due_date ?? '—')}</td>
        ${inv.status != null ? `<td>${escapeHtml(inv.status)}</td>` : ''}
      </tr>`
      )
      .join('');

    const hasStatus = invoices.some((inv) => inv.status != null);

    return `
      <table class="invoice-table">
        <thead>
          <tr>
            <th>Number</th>
            <th>Amount</th>
            <th>Balance</th>
            <th>Due</th>
            ${hasStatus ? '<th>Status</th>' : ''}
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
      <p class="hint invoice-table-hint">Click a row to view line items and details.</p>`;
  }

  function renderDetail(inv) {
    const items = (inv.line_items || [])
      .map(
        (item) => `
      <tr>
        <td>${escapeHtml(item.product_key || '—')}</td>
        <td>${escapeHtml(item.notes || '—')}</td>
        <td>${escapeHtml(String(item.quantity ?? '—'))}</td>
        <td>${escapeHtml(formatMoney(item.cost))}</td>
        <td>${escapeHtml(formatMoney(item.line_total))}</td>
      </tr>`
      )
      .join('');

    return `
      <header class="modal-header">
        <h3>Invoice ${escapeHtml(inv.number ?? '')}</h3>
        <span class="status-badge">${escapeHtml(inv.status ?? '')}</span>
      </header>
      <dl class="detail-meta">
        <div><dt>Date</dt><dd>${escapeHtml(inv.date ?? '—')}</dd></div>
        <div><dt>Due</dt><dd>${escapeHtml(inv.due_date ?? '—')}</dd></div>
        <div><dt>Amount</dt><dd>${escapeHtml(formatMoney(inv.amount))}</dd></div>
        <div><dt>Balance</dt><dd>${escapeHtml(formatMoney(inv.balance))}</dd></div>
      </dl>
      <h4 class="modal-subtitle">Line items</h4>
      <table class="detail-items">
        <thead>
          <tr>
            <th>Product</th>
            <th>Description</th>
            <th>Qty</th>
            <th>Unit price</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>${items || '<tr><td colspan="5" class="empty">No line items</td></tr>'}</tbody>
      </table>
      ${inv.terms ? `<p class="modal-notes"><strong>Terms:</strong> ${escapeHtml(inv.terms)}</p>` : ''}
      ${inv.public_notes ? `<p class="modal-notes"><strong>Notes:</strong> ${escapeHtml(inv.public_notes)}</p>` : ''}`;
  }

  function openModal() {
    modal.hidden = false;
    document.body.classList.add('modal-open');
  }

  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  async function openInvoiceDetail(invoiceId) {
    if (!invoiceId) {
      return;
    }

    openModal();
    body.innerHTML = '<p class="empty">Loading invoice…</p>';

    try {
      const res = await fetch(`/api/invoice.php?id=${encodeURIComponent(invoiceId)}`);
      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.error || 'Could not load invoice');
      }
      body.innerHTML = renderDetail(json.data);
    } catch (err) {
      body.innerHTML = `<p class="alert alert-error">${escapeHtml(err.message || 'Failed to load')}</p>`;
    }
  }

  modal.addEventListener('click', (e) => {
    if (e.target.closest('[data-action="close"]')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  document.addEventListener('click', (e) => {
    const viewBtn = e.target.closest('[data-invoice-id]');
    if (viewBtn && !viewBtn.closest('tr')) {
      openInvoiceDetail(viewBtn.dataset.invoiceId);
      return;
    }

    const row = e.target.closest('tr[data-invoice-id]');
    if (!row) {
      return;
    }
    openInvoiceDetail(row.dataset.invoiceId);
  });

  document.addEventListener('keydown', (e) => {
    const row = e.target.closest('tr[data-invoice-id]');
    if (row && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      openInvoiceDetail(row.dataset.invoiceId);
    }
  });

  window.InvoiceDetail = {
    renderInvoiceTable,
    open: openInvoiceDetail,
  };
})();
