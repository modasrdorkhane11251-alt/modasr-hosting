(() => {
  'use strict';

  const page = document.body.dataset.page || 'dashboard';
  const app = document.getElementById('app');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const state = { offset: 0, limit: 25, instances: [], settings: null };
  const faNumber = new Intl.NumberFormat('fa-IR');

  const e = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
  const money = (value) => `${faNumber.format(Number(value || 0))} تومان`;
  const short = (value, length = 90) => String(value ?? '').length > length ? `${String(value).slice(0, length)}…` : String(value ?? '');

  function toast(title, message, type = 'success') {
    const region = document.getElementById('toastRegion');
    const item = document.createElement('div');
    item.className = `toast ${type}`;
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    item.innerHTML = `<strong>${e(title)}</strong><p>${e(message)}</p>`;
    region.appendChild(item);
    window.setTimeout(() => item.remove(), 4100);
  }

  async function request(action, options = {}) {
    const init = { method: options.method || 'GET', headers: {} };
    if (options.method === 'POST') {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      const body = new URLSearchParams();
      body.set('csrf_token', csrf);
      Object.entries(options.data || {}).forEach(([key, value]) => {
        if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
          Object.entries(value).forEach(([childKey, childValue]) => body.set(`${key}[${childKey}]`, childValue));
        } else {
          body.set(key, value);
        }
      });
      init.body = body.toString();
    }
    const [actionName, actionQuery] = action.split('&', 2);
    const response = await fetch(`api/index.php?action=${encodeURIComponent(actionName)}${actionQuery ? `&${actionQuery}` : ''}`, init);
    const contentType = response.headers.get('content-type') || '';
    const data = contentType.includes('application/json') ? await response.json() : { ok: false, error: 'server_error' };
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
  }

  function loading(message = 'در حال بارگذاری اطلاعات واقعی Tenant…') {
    app.innerHTML = `<div class="loading-block" role="status"><span class="spinner" aria-hidden="true"></span><span>${e(message)}</span></div>`;
  }

  function emptyState(message) {
    return `<div class="empty-state">${e(message)}</div>`;
  }

  function statusBadge(status) {
    return `<span class="status-badge ${e(status)}"><span class="status-dot"></span>${e(status)}</span>`;
  }

  function panel(title, subtitle, body, tools = '') {
    return `<section class="panel"><div class="panel-header"><div><h2>${e(title)}</h2><p>${e(subtitle)}</p></div><div class="toolbar">${tools}</div></div><div class="panel-body">${body}</div></section>`;
  }

  function table(headers, rows) {
    if (!rows.length) return emptyState('رکوردی برای نمایش وجود ندارد.');
    return `<div class="table-wrap"><table><thead><tr>${headers.map((header) => `<th>${e(header)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
  }

  function row(cells) {
    return `<tr>${cells.map((cell) => `<td>${cell}</td>`).join('')}</tr>`;
  }

  function actionButton(label, action, id, style = '') {
    return `<button type="button" class="button button-small ${e(style)}" data-action="${e(action)}" data-id="${e(id)}">${e(label)}</button>`;
  }

  function confirmAction(title, message, onConfirm) {
    const backdrop = document.getElementById('modalBackdrop');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    modalTitle.textContent = title;
    modalBody.innerHTML = `<p>${e(message)}</p><div class="form-actions"><button class="button" type="button" id="confirmYes">تأیید و ادامه</button><button class="button button-ghost" type="button" data-close-modal>انصراف</button></div>`;
    backdrop.hidden = false;
    document.getElementById('confirmYes').onclick = () => {
      backdrop.hidden = true;
      onConfirm();
    };
    backdrop.querySelector('.modal-close').focus();
  }

  function closeModal() {
    document.getElementById('modalBackdrop').hidden = true;
  }

  document.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', closeModal));
  document.getElementById('modalBackdrop').addEventListener('click', (event) => {
    if (event.target.id === 'modalBackdrop') closeModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });

  const sidebar = document.getElementById('sidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  sidebarToggle.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    sidebarToggle.setAttribute('aria-expanded', String(open));
  });
  document.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', () => {
    sidebar.classList.remove('open');
    sidebarToggle.setAttribute('aria-expanded', 'false');
  }));

  document.querySelector('[data-action="logout"]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await request('auth.logout', { method: 'POST', data: { csrf_token: form.csrf_token.value } });
      window.location.reload();
    } catch (error) {
      toast('خطا در خروج', error.message, 'error');
    }
  });

  async function loadDashboard() {
    loading();
    const data = await request('dashboard');
    const metrics = data.metrics;
    const cards = [
      ['کاربران ربات', metrics.users, 'کاربر ثبت‌شده در Tenant'],
      ['موجودی کل کاربران', money(metrics.balance), 'جمع Balance در دیتابیس مشتری'],
      ['سفارش‌ها', metrics.orders, 'کل Invoiceهای ثبت‌شده'],
      ['Instances فعال', `${metrics.active_instances} از ${metrics.instances}`, 'وضعیت واقعی Runtimeها'],
    ];
    const metricHtml = `<div class="grid metrics">${cards.map(([label, value, note]) => `<article class="metric"><span class="metric-label">${e(label)}</span><strong class="metric-value">${e(value)}</strong><span class="metric-note">${e(note)}</span></article>`).join('')}</div>`;
    const orderRows = data.orders.map((item) => row([
      `<span class="mono">${e(item.id_invoice)}</span>`,
      e(item.username),
      e(item.name_product),
      `<span class="amount">${money(item.price_product)}</span>`,
      `<span class="mono">${e(item.time_sell)}</span>`,
      statusBadge(item.Status),
    ]));
    const auditRows = data.audit.map((item) => row([
      e(item.action),
      e(item.object_type),
      `<span class="mono">${e(item.object_id)}</span>`,
      e(item.actor_role),
      `<span class="mono">${e(item.created_at)}</span>`,
    ]));
    app.innerHTML = metricHtml
      + panel('آخرین سفارش‌ها', 'داده واقعی دیتابیس مشتری', table(['شماره سفارش', 'کاربر', 'محصول', 'مبلغ', 'زمان', 'وضعیت'], orderRows))
      + panel('رویدادهای امنیتی و عملیاتی', 'Audit Hash-Chain', table(['Action', 'Object', 'شناسه', 'نقش', 'زمان'], auditRows));
  }

  async function loadUsers() {
    loading();
    const query = new URLSearchParams(window.location.search);
    const q = query.get('q') || '';
    state.offset = Math.max(0, Number(query.get('offset') || 0));
    const data = await request(`users&limit=${state.limit}&offset=${state.offset}&q=${encodeURIComponent(q)}`);
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.id)}</span>`,
      e(item.username || '—'),
      `<span class="amount">${money(item.Balance)}</span>`,
      e(item.agent),
      `<span class="mono">${e(item.register)}</span>`,
      `<span class="mono">${e(item.last_message_time)}</span>`,
      statusBadge(item.User_Status),
    ]));
    const tools = `<form id="userSearch" class="toolbar"><div class="field"><label for="userQ">جستجو</label><input id="userQ" name="q" value="${e(q)}" placeholder="آیدی یا نام کاربری" autocomplete="off"></div><button class="button" type="submit">اعمال فیلتر</button></form>`;
    app.innerHTML = panel('فهرست کاربران', 'فقط کاربران Tenant جاری', table(['آیدی', 'نام کاربری', 'موجودی', 'نقش', 'عضویت', 'آخرین فعالیت', 'وضعیت'], rows), tools)
      + `<div class="pagination"><button class="button button-ghost" id="prevPage" ${state.offset === 0 ? 'disabled' : ''}>قبلی</button><span class="muted">آفست ${faNumber.format(state.offset)}</span><button class="button button-ghost" id="nextPage" ${data.items.length < state.limit ? 'disabled' : ''}>بعدی</button></div>`;
    document.getElementById('userSearch').addEventListener('submit', (event) => {
      event.preventDefault();
      const value = document.getElementById('userQ').value;
      window.location.href = `?page=users&q=${encodeURIComponent(value)}`;
    });
    document.getElementById('prevPage').addEventListener('click', () => {
      window.location.href = `?page=users&q=${encodeURIComponent(q)}&offset=${Math.max(0, state.offset - state.limit)}`;
    });
    document.getElementById('nextPage').addEventListener('click', () => {
      window.location.href = `?page=users&q=${encodeURIComponent(q)}&offset=${state.offset + state.limit}`;
    });
  }

  async function loadProducts() {
    loading();
    const data = await request('products');
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.code_product)}</span>`,
      e(item.name_product),
      `<span class="amount">${money(item.price_product)}</span>`,
      e(item.Location),
      e(item.Service_time),
      e(item.category),
      statusBadge(item.status_product),
      actionButton(item.status_product === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'product-toggle', item.code_product, item.status_product === 'active' ? 'button-warning' : ''),
    ]));
    app.innerHTML = panel('محصولات و قیمت‌ها', 'تغییر وضعیت با CSRF و ثبت Audit', table(['کد', 'نام محصول', 'قیمت', 'لوکیشن', 'زمان', 'دسته', 'وضعیت', 'عملیات'], rows));
  }

  async function loadOrders() {
    loading();
    const currentStatus = new URLSearchParams(window.location.search).get('status') || '';
    const data = await request(`orders&status=${encodeURIComponent(currentStatus)}`);
    const statuses = ['', 'active', 'Unpaid', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'paid'];
    const options = statuses.map((status) => `<option value="${e(status)}" ${status === currentStatus ? 'selected' : ''}>${status === '' ? 'همه وضعیت‌ها' : e(status)}</option>`).join('');
    const tools = `<form id="orderFilter" class="toolbar"><div class="field"><label for="orderStatus">فیلتر وضعیت</label><select id="orderStatus" name="status">${options}</select></div><button class="button" type="submit">اعمال</button><a class="button button-ghost" href="api/index.php?action=export.orders">دانلود CSV</a></form>`;
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.id_invoice)}</span>`,
      `<span class="mono">${e(item.id_user)}</span>`,
      e(item.username),
      e(item.name_product),
      `<span class="amount">${money(item.price_product)}</span>`,
      `<span class="amount">${money(item.payable_amount)}</span>`,
      `<span class="mono">${e(item.time_sell)}</span>`,
      statusBadge(item.Status),
    ]));
    app.innerHTML = panel('سفارش‌ها', 'مبالغ تومان و داده واقعی Invoice', table(['شماره', 'کاربر', 'نام کاربری', 'محصول', 'قیمت', 'پرداختنی', 'زمان', 'وضعیت'], rows), tools);
    document.getElementById('orderFilter').addEventListener('submit', (event) => {
      event.preventDefault();
      window.location.href = `?page=orders&status=${encodeURIComponent(document.getElementById('orderStatus').value)}`;
    });
  }

  async function loadPayments() {
    loading();
    const data = await request('payments');
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.id)}</span>`,
      `<span class="mono">${e(item.id_user)}</span>`,
      `<span class="mono">${e(item.id_order)}</span>`,
      `<span class="amount">${money(item.price)}</span>`,
      e(item.Payment_Method),
      statusBadge(item.payment_Status),
      `<span class="mono">${e(item.time)}</span>`,
      `<span class="mono">${e(item.id_invoice)}</span>`,
    ]));
    app.innerHTML = panel('گزارش پرداخت‌ها', 'وضعیت و مرجع هر پرداخت', table(['شناسه', 'کاربر', 'سفارش', 'مبلغ', 'روش', 'وضعیت', 'زمان', 'Invoice'], rows), '<a class="button button-ghost" href="api/index.php?action=export.payments">دانلود CSV</a>');
  }

  async function loadPanels() {
    loading();
    const data = await request('panels');
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.id)}</span>`,
      `<span class="mono">${e(item.code_panel)}</span>`,
      e(item.name_panel),
      e(item.type),

      e(item.agent),
      statusBadge(item.status),
      actionButton(item.status === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'panel-toggle', item.id, item.status === 'active' ? 'button-warning' : ''),
    ]));
    app.innerHTML = panel('پنل‌های VPN', 'فقط اطلاعات غیرحساس و وضعیت اتصال‌ها؛ Secret نمایش داده نمی‌شود', table(['شناسه', 'کد', 'نام', 'نوع', 'Agent', 'وضعیت', 'عملیات'], rows));
  }

  async function loadInstances() {
    loading();
    const data = await request('instances');
    state.instances = data.items;
    const rows = data.items.map((item) => {
      const actions = item.runtime_status === 'active'
        ? actionButton('توقف', 'instance-stop', item.id, 'button-warning') + actionButton('راه‌اندازی مجدد', 'instance-restart', item.id, 'button-ghost')
        : actionButton('راه‌اندازی', 'instance-start', item.id, '');
      return row([
        `<span class="mono">${e(item.platform)}</span>`,
        e(item.username_snapshot),
        e(item.product_code),
        e(item.runtime_kind),
        statusBadge(item.runtime_status),
        `<span class="mono">${e(item.adapter_version)} / ${e(item.product_version)}</span>`,
        `<span class="mono">${e(item.last_health_at || '—')}</span>`,
        `<div class="toolbar">${actions}</div>`,
      ]);
    });
    app.innerHTML = panel('Bot Instances', 'Start / Stop / Restart واقعی با مجوز و Audit', table(['پلتفرم', 'ربات', 'محصول', 'Runtime', 'وضعیت', 'نسخه', 'Health', 'عملیات'], rows));
  }

  async function loadSettings() {
    loading();
    const data = await request('settings');
    state.settings = data.settings;
    const field = (key, label, type = 'text') => `<div class="field"><label for="setting-${e(key)}">${e(label)}</label><input id="setting-${e(key)}" name="${e(key)}" type="${type}" value="${e(state.settings[key] ?? '')}" required></div>`;
    app.innerHTML = panel('تنظیمات امن', 'فقط فیلدهای غیرحساس و مجاز ویرایش می‌شوند', `<form id="settingsForm"><div class="form-grid">
      <div class="field"><label for="setting-Bot_Status">وضعیت ربات</label><select id="setting-Bot_Status" name="Bot_Status"><option value="botstatuson" ${state.settings.Bot_Status === 'botstatuson' ? 'selected' : ''}>فعال</option><option value="botstatusoff" ${state.settings.Bot_Status === 'botstatusoff' ? 'selected' : ''}>غیرفعال</option></select></div>
      ${field('limit_usertest_all', 'حداکثر درخواست روزانه', 'number')}
      ${field('id_support', 'یوزرنیم پشتیبانی')}
      ${field('affiliatespercentage', 'درصد Commission', 'number')}
      </div><div class="form-actions"><button class="button" type="submit">ذخیره تنظیمات</button><button class="button button-ghost" type="reset">بازگشت</button></div></form>`);
    document.getElementById('settingsForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const settings = {};
      Array.from(form.elements).forEach((element) => {
        if (element.name) settings[element.name] = element.value;
      });
      try {
        const result = await request('settings.save', { method: 'POST', data: { settings } });
        state.settings = result.settings;
        toast('تنظیمات ذخیره شد', 'مقادیر مجاز با موفقیت به‌روزرسانی شدند.');
        loadSettings();
      } catch (error) {
        toast('ذخیره نشد', error.message, 'error');
      }
    });
  }

  async function loadSupport() {
    loading();
    await loadInstancesData();
    const data = await request('support');
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.Tracking)}</span>`,
      `<span class="mono">${e(item.iduser)}</span>`,
      e(item.name_departman),
      short(item.text, 70),
      statusBadge(item.status),
      `<span class="mono">${e(item.time)}</span>`,
    ]));
    const instanceOptions = state.instances.map((item) => `<option value="${e(item.id)}">${e(item.platform)} — ${e(item.username_snapshot)}</option>`).join('');
    const form = `<form id="supportReply"><div class="form-grid">
      <div class="field"><label for="supportUser">آیدی کاربر مقصد</label><input id="supportUser" name="user_id" required inputmode="numeric" placeholder="Telegram User ID"></div>
      <div class="field"><label for="supportInstance">Instance ارسال‌کننده</label><select id="supportInstance" name="instance_id" required>${instanceOptions}</select></div>
      <div class="field" style="grid-column:1/-1"><label for="supportText">متن پاسخ</label><textarea id="supportText" name="text" required maxlength="2000" placeholder="پاسخ پشتیبانی را بنویسید…"></textarea></div>
      </div><div class="form-actions"><button class="button" type="submit">ارسال پاسخ واقعی</button></div></form>`;
    app.innerHTML = panel('ارسال پاسخ پشتیبانی', 'ارسال با Token رمزنگاری‌شده Instance و ثبت رویداد', form)
      + panel('پیام‌های اخیر', 'پیام‌های ثبت‌شده در Tenant', table(['Tracking', 'کاربر', 'دپارتمان', 'متن', 'وضعیت', 'زمان'], rows));
    document.getElementById('supportReply').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        await request('support.reply', { method: 'POST', data: {
          user_id: document.getElementById('supportUser').value,
          instance_id: document.getElementById('supportInstance').value,
          text: document.getElementById('supportText').value,
        } });
        toast('پاسخ ارسال شد', 'پیام پشتیبانی برای کاربر ارسال و در Audit ثبت شد.');
        loadSupport();
      } catch (error) {
        submit.disabled = false;
        toast('ارسال نشد', error.message, 'error');
      }
    });
  }

  async function loadInstancesData() {
    if (!state.instances.length) {
      const data = await request('instances');
      state.instances = data.items;
    }
  }

  async function loadBackups() {
    loading();
    const data = await request('backup.list');
    const canCreate = !data.owner_only;
    const tools = canCreate ? '<button class="button" id="createBackup" type="button">ساخت Backup رمزنگاری‌شده</button>' : '<span class="muted">ساخت Backup فقط با مالک اصلی است</span>';
    const rows = data.items.map((item) => row([
      `<span class="mono">${e(item.id)}</span>`,
      `<span class="amount">${faNumber.format(item.size)} B</span>`,
      `<span class="mono">${e(item.created_at)}</span>`,
      `<span class="mono">${e(item.sha256)}</span>`,
      `<a class="button button-small button-ghost" href="api/index.php?action=backup.download&id=${encodeURIComponent(item.id)}">دانلود</a>`,
    ]));
    app.innerHTML = panel('Backup و بازیابی', 'فایل رمزنگاری‌شده؛ بدون Config، Env یا Secret در آرشیو', table(['فایل', 'حجم', 'زمان', 'SHA-256', 'عملیات'], rows), tools);
    if (canCreate) {
      document.getElementById('createBackup').addEventListener('click', () => {
        confirmAction('ساخت Backup', 'یک Backup رمزنگاری‌شده از Factory ساخته شود؟', async () => {
          try {
            const result = await request('backup.create', { method: 'POST' });
            toast('Backup ساخته شد', result.item.id);
            loadBackups();
          } catch (error) {
            toast('Backup ناموفق', error.message, 'error');
          }
        });
      });
    }
  }

  async function loadAudit() {
    loading();
    const data = await request('audit');
    const rows = data.items.map((item) => row([
      e(item.action),
      e(item.object_type),
      `<span class="mono">${e(item.object_id)}</span>`,
      e(item.actor_role),
      short(item.metadata_json, 65),
      `<span class="mono">${e(item.previous_hash)}</span>`,
      `<span class="mono">${e(item.created_at)}</span>`,
    ]));
    app.innerHTML = panel('Audit Log', 'رویدادهای امنیتی و عملیات حساس با Hash Chain', table(['Action', 'Object', 'شناسه', 'نقش', 'Metadata', 'Previous Hash', 'زمان'], rows));
  }

  const actions = {
    'product-toggle': (id) => confirmAction('تغییر وضعیت محصول', 'وضعیت این محصول تغییر کند؟', async () => {
      try {
        const result = await request('products.toggle', { method: 'POST', data: { code_product: id } });
        toast('وضعیت تغییر کرد', `وضعیت جدید: ${result.status_product}`);
        loadProducts();
      } catch (error) {
        toast('عملیات ناموفق', error.message, 'error');
      }
    }),
    'panel-toggle': (id) => confirmAction('تغییر وضعیت پنل', 'وضعیت این پنل تغییر کند؟', async () => {
      try {
        const result = await request('panels.toggle', { method: 'POST', data: { id } });
        toast('وضعیت تغییر کرد', `وضعیت جدید: ${result.status}`);
        loadPanels();
      } catch (error) {
        toast('عملیات ناموفق', error.message, 'error');
      }
    }),
    'instance-start': (id) => confirmAction('راه‌اندازی Instance', 'Instance روی سرور Start شود؟', async () => {
      try {
        await request('instances.action', { method: 'POST', data: { instance_id: id, runtime_action: 'start' } });
        toast('Instance فعال شد', 'سرویس با موفقیت راه‌اندازی شد.');
        loadInstances();
      } catch (error) {
        toast('راه‌اندازی ناموفق', error.message, 'error');
      }
    }),
    'instance-stop': (id) => confirmAction('توقف Instance', 'Instance متوقف شود؟', async () => {
      try {
        await request('instances.action', { method: 'POST', data: { instance_id: id, runtime_action: 'stop' } });
        toast('Instance متوقف شد', 'سرویس متوقف و در Audit ثبت شد.');
        loadInstances();
      } catch (error) {
        toast('توقف ناموفق', error.message, 'error');
      }
    }),
    'instance-restart': (id) => confirmAction('راه‌اندازی مجدد Instance', 'Instance متوقف و دوباره راه‌اندازی شود؟', async () => {
      try {
        await request('instances.action', { method: 'POST', data: { instance_id: id, runtime_action: 'restart' } });
        toast('Restart انجام شد', 'سرویس با موفقیت راه‌اندازی مجدد شد.');
        loadInstances();
      } catch (error) {
        toast('Restart ناموفق', error.message, 'error');
      }
    }),
  };

  app.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const handler = actions[button.dataset.action];
    if (handler) handler(button.dataset.id);
  });

  const loaders = {
    dashboard: loadDashboard,
    users: loadUsers,
    products: loadProducts,
    orders: loadOrders,
    payments: loadPayments,
    panels: loadPanels,
    instances: loadInstances,
    support: loadSupport,
    settings: loadSettings,
    backups: loadBackups,
    audit: loadAudit,
  };

  if (app) {
    const loader = loaders[page] || loadDashboard;
    loader().catch((error) => {
      app.innerHTML = `<div class="alert danger" role="alert">بارگذاری اطلاعات با خطا مواجه شد: ${e(error.message)}</div>`;
    });
  }
})();
