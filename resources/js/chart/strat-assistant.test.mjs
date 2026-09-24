/**
 * Node smoke-test: parity with StratAssistantIndicatorServiceTest (PHP).
 * Run: node resources/js/chart/strat-assistant.test.mjs
 */
import { createRequire } from 'module';
import { pathToFileURL } from 'url';
import path from 'path';
import { fileURLToPath } from 'url';
import vm from 'vm';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(__dirname, 'strat-assistant.js'), 'utf8');
const sandbox = { window: {}, console };
vm.runInNewContext(src, sandbox);
const SA = sandbox.window.PlatformStratAssistant;

function bar(t, o, h, l, c) {
    return { time: t, open: o, high: h, low: l, close: c, volume: 1 };
}

function assert(cond, msg) {
    if (!cond) throw new Error(msg || 'assert failed');
}

assert(SA.classifyBar({ high: 10, low: 9 }, { high: 11, low: 8 }) === '1', 'inside');
assert(SA.classifyBar({ high: 12, low: 9.5 }, { high: 11, low: 8 }) === '2u', '2u');
assert(SA.classifyBar({ high: 10.5, low: 7 }, { high: 11, low: 8 }) === '2d', '2d');
assert(SA.classifyBar({ high: 12, low: 7 }, { high: 11, low: 8 }) === '3', 'outside');

const rows = [
    bar(1, 10, 11, 9, 10.5),
    bar(2, 10.5, 12, 10, 11.5),
    bar(3, 11.0, 11.8, 9.5, 9.8),
    bar(4, 9.8, 13, 8, 12),
    bar(5, 11.5, 12.5, 9, 11),
    bar(6, 11.2, 13.5, 10, 13),
];
const out = SA.compute(rows);
const texts = out.markers.map((m) => m.text);
assert(texts.includes('22R'), '22R');
assert(texts.includes('312'), '312');
assert(out.meta.combos > 0, 'combos');

const big = [];
let price = 100;
for (let i = 0; i < 5000; i++) {
    price += ((i % 7) - 3) * 0.02;
    big.push(bar(i + 1, price, price + 0.15, price - 0.15, price + 0.01));
}
const t0 = performance.now();
const bigOut = SA.compute(big);
const ms = performance.now() - t0;
assert(bigOut.meta.typed === 4999, 'typed');
assert(ms < 250, 'perf ' + ms);

console.log('strat-assistant.test.mjs OK', { ms: Math.round(ms * 100) / 100, markers: bigOut.markers.length });
