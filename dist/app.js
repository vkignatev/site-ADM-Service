'use strict';
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#main-nav');
function closeMenu() {
  navigation.classList.remove('is-open');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'Открыть меню');
}
menuButton.addEventListener('click', () => {
  const expanded = menuButton.getAttribute('aria-expanded') !== 'true';
  navigation.classList.toggle('is-open', expanded);
  menuButton.setAttribute('aria-expanded', String(expanded));
  menuButton.setAttribute('aria-label', expanded ? 'Закрыть меню' : 'Открыть меню');
});
navigation.addEventListener('click', (event) => {
  if (event.target.closest('a')) closeMenu();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
    closeMenu();
    menuButton.focus();
  }
});
window.matchMedia('(min-width: 761px)').addEventListener('change', closeMenu);
document.addEventListener('click', (event) => {
  if (!event.target.closest('.header')) closeMenu();
});

const licenseDialog = document.querySelector('#license-dialog');
const privacyDialog = document.querySelector('#privacy-dialog');
document.querySelectorAll('[data-open-license]').forEach((button) => {
  button.addEventListener('click', () => licenseDialog.showModal());
});
document.querySelectorAll('[data-open-privacy]').forEach((button) => {
  button.addEventListener('click', () => privacyDialog.showModal());
});
document.querySelectorAll('dialog').forEach((dialog) => {
  dialog.addEventListener('click', (event) => {
    if (event.target.closest('[data-close-dialog]')) dialog.close();
    else if (event.target === dialog) {
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    }
  });
  dialog.addEventListener('close', () => {
    if (!document.querySelector('dialog[open]')) document.body.classList.remove('dialog-open');
  });
  new MutationObserver(() => {
    document.body.classList.toggle('dialog-open', Boolean(document.querySelector('dialog[open]')));
  }).observe(dialog, { attributes: true, attributeFilter: ['open'] });
});

document.querySelectorAll('[data-service]').forEach((link) => {
  link.addEventListener('click', () => {
    document.querySelector('#service').value = link.dataset.service;
  });
});


const form = document.querySelector('#request-form');
const submitButton = document.querySelector('#submit-request');
const formStatus = document.querySelector('#form-status');
const defaultButtonMarkup = submitButton.innerHTML;
let formEnabled = false, csrf = '', submitting = false, requestId = '', previousPayload = '';
function showUnavailable() {
  formStatus.replaceChildren(document.createTextNode('Онлайн-заявки пока недоступны. Позвоните: '));
  const phone = document.createElement('a'); phone.href = 'tel:+79824121159'; phone.textContent = '+7 982 412 11 59'; formStatus.append(phone, '.');
}
async function configureForm() {
  if (!/^https?:$/.test(location.protocol)) return;
  try {
    const response = await fetch('api.php?action=status', {credentials:'same-origin',cache:'no-store'});
    if (!response.ok) return;
    const status = await response.json(); csrf = status.csrf || ''; formEnabled = status.formEnabled === true; submitButton.disabled = !formEnabled;
    if (formEnabled) formStatus.textContent = 'Заявка будет сохранена и передана специалисту для связи с вами.';
    trackVisit();
  } catch { showUnavailable(); }
}
function trackVisit() {
  if (!csrf || navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true) return;
  let tracked = false;
  function send() {
    if (tracked || document.visibilityState !== 'visible') return;
    tracked = true;
    fetch('api.php?action=track', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({path:location.pathname,referrer:document.referrer}),keepalive:true,signal:AbortSignal.timeout(5000)}).then(response=>response.arrayBuffer()).catch(()=>{});
  }
  send(); document.addEventListener('visibilitychange',send);
}
form.addEventListener('submit', async event => {
  event.preventDefault(); if (submitting) return; if (!formEnabled) {showUnavailable();return;} if (!form.reportValidity()) return;
  const data = Object.fromEntries(new FormData(form));
  const digits = data.phone.replace(/\D/g,'');
  if (digits.length < 10 || digits.length > 15) {document.querySelector('#phone').setCustomValidity('Укажите телефон из 10–15 цифр.');document.querySelector('#phone').reportValidity();return;}
  const payload = JSON.stringify(data);
  if (!requestId || payload !== previousPayload) {requestId = crypto.randomUUID();previousPayload = payload;}
  data.request_id = requestId;
  submitting = true; submitButton.disabled = true; submitButton.textContent = 'Отправляем…'; formStatus.textContent = 'Сохраняем ваше обращение.';
  try {
    const response = await fetch('api.php?action=request', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data),signal:AbortSignal.timeout(45000)});
    const result = await response.json();
    if (!response.ok || result.ok !== true) throw new Error(result.message || 'Не удалось принять заявку. Позвоните: +7 982 412 11 59.');
    formStatus.textContent = result.message || 'Заявка принята. Свяжемся с вами по указанному телефону.';
    form.reset(); requestId = ''; previousPayload = '';
  } catch(error) {
    formStatus.textContent = error.name === 'TimeoutError' ? 'Ответ задерживается. Заявка могла сохраниться. Повторная отправка без изменения полей не создаст дубликат; также можно позвонить: +7 982 412 11 59.' : (error.message && !/fetch|network|load|JSON/i.test(error.message) ? error.message : 'Нет ответа от сервера. Повторите отправку или позвоните: +7 982 412 11 59.');
  } finally {submitting=false;submitButton.disabled=!formEnabled;submitButton.innerHTML=defaultButtonMarkup;}
});
document.querySelector('#phone').addEventListener('input',event=>event.target.setCustomValidity(''));
configureForm();
