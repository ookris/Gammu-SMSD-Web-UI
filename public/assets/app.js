// SMS Gateway – drobne interakcje panelu. Bez JS inline (CSP): wszystko przez atrybuty data-*.
(function () {
  'use strict';
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // Teksty interfejsu z <script type="application/json" id="i18n"> (Ui::jsTexts) – te same klucze co t()/tn() w PHP
  let i18n = null;
  const entry = (key) => {
    if (i18n === null) {
      const el = document.getElementById('i18n');
      try { i18n = el ? JSON.parse(el.textContent) : {}; } catch (err) { i18n = {}; }
    }
    return (i18n.t || {})[key];
  };
  const fill = (text, vars) => text.replace(/\{([a-z_]+)\}/g, (m, k) => (vars && vars[k] !== undefined ? String(vars[k]) : m));
  const pluralIndex = (n) => {
    if (i18n.lang !== 'pl') return n === 1 ? 0 : 1;
    if (n === 1) return 0;
    const d = n % 10, h = n % 100;
    return d >= 2 && d <= 4 && (h < 12 || h > 14) ? 1 : 2;
  };
  window.I18n = {
    t: (key, vars) => { const s = entry(key); return typeof s === 'string' ? fill(s, vars) : key; },
    tn: (key, n, vars) => {
      const f = entry(key);
      return Array.isArray(f) ? fill(f[Math.min(pluralIndex(n), f.length - 1)], Object.assign({ n }, vars)) : key;
    },
  };

  // Menu boczne na telefonie
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-nav-toggle]');
    if (toggle) {
      const open = document.body.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', String(open));
      return;
    }
    if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar')) {
      document.body.classList.remove('nav-open');
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.body.classList.remove('nav-open');
  });

  // Okna dialogowe: data-dialog-open="id", data-dialog-close; dialog[data-autoopen] otwiera się od razu
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-dialog-open]');
    if (opener) {
      const dlg = document.getElementById(opener.dataset.dialogOpen);
      if (dlg && typeof dlg.showModal === 'function') { e.preventDefault(); dlg.showModal(); }
      return;
    }
    const closer = e.target.closest('[data-dialog-close]');
    if (closer) { e.preventDefault(); closer.closest('dialog')?.close(); return; }
    if (e.target instanceof HTMLDialogElement) e.target.close(); // kliknięcie w tło
  });

  // Potwierdzenie akcji: <button data-confirm="Tytuł" data-confirm-text="…" data-confirm-ok="Usuń"> lub <a data-confirm>
  let confirmed = null;
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el || el === confirmed) return;
    const dlg = document.getElementById('confirm-dialog');
    if (!dlg) return;
    e.preventDefault();
    dlg.querySelector('h2').textContent = el.dataset.confirm;
    const p = dlg.querySelector('[data-confirm-text]');
    p.textContent = el.dataset.confirmText || '';
    p.hidden = !el.dataset.confirmText;
    const old = dlg.querySelector('[data-confirm-ok]');
    const ok = old.cloneNode(false); // nowy przycisk = bez obsługi z poprzedniego (anulowanego) okna
    old.replaceWith(ok);
    if (!old.dataset.label) old.dataset.label = old.textContent; // domyślny tekst z układu (w języku panelu)
    ok.dataset.label = old.dataset.label;
    ok.textContent = el.dataset.confirmOk || ok.dataset.label;
    ok.addEventListener('click', () => {
      dlg.close();
      if (el instanceof HTMLAnchorElement) { location.href = el.href; return; }
      confirmed = el;
      if (el.form && el.type === 'submit') el.form.requestSubmit(el); else el.click();
      confirmed = null;
    }, { once: true });
    dlg.showModal();
  });

  // Zaznacz wszystkie + licznik w pasku akcji zbiorczych
  function updateBulk(table) {
    const boxes = $$('tbody input[type="checkbox"]', table);
    const n = boxes.filter((b) => b.checked).length;
    const card = table.closest('.card');
    const bar = card?.querySelector('.bulkbar');
    if (bar) {
      bar.hidden = n === 0;
      const cnt = bar.querySelector('[data-bulk-count]');
      if (cnt) cnt.textContent = String(n);
    }
    const all = table.querySelector('thead input[data-check-all]');
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
  }
  document.addEventListener('change', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || t.type !== 'checkbox') return;
    const table = t.closest('table');
    if (!table) return;
    if (t.matches('[data-check-all]')) $$('tbody input[type="checkbox"]', table).forEach((b) => { b.checked = t.checked; });
    updateBulk(table);
  });

  // Licznik SMS: <textarea data-sms data-sms-counter="id" data-sms-why="id">, <input data-sms-translit="idTextarea">
  function refreshSms(area) {
    if (!window.SmsText) return;
    const tr = document.querySelector(`[data-sms-translit="${area.id}"]`);
    const d = window.SmsText.describe(area.value, tr ? tr.checked : false);
    const c = document.getElementById(area.dataset.smsCounter || '');
    const w = document.getElementById(area.dataset.smsWhy || '');
    if (c) c.textContent = d.counter;
    if (w) { w.innerHTML = d.why; w.hidden = d.why === ''; }
  }
  document.addEventListener('input', (e) => {
    if (e.target instanceof HTMLTextAreaElement && e.target.matches('[data-sms]')) refreshSms(e.target);
  });
  document.addEventListener('change', (e) => {
    const cb = e.target.closest('[data-sms-translit]');
    if (cb) { const a = document.getElementById(cb.dataset.smsTranslit); if (a) refreshSms(a); }
  });

  // Wstawianie zmiennych do treści: data-insert="{imie}" data-target="id"
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-insert]');
    if (!b) return;
    const area = document.getElementById(b.dataset.target);
    if (!area) return;
    const s = area.selectionStart ?? area.value.length, en = area.selectionEnd ?? s;
    area.value = area.value.slice(0, s) + b.dataset.insert + area.value.slice(en);
    area.focus();
    area.selectionStart = area.selectionEnd = s + b.dataset.insert.length;
    area.dispatchEvent(new Event('input', { bubbles: true }));
  });

  // Szablon: <select data-template-target="idTextarea">, opcje z data-body
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('select[data-template-target]');
    if (!sel) return;
    const opt = sel.selectedOptions[0];
    const area = document.getElementById(sel.dataset.templateTarget);
    if (area && opt && opt.dataset.body !== undefined) {
      area.value = opt.dataset.body;
      area.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  // Usunięcie błędnego numeru z pola: data-remove-number="601 23" data-target="numbers"
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-number]');
    if (!b) return;
    const area = document.getElementById(b.dataset.target);
    if (!area) return;
    const bad = b.dataset.removeNumber;
    area.value = area.value.split(/[,;\n\r]+/).map((s) => s.trim()).filter((s) => s !== '' && s !== bad).join('\n');
    area.dispatchEvent(new Event('change', { bubbles: true }));
    b.closest('li')?.remove();
  });

  // Szybkie kody USSD: data-fill="idPola" data-value="*101#"
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fill]');
    if (!b) return;
    const input = document.getElementById(b.dataset.fill);
    if (input) { input.value = b.dataset.value; input.focus(); }
  });

  // Dodanie wiersza z szablonu: data-add-row="idSzablonu" data-target="idKontenera"
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-add-row]');
    if (!b) return;
    const tpl = document.getElementById(b.dataset.addRow);
    const box = document.getElementById(b.dataset.target);
    if (tpl instanceof HTMLTemplateElement && box) box.appendChild(tpl.content.cloneNode(true));
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-row]');
    if (b) b.closest('.row')?.remove();
  });

  // Automatyczne wysłanie formularza filtrów po zmianie: <form data-autosubmit>
  document.addEventListener('change', (e) => {
    const f = e.target.closest('form[data-autosubmit]');
    if (f && !e.target.matches('[type="search"]')) f.requestSubmit();
  });

  // Inicjalizacja treści (strona i fragmenty htmx)
  function init(root) {
    $$('textarea[data-sms]', root).forEach(refreshSms);
    $$('table', root).forEach((t) => { if (t.querySelector('[data-check-all]')) updateBulk(t); });
    $$('dialog[data-autoopen]', root).forEach((d) => { if (!d.open) d.showModal(); d.removeAttribute('data-autoopen'); });
    $$('[data-scroll-bottom]', root).forEach((el) => { el.scrollTop = el.scrollHeight; });
  }
  init(document);
  document.addEventListener('htmx:afterSettle', (e) => init(e.target));
  if (location.hash.length > 1) {
    const dlg = document.getElementById(location.hash.slice(1));
    if (dlg instanceof HTMLDialogElement) dlg.showModal();
  }
})();
