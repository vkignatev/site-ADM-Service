'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buildRequest } = require('../dist/request.js');
const base = { objectType: 'Склад', service: 'Проектирование' };
test('минимальный запрос не содержит пустых необязательных полей', () => {
  const text = buildRequest(base);
  assert.match(text, /Тип объекта: Склад/);
  assert.match(text, /Услуга: Проектирование/);
  assert.doesNotMatch(text, /undefined|null|Город:|Площадь:/);
});
test('подробный запрос сохраняет кириллицу и переносы', () => {
  const text = buildRequest({ ...base, area: '600.5', city: '  Томск  ', description: 'Есть сигнализация.\nНужно обслуживание.' });
  assert.match(text, /600,5 м²/);
  assert.match(text, /Город: Томск\n/);
  assert.match(text, /Есть сигнализация\.\nНужно обслуживание\./);
});
test('отклоняются неизвестные услуги и типы объектов', () => {
  assert.throws(() => buildRequest({ ...base, service: '<script>' }));
  assert.throws(() => buildRequest({ ...base, objectType: '' }));
});
test('отклоняются отрицательные и некорректные площади', () => {
  for (const area of ['-1', '0', 'NaN', 'Infinity', 'abc', '10000001']) assert.throws(() => buildRequest({ ...base, area }));
  assert.doesNotThrow(() => buildRequest({ ...base, area: '1' }));
  assert.doesNotThrow(() => buildRequest({ ...base, area: '10000000' }));
});
test('проверяется длина текстовых полей', () => {
  assert.throws(() => buildRequest({ ...base, city: 'x'.repeat(121) }));
  assert.throws(() => buildRequest({ ...base, description: 'x'.repeat(2001) }));
});
