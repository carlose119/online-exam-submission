import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const view = readFileSync(new URL('../../resources/views/livewire/student/exam/take.blade.php', import.meta.url), 'utf8');

function timer(offset = 0) {
    let elapsed = 0;
    let callback;
    const source = view.match(/x-data="([\s\S]*?)"/)[1]
        .replaceAll('{{ $deadline }}', '2030-01-01T00:00:02Z')
        .replaceAll('{{ $serverNow }}', '2030-01-01T00:00:00Z');
    const clock = Date.parse('2030-01-01T00:00:00Z');
    class ClientDate extends Date { static now() { return clock + offset + elapsed; } }
    const state = vm.runInNewContext(`(${source})`, {
        Date: ClientDate,
        performance: { now: () => elapsed },
        setTimeout: fn => { callback = fn; return 1; },
        clearTimeout: () => { callback = undefined; },
    });
    state.init();
    return { state, advance(ms) { elapsed += ms; const fn = callback; callback = undefined; fn?.(); } };
}

test('idle page keeps polling server authority independently of countdown zero', () => {
    assert.match(view, /wire:poll\.1s\.keep-alive="\$refresh"/);
    const t = timer();
    assert.equal(t.state.timerDisplay, '0:02');
    t.advance(1000);
    assert.equal(t.state.timerDisplay, '0:01');
    t.advance(1000);
    assert.equal(t.state.timerDisplay, '0:00');
    t.advance(5000);
    assert.equal(t.state.timerDisplay, '0:00');
});

for (const offset of [-60000, 60000]) {
    test(`server-relative display ignores client clock offset ${offset} and delayed callbacks`, () => {
        const t = timer(offset);
        assert.equal(t.state.timerDisplay, '0:02');
        t.advance(5000);
        assert.equal(t.state.timerDisplay, '0:00');
    });
}

test('default test startup still delegates directly to Laravel', () => {
    const source = readFileSync(new URL('../TestCase.php', import.meta.url), 'utf8');
    assert.match(source, /if \(\$sandbox === false \|\| \$sandbox === ''\) \{\s*return parent::createApplication\(\);/);
    assert.ok(source.indexOf('useEnvironmentPath') < source.indexOf('->bootstrap()'));
    assert.ok(source.indexOf('useStoragePath') < source.indexOf('->bootstrap()'));
});
