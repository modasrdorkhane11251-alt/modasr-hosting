(function () {
  function setValue(id, value) {
    var el = document.getElementById(id);
    if (el) el.value = value == null ? '' : value;
  }

  function setSelect(id, value) {
    var el = document.getElementById(id);
    if (!el) return;
    var wanted = value == null || value === '' ? '/all' : String(value);
    for (var i = 0; i < el.options.length; i++) {
      if (el.options[i].value === wanted) {
        el.value = wanted;
        return;
      }
    }
  }

  function pricingFields(form) {
    var mode = form.querySelector('[data-pricing-mode]');
    var usd = form.querySelector('input[name="price_usd"]');
    var toman = form.querySelector('input[name="price_product"]');
    return { mode: mode, usd: usd, toman: toman };
  }

  function refreshPricing(form, recalculate) {
    var f = pricingFields(form);
    if (!f.mode || !f.usd || !f.toman) return;
    var dynamic = f.mode.value === 'dynamic';
    f.usd.required = dynamic;
    f.usd.readOnly = !dynamic;
    f.toman.readOnly = dynamic;
    form.classList.toggle('is-dynamic', dynamic);
    if (dynamic && recalculate && window.CATALOG_USDT_RATE && parseFloat(f.usd.value) > 0) {
      f.toman.value = Math.round(parseFloat(f.usd.value) * Number(window.CATALOG_USDT_RATE));
    }
  }

  window.openEditModal = function (p) {
    setValue('edit_id', p.id);
    setValue('edit_name', p.name_product);
    setValue('edit_price', p.price_product);
    setValue('edit_price_usd', p.price_usd);
    setValue('edit_volume', p.Volume_constraint);
    setValue('edit_time', p.Service_time);
    setValue('edit_note', p.note);
    setValue('edit_position', p.position);
    setSelect('edit_cat', p.category || '');
    setSelect('edit_agent', p.agent || 'f');
    setSelect('edit_panel', p.Location || '/all');
    setSelect('edit_status', p.status_product || 'active');
    setSelect('edit_pricing_mode', p.pricing_mode || 'fixed');
    ['multi_month', 'inventory_enabled', 'tax_included'].forEach(function (key) {
      var checkbox = document.getElementById('edit_' + key);
      if (checkbox) checkbox.checked = p[key] === undefined || p[key] === null ? key === 'tax_included' : Number(p[key]) === 1;
    });
    var preview = document.getElementById('edit_image_preview');
    if (preview) {
      if (p.image_url) {
        preview.src = p.image_url;
        preview.classList.remove('is-empty');
      } else {
        preview.removeAttribute('src');
        preview.classList.add('is-empty');
      }
    }
    var form = document.querySelector('#editModal [data-pricing-form]');
    if (form) refreshPricing(form, false);
    openModal('editModal');
  };

  document.addEventListener('change', function (event) {
    var form = event.target.closest('[data-pricing-form]');
    if (form && event.target.matches('[data-pricing-mode]')) refreshPricing(form, true);
  });
  document.addEventListener('input', function (event) {
    var form = event.target.closest('[data-pricing-form]');
    if (form && event.target.name === 'price_usd') refreshPricing(form, true);
  });
  document.addEventListener('change', function (event) {
    var input = event.target.closest('[data-image-input]');
    if (!input || !input.files || !input.files[0]) return;
    var preview = document.getElementById(input.getAttribute('data-preview'));
    if (!preview) return;
    preview.src = URL.createObjectURL(input.files[0]);
    preview.classList.remove('is-empty');
    preview.onload = function () { URL.revokeObjectURL(preview.src); };
  });
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-pricing-form]');
    if (!form || !form.checkValidity()) return;
    var button = form.querySelector('button[type="submit"]');
    if (button) {
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
    }
  });
})();
