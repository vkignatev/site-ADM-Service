(function (root) {
  'use strict';
  const objectTypes = ['Офис', 'Склад', 'Торговое помещение', 'Производство', 'Другой объект'];
  const services = ['Проектирование', 'Монтаж и пусконаладка', 'Техническое обслуживание', 'Обследование и модернизация', 'Нужна консультация'];
  function buildRequest(input) {
    if (!objectTypes.includes(input.objectType) || !services.includes(input.service)) {
      throw new Error('Выберите тип объекта и услугу.');
    }
    const area = String(input.area || '').trim();
    if (area && (!Number.isFinite(Number(area)) || Number(area) < 1 || Number(area) > 10000000)) {
      throw new Error('Укажите площадь от 1 до 10 000 000 м².');
    }
    const city = String(input.city || '').trim();
    const description = String(input.description || '').trim();
    if (city.length > 120 || description.length > 2000) {
      throw new Error('Сократите город до 120 символов и описание до 2000 символов.');
    }
    return [
      'Запрос по пожарной безопасности', '',
      'Тип объекта: ' + input.objectType,
      'Услуга: ' + input.service,
      ...(area ? ['Площадь: ' + Number(area).toLocaleString('ru-RU') + ' м²'] : []),
      ...(city ? ['Город: ' + city] : []),
      ...(description ? ['', 'Задача:', description] : []),
      '', 'Прошу уточнить необходимые исходные данные, состав работ и порядок расчёта стоимости.'
    ].join('\n');
  }
  const api = { buildRequest };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.FireRequest = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
