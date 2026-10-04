// Licznik znaków i części SMS – szkic logiki SmsText (rozdz. 3.3, zadanie 2.2).
// Alfabet GSM wg Gammu: podstawowy z „¤”, bez „¹”. Polskie litery (ą, ł, ó…) NIE są w GSM –
// Gammu zamieniłby je po cichu, dlatego panel wybiera wtedy Unicode (UCS-2).
(function () {
  'use strict';

  const GSM_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡' +
    'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
  const GSM_EXT = '^{}\\[~]|€\f';
  const TRANSLIT = {
    'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
    'Ą': 'A', 'Ć': 'C', 'Ę': 'E', 'Ł': 'L', 'Ń': 'N', 'Ó': 'O', 'Ś': 'S', 'Ź': 'Z', 'Ż': 'Z',
    '„': '"', '”': '"', '“': '"', '‘': "'", '’': "'", '–': '-', '—': '-', '…': '...', ' ': ' '
  };
  const MAX_PARTS = 10;

  function analyze(text) {
    let gsm = true, gsmUnits = 0;
    const bad = [];
    for (const ch of text) {
      if (GSM_BASIC.includes(ch)) gsmUnits += 1;
      else if (GSM_EXT.includes(ch)) gsmUnits += 2;
      else { gsm = false; if (!bad.includes(ch)) bad.push(ch); }
    }
    // UCS-2: znak spoza BMP (emoji) zajmuje dwie jednostki – tak jak length w JS
    const units = gsm ? gsmUnits : text.length;
    const single = gsm ? 160 : 70, multi = gsm ? 153 : 67;
    const parts = units === 0 ? 0 : (units <= single ? 1 : Math.ceil(units / multi));
    return { gsm, units, parts, bad, chars: [...text].length, tooLong: parts > MAX_PARTS };
  }

  function translit(text) {
    let out = '';
    for (const ch of text) out += TRANSLIT[ch] !== undefined ? TRANSLIT[ch] : ch;
    return out;
  }

  function plural(n, one, few, many) {
    if (n === 1) return one;
    const d = n % 10, t = n % 100;
    return d >= 2 && d <= 4 && (t < 12 || t > 14) ? few : many;
  }

  function describe(text, doTranslit) {
    const src = doTranslit ? translit(text) : text;
    const a = analyze(src);
    const counter = `${a.chars} ${plural(a.chars, 'znak', 'znaki', 'znaków')} · ${a.parts} SMS · ${a.gsm ? 'GSM-7' : 'Unicode'}`;
    let why = '';
    if (a.tooLong) {
      why = `Za długa wiadomość: ${a.parts} części, limit to ${MAX_PARTS}.`;
    } else if (!a.gsm) {
      const t = analyze(translit(src));
      const list = a.bad.slice(0, 6).join(', ') + (a.bad.length > 6 ? '…' : '');
      why = `<strong>${escapeHtml(list)}</strong> ${a.bad.length === 1 ? 'wymusza' : 'wymuszają'} kodowanie Unicode (70 znaków na SMS zamiast 160)`;
      if (t.gsm && t.parts < a.parts) why += ` – po zamianie polskich znaków ${t.parts} SMS zamiast ${a.parts}.`;
      else why += '.';
    }
    return { counter, why, analysis: a };
  }

  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  window.SmsText = { analyze, translit, describe };
})();
