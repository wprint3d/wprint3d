import fs from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

const translations = fs.readFileSync(new URL('../config/translations.js', import.meta.url), 'utf8');
const component = fs.readFileSync(new URL('../components/UserPrinterFileProgress.js', import.meta.url), 'utf8');

test('Spanish ETA uses the complete correctly cased phrase', () => {
    assert.match(translations, /fewSecondsLeft:\s*"Faltan unos segundos"/);
    assert.doesNotMatch(translations, /fewSecondsLeft:\s*"Faltan Unos segundos"/);
});

test('print progress renders a localized recalculating state when ETA is unknown', () => {
    assert.match(translations, /etaRecalculating:\s*"Recalculando el tiempo restante…"/);
    assert.match(component, /t\("files\.etaRecalculating"\)/);
    assert.match(component, /t\("files\.fewSecondsLeft"\)/);
});
