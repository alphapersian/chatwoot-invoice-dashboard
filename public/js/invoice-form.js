(() => {
  const productsUrl = document.body.dataset.productsUrl || '/api/products.php';
  const itemsContainer = document.getElementById('line-items');
  const addBtn = document.getElementById('add-line-item');
  const rowTemplate = document.getElementById('line-item-template');

  if (!itemsContainer || !addBtn || !rowTemplate) {
    return;
  }

  /** @type {Array<{product_key:string,notes:string,price:number}>} */
  let catalog = [];

  const normalize = (s) => String(s || '').trim().toLowerCase();

  async function loadCatalog(query = '') {
    const url = query ? `${productsUrl}?q=${encodeURIComponent(query)}` : productsUrl;
    const res = await fetch(url);
    if (!res.ok) {
      throw new Error('Could not load products');
    }
    const json = await res.json();
    if (json.error) {
      throw new Error(json.error);
    }
    return json.data || [];
  }

  function findProduct(name) {
    const key = normalize(name);
    return catalog.find((p) => normalize(p.product_key) === key) || null;
  }

  function fillFromProduct(row, product) {
    const notes = row.querySelector('[data-field="notes"]');
    const cost = row.querySelector('[data-field="cost"]');
    if (notes && product.notes) {
      notes.value = product.notes;
    }
    if (cost && product.price != null && product.price !== '') {
      cost.value = product.price;
    }
  }

  function bindRow(row) {
    const nameInput = row.querySelector('[data-field="product_name"]');
    const removeBtn = row.querySelector('[data-action="remove"]');
    const suggestions = row.querySelector('.product-suggestions');

    if (!nameInput) {
      return;
    }

    const datalistId = `products-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
    nameInput.setAttribute('list', datalistId);

    let datalist = row.querySelector('datalist');
    if (!datalist) {
      datalist = document.createElement('datalist');
      datalist.id = datalistId;
      row.appendChild(datalist);
    } else {
      datalist.id = datalistId;
    }

    const renderSuggestions = (items) => {
      datalist.innerHTML = '';
      items.forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p.product_key;
        opt.label = p.notes ? `${p.product_key} — ${p.notes}` : p.product_key;
        datalist.appendChild(opt);
      });
    };

    renderSuggestions(catalog);

    const applyProduct = () => {
      const match = findProduct(nameInput.value);
      if (match) {
        fillFromProduct(row, match);
        if (suggestions) {
          suggestions.textContent = 'Loaded from catalog';
          suggestions.className = 'product-suggestions matched';
        }
      } else if (nameInput.value.trim() && suggestions) {
        suggestions.textContent = 'New product will be created on save';
        suggestions.className = 'product-suggestions new';
      } else if (suggestions) {
        suggestions.textContent = '';
        suggestions.className = 'product-suggestions';
      }
    };

    const onNameChange = () => {
      applyProduct();
    };

    nameInput.addEventListener('change', onNameChange);

    let debounce;
    nameInput.addEventListener('input', () => {
      onNameChange();
      clearTimeout(debounce);
      debounce = setTimeout(async () => {
        const q = nameInput.value.trim();
        if (q.length < 2) {
          renderSuggestions(catalog);
          return;
        }
        try {
          const results = await loadCatalog(q);
          results.forEach((p) => {
            if (!catalog.some((c) => normalize(c.product_key) === normalize(p.product_key))) {
              catalog.push(p);
            }
          });
          renderSuggestions(results);
          applyProduct();
        } catch {
          /* keep existing datalist */
        }
      }, 250);
    });

    removeBtn?.addEventListener('click', () => {
      const rows = itemsContainer.querySelectorAll('.line-item-row');
      if (rows.length <= 1) {
        nameInput.value = '';
        row.querySelector('[data-field="notes"]').value = '';
        row.querySelector('[data-field="cost"]').value = '';
        row.querySelector('[data-field="quantity"]').value = '1';
        applyProduct();
        return;
      }
      row.remove();
      reindexRows();
    });

    applyProduct();
  }

  function reindexRows() {
    itemsContainer.querySelectorAll('.line-item-row').forEach((row, index) => {
      row.querySelectorAll('[data-field]').forEach((input) => {
        const field = input.getAttribute('data-field');
        input.name = `items[${index}][${field}]`;
      });
    });
  }

  function addRow(data = {}) {
    const fragment = rowTemplate.content.cloneNode(true);
    const row = fragment.querySelector('.line-item-row');
    if (!row) {
      return;
    }

    const index = itemsContainer.querySelectorAll('.line-item-row').length;
    row.querySelectorAll('[data-field]').forEach((input) => {
      const field = input.getAttribute('data-field');
      input.name = `items[${index}][${field}]`;
      if (data[field] != null) {
        input.value = data[field];
      }
    });

    itemsContainer.appendChild(row);
    bindRow(row);
    reindexRows();
  }

  addBtn.addEventListener('click', () => addRow());

  (async () => {
    try {
      catalog = await loadCatalog();
    } catch (err) {
      console.warn(err);
      catalog = [];
    }

    const preset = itemsContainer.dataset.initialItems;
    if (preset) {
      try {
        const items = JSON.parse(preset);
        if (Array.isArray(items) && items.length > 0) {
          items.forEach((item) => addRow(item));
          return;
        }
      } catch {
        /* fall through */
      }
    }

    if (itemsContainer.querySelectorAll('.line-item-row').length === 0) {
      addRow();
    } else {
      itemsContainer.querySelectorAll('.line-item-row').forEach(bindRow);
      reindexRows();
    }
  })();
})();
