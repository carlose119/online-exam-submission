import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const executable = source.replace("import Alpine from 'alpinejs';", 'const Alpine = standalone;');
assert.notEqual(executable, source, 'inject only the Alpine import');

function bootstrap(window) {
    let starts = 0;
    const standalone = { start() { starts++; } };
    vm.runInNewContext(executable, { window, standalone });
    return { standalone, starts };
}

test('Livewire retains ownership without starting standalone Alpine', () => {
    const owned = {};
    const window = { Livewire: {}, Alpine: owned };
    const result = bootstrap(window);
    assert.equal(window.Alpine, owned);
    assert.equal(result.starts, 0);
});

test('pages without Livewire still expose and start standalone Alpine', () => {
    const window = {};
    const result = bootstrap(window);
    assert.equal(window.Alpine, result.standalone);
    assert.equal(result.starts, 1);
});
