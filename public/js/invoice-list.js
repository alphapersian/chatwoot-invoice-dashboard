(() => {
  const wrap = document.querySelector('.invoice-list-wrap[data-invoices]');
  if (!wrap || !window.InvoiceDetail?.renderInvoiceTable) {
    return;
  }

  try {
    const invoices = JSON.parse(wrap.dataset.invoices || '[]');
    wrap.innerHTML = window.InvoiceDetail.renderInvoiceTable(invoices);
  } catch {
    wrap.innerHTML = '<p class="empty">Could not load invoice list.</p>';
  }
})();
