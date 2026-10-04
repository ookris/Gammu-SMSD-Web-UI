// SMS Gateway – drobne interakcje panelu. Bez JS inline (CSP): wszystko przez atrybuty data-*.
(function () {
  'use strict';
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

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

  // Okna dialogowe: data-dialog-open="id", data-dialog-close; adres z #id otwiera okno od razu
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
  if (location.hash.length > 1) {
    const dlg = document.getElementById(location.hash.slice(1));
    if (dlg instanceof HTMLDialogElement) dlg.showModal();
  }

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
  $$('table').forEach((t) => { if (t.querySelector('[data-check-all]')) updateBulk(t); });

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
  $$('textarea[data-sms]').forEach((a) => {
    a.addEventListener('input', () => refreshSms(a));
    refreshSms(a);
  });
  $$('[data-sms-translit]').forEach((cb) => cb.addEventListener('change', () => {
    const a = document.getElementById(cb.dataset.smsTranslit);
    if (a) refreshSms(a);
  }));

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

  // Szybkie kody USSD: data-fill="idPola" data-value="*101#"
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fill]');
    if (!b) return;
    const input = document.getElementById(b.dataset.fill);
    if (input) { input.value = b.dataset.value; input.focus(); }
  });
})();
