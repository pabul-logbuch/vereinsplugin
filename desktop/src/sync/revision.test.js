'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { rowRev, crc32, canonical } = require('./revision');

test('crc32 matches known check vectors', () => {
  assert.equal(crc32('123456789'), 'cbf43926'); // klassischer CRC-32-Prüfwert
  assert.equal(crc32(''), '00000000');
  assert.equal(crc32('The quick brown fox jumps over the lazy dog'), '414fa339');
});

test('canonical form: keys binär sortiert, _rev entfernt, \\x1f-getrennt', () => {
  assert.equal(canonical({ b: '2', a: '1', _rev: 'egal' }), 'a=1\x1fb=2');
  assert.equal(canonical({ id: '5', betrag: '12.50' }), 'betrag=12.50\x1fid=5');
});

test('null wird zu \\N, bool zu 1/0, Zahlen zu String', () => {
  assert.equal(canonical({ a: null }), 'a=\\N');
  assert.equal(canonical({ a: true, b: false }), 'a=1\x1fb=0');
  assert.equal(canonical({ a: 5 }), 'a=5');
});

test('rowRev ist reihenfolgeunabhängig und ignoriert _rev', () => {
  const a = rowRev({ id: '1', name: 'Max', ort: 'Riedlingen' });
  const b = rowRev({ ort: 'Riedlingen', name: 'Max', id: '1', _rev: 'abcdef01' });
  assert.equal(a, b);
  assert.match(a, /^[0-9a-f]{8}$/);
});

test('rowRev ändert sich bei Wertänderung', () => {
  const a = rowRev({ id: '1', betrag: '10.00' });
  const b = rowRev({ id: '1', betrag: '10.01' });
  assert.notEqual(a, b);
});
