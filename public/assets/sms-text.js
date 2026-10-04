// Licznik znaków i części SMS – ta sama logika co src/SmsText.php (rozdz. 3.3, zadanie 2.2).
// Alfabet GSM wg Gammu: podstawowy z „¤”, bez „¹” i bez znaku nowej strony. Polskie litery (ą, ł, ó…) NIE są w GSM –
// Gammu zamieniłby je po cichu, dlatego panel wybiera wtedy Unicode (UCS-2). Zgodność: tests/cases/smstext.json.
(function (root) {
  'use strict';

  const GSM_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡' +
    'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
  const GSM_EXT = '^{}\\[~]|€';
  const TRANSLIT = {
    'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
    'Ą': 'A', 'Ć': 'C', 'Ę': 'E', 'Ł': 'L', 'Ń': 'N', 'Ó': 'O', 'Ś': 'S', 'Ź': 'Z', 'Ż': 'Z',
    '„': '"', '”': '"', '“': '"', '‘': "'", '’': "'", '–': '-', '—': '-', '…': '...', ' ': ' '
  };
  const MAX_PARTS = 10;

  function analyze(text) {
    let gsm = true, gsmUnits = 0, ucsUnits = 0, chars = 0;
    const bad = [];
    for (const ch of text) {
      chars++;
      ucsUnits += ch.length; // znak spoza BMP (emoji) = para zastępcza = 2 jednostki
      if (GSM_BASIC.includes(ch)) gsmUnits += 1;
      else if (GSM_EXT.includes(ch)) gsmUnits += 2;
      else { gsm = false; if (!bad.includes(ch)) bad.push(ch); }
    }
    const units = gsm ? gsmUnits : ucsUnits;
    const single = gsm ? 160 : 70, multi = gsm ? 153 : 67;
    const parts = units === 0 ? 0 : (units <= single ? 1 : Math.ceil(units / multi));
    return { gsm, units, parts, bad, chars, tooLong: parts > MAX_PARTS };
  }

  function translit(text) {
    let out = '';
    for (const ch of text) out += TRANSLIT[ch] !== undefined ? TRANSLIT[ch] : ch;
    return out;
  }

  // Zmienne personalizacji: licznik liczy najdłuższy wariant (vars = {imie: '…', nazwa: '…'})
  function personalize(text, vars) {
    if (!vars) return text;
    return text.replace(/\{(imie|nazwa)\}/g, (m, k) => (vars[k] !== undefined ? vars[k] : m));
  }

  function describe(text, doTranslit, vars) {
    const src = personalize(doTranslit ? translit(text) : text, vars && doTranslit ? mapValues(vars, translit) : vars);
    const a = analyze(src);
    const I = root.I18n; // teksty z app.js (Ui::jsTexts)
    const counter = I.t('sms.counter', { chars: I.tn('sms.chars', a.chars), parts: a.parts, coding: a.gsm ? 'GSM-7' : 'Unicode' });
    let why = '';
    if (a.tooLong) {
      why = escapeHtml(I.t('sms.too_long', { parts: a.parts, max: MAX_PARTS }));
    } else if (!a.gsm) {
      const t = analyze(translit(src));
      const list = a.bad.slice(0, 6).join(', ') + (a.bad.length > 6 ? '…' : '');
      why = escapeHtml(I.tn('sms.forces', a.bad.length, { list: '\u0000' })).replace('\u0000', `<strong>${escapeHtml(list)}</strong>`);
      if (t.gsm && t.parts < a.parts) why += ' – ' + escapeHtml(I.t('sms.translit_gain', { after: t.parts, before: a.parts }));
      why += '.';
    }
    return { counter, why, analysis: a };
  }

  function mapValues(obj, fn) {
    const out = {};
    for (const k of Object.keys(obj)) out[k] = fn(obj[k]);
    return out;
  }

  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  root.SmsText = { analyze, translit, describe, personalize, MAX_PARTS };
})(typeof window !== 'undefined' ? window : globalThis);
