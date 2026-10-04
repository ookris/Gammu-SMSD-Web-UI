// Zgodność licznika JS z PHP: node tests/js/run.mjs (te same przypadki co tests/SmsTextTest.php)
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const dir = fileURLToPath(new URL('.', import.meta.url));
const ctx = { globalThis: {} };
vm.createContext(ctx);
vm.runInContext(readFileSync(dir + '../../public/assets/sms-text.js', 'utf8'), ctx);
const S = ctx.globalThis.SmsText;
const cases = JSON.parse(readFileSync(dir + '../cases/smstext.json', 'utf8'));
let failed = 0;
for (const c of cases) {
  let text = c.text ?? c.repeat.map(([ch, n]) => ch.repeat(n)).join('');
  if (c.translit) text = S.translit(text);
  const a = S.analyze(text);
  const ok = a.gsm === c.gsm && a.units === c.units && a.parts === c.parts;
  if (!ok) failed++;
  console.log(`${ok ? '✔' : '✘'} ${c.name}${ok ? '' : ` → gsm=${a.gsm} units=${a.units} parts=${a.parts}`}`);
}
console.log(`\n${failed === 0 ? 'OK' : 'BŁĘDY'}: ${cases.length - failed} zaliczonych, ${failed} nieudanych`);
process.exit(failed === 0 ? 0 : 1);
