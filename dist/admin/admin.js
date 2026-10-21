'use strict';
const $ = (selector) => document.querySelector(selector);
const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = String(text); return node; };
const labels = {pending:'Ожидает отправки',sent:'Отправлено',failed:'Ошибка отправки',unconfigured:'Почта не настроена',sending:'Отправляется',unknown:'Статус неизвестен',new:'Новая',contacted:'Связались',done:'Завершена'};
const titles = {dashboard:'Обзор сайта',leads:'Заявки',mail:'Настройки почты',account:'Доступ администратора'};
let csrf = '', authenticated = false, installed = false, currentTab = 'dashboard', leadPage = 1, selectedLead = null, dashboardRevision = 0, chartSeries = null;
function status(selector, message = '', isError = false) { const node = $(selector); node.textContent = message; node.classList.toggle('error', isError); node.classList.toggle('success', !isError && Boolean(message)); }
async function api(action, data, parameters = {}) {
 const url = new URL('../api.php', location.href); url.searchParams.set('action', action);
 Object.entries(parameters).forEach(([key,value]) => url.searchParams.set(key, value));
 const options = {credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(45000)};
 if (data !== undefined) { options.method = 'POST'; options.headers = {'Content-Type':'application/json','X-CSRF-Token':csrf}; options.body = JSON.stringify(data); }
 let response;
 try { response = await fetch(url, options); } catch { throw new Error('Нет ответа от сервера. Проверьте соединение и обновите страницу.'); }
 let result;
 try { result = await response.json(); } catch { throw new Error('Сервер вернул неверный ответ. Проверьте настройки PHP.'); }
 if (!response.ok) {
  if (response.status === 401 && authenticated) { authenticated = false; $('#admin-view').hidden = true; $('#auth-view').hidden = false; status('#auth-status','Сессия завершена. Войдите снова.',true); }
  throw new Error(result.message || 'Не удалось выполнить действие.');
 }
 if (result.csrf) csrf = result.csrf;
 return result;
}
async function busy(form, operation) {
 const buttons = [...form.querySelectorAll('button')]; buttons.forEach(b => b.disabled = true);
 form.inert = true; form.setAttribute('aria-busy','true');
 try { return await operation(); } finally { buttons.forEach(b => b.disabled = false); form.inert = false; form.setAttribute('aria-busy','false'); }
}
async function initialize() {
 try {
  const user = await api('me'); installed = user.installed; authenticated = user.authenticated;
  $('#auth-view').hidden = authenticated; $('#admin-view').hidden = !authenticated;
  if (authenticated) { $('#admin-login').textContent = user.login; $('.live-label').textContent = user.localSetup ? 'Локальный просмотр' : 'Данные вашего сайта'; await openTab(currentTab); }
  else {
   $('#auth-title').textContent = installed ? 'Вход в панель' : 'Создать администратора';
   $('#auth-description').textContent = installed ? 'Статистика, обращения и почтовые настройки.' : 'Задайте единственный логин и пароль. После установки повторное создание учётной записи будет закрыто.';
   $('#auth-submit').textContent = installed ? 'Войти' : 'Создать и войти';
   $('#install-key-field').hidden = installed || user.localSetup;
   $('#install-key').required = !installed && !user.localSetup;
   $('#password').minLength = installed ? 1 : 12;
   $('#password').autocomplete = installed ? 'current-password' : 'new-password';
  }
 } catch (error) { status('#auth-status',error.message,true); }
}
$('#auth-form').addEventListener('submit', event => {
 event.preventDefault(); const form = event.currentTarget;
 busy(form, async () => { status('#auth-status'); try { await api(installed ? 'login' : 'setup',Object.fromEntries(new FormData(form))); form.reset(); await initialize(); } catch(error){status('#auth-status',error.message,true);} });
});
$('#logout').addEventListener('click', async () => { try { await api('logout',{}); currentTab = 'dashboard'; authenticated = false; $('#lead-dialog').close(); await initialize(); } catch(error){status('#global-status',error.message,true);} });
async function openTab(tab) {
 if (!titles[tab]) return;
 currentTab = tab; $('#page-title').textContent = titles[tab]; status('#global-status');
 document.querySelectorAll('[data-tab]').forEach(button => { if (button.dataset.tab === tab) button.setAttribute('aria-current','page'); else button.removeAttribute('aria-current'); });
 document.querySelectorAll('.tab-panel').forEach(panel => panel.hidden = panel.id !== tab+'-tab');
 try { if(tab==='dashboard') await loadDashboard(); if(tab==='leads') await loadLeads(); if(tab==='mail') await busy($('#mail-form'),async()=>renderMail(await api('mail'))); }
 catch(error){status('#global-status',error.message,true);}
}
document.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click',()=>openTab(button.dataset.tab)));
$('#refresh').addEventListener('click',()=>openTab(currentTab));
$('#period').addEventListener('change',()=>openTab('dashboard'));
function svgNode(tag, attributes, text) { const node = document.createElementNS('http://www.w3.org/2000/svg',tag); Object.entries(attributes).forEach(([key,value])=>node.setAttribute(key,String(value))); if(text!==undefined)node.textContent=text;return node; }
function renderChart(series) {
 chartSeries=series;
 const entries=Object.entries(series), width=Math.max(240,Math.min(1100,$('#traffic-chart').clientWidth||900)), height=width<600?190:240, left=34, right=15, top=14, bottom=34;
 const svg=svgNode('svg',{viewBox:`0 0 ${width} ${height}`,role:'presentation'});
 const max=Math.max(1,...entries.flatMap(([,v])=>[Number(v.visitors),Number(v.views)]));
 const x=i=>left+(width-left-right)*i/Math.max(1,entries.length-1), y=v=>height-bottom-(height-top-bottom)*v/max;
 for(const value of new Set([0,1,2,3,4].map(i=>Math.ceil(max*i/4)))){const pos=y(value);svg.append(svgNode('line',{x1:left,y1:pos,x2:width-right,y2:pos,class:'grid'}),svgNode('text',{x:left-8,y:pos+4,'text-anchor':'end'},String(value)));}
 for(const key of ['views','visitors']){const d=entries.map(([,v],i)=>(i?'L':'M')+x(i).toFixed(2)+','+y(Number(v[key])).toFixed(2)).join(' ');svg.append(svgNode('path',{d,class:key+'-line'}));}
 for(const i of [...new Set([0,Math.floor(entries.length/2),entries.length-1])]) if(entries[i]){const day=entries[i][0].slice(5).split('-').reverse().join('.');svg.append(svgNode('text',{x:x(i),y:height-8,'text-anchor':i===0?'start':i===entries.length-1?'end':'middle'},day));}
 $('#traffic-chart').replaceChildren(svg); $('#chart-empty').hidden=max>1||entries.some(([,v])=>Number(v.views)>0);
 $('#traffic-chart').setAttribute('aria-label',`Посещения за ${entries.length} дней: ${entries.reduce((s,[,v])=>s+Number(v.views),0)} просмотров. Подробные значения доступны в таблицах и счётчиках.`);
}
new ResizeObserver(()=>{if(chartSeries&&currentTab==='dashboard'&&$('#traffic-chart').clientWidth)renderChart(chartSeries);}).observe($('#traffic-chart'));
function breakdown(target,rows,device=false){const root=$(target);root.replaceChildren();if(!rows.length){root.append(el('p','empty-note','Пока нет данных за выбранный период.'));return;}const max=Math.max(1,...rows.map(row=>Number(row.value)));for(const row of rows){const item=el('div','breakdown-item'),line=el('div');const names={desktop:'Компьютеры',mobile:'Телефоны',tablet:'Планшеты'};line.append(el('span','',device?(names[row.label]||row.label):row.label),el('strong','',row.value));const meter=el('meter');meter.min=0;meter.max=max;meter.value=Number(row.value);meter.setAttribute('aria-label',(device?(names[row.label]||row.label):row.label)+': '+row.value);item.append(line,meter);root.append(item);}}
async function loadDashboard(){const revision=++dashboardRevision;const data=await api('dashboard',undefined,{days:$('#period').value});if(revision!==dashboardRevision)return;for(const key of ['visitors','views','leads'])$('#metric-'+key).textContent=Number(data[key]).toLocaleString('ru-RU');$('#metric-pending').textContent=Number(data.mail_pending).toLocaleString('ru-RU');$('#chart-caption').textContent='По дням · время Новосибирска';renderChart(data.series);breakdown('#sources-list',data.sources);breakdown('#devices-list',data.devices,true);}
function dateText(value){if(!value)return '—';return new Date(value.replace(' ','T')+'Z').toLocaleString('ru-RU',{timeZone:'Asia/Novosibirsk',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function badge(statusValue){return el('span','badge '+statusValue,labels[statusValue]||statusValue);}
async function loadLeads(){const data=await api('leads',undefined,{page:leadPage,status:$('#mail-filter').value});const body=$('#leads-body');body.replaceChildren();$('#leads-count').textContent='Всего заявок: '+data.total;$('#leads-empty').hidden=data.items.length>0;$('#leads-page').textContent=`${data.page} / ${data.pages}`;$('#leads-prev').disabled=data.page<=1;$('#leads-next').disabled=data.page>=data.pages;for(const item of data.items){const tr=el('tr'),date=el('td','',dateText(item.created_at)),client=el('td');const phone=el('a','client-phone',item.phone);phone.href='tel:'+item.phone.replace(/[^+0-9]/g,'');client.append(el('span','client-name',item.name),phone);const service=el('td','',item.service),mail=el('td'),process=el('td'),action=el('td'),button=el('button','row-open','Открыть');mail.append(badge(item.mail_status));process.append(badge(item.processing_status));button.addEventListener('click',()=>openLead(item.id));action.append(button);tr.append(date,client,service,mail,process,action);body.append(tr);}}
$('#mail-filter').addEventListener('change',()=>{leadPage=1;openTab('leads');});$('#leads-prev').addEventListener('click',()=>{leadPage=Math.max(1,leadPage-1);openTab('leads');});$('#leads-next').addEventListener('click',()=>{leadPage++;openTab('leads');});
async function openLead(id){try{const lead=await api('lead',undefined,{id});selectedLead=lead;const details=el('dl','lead-details');for(const [label,value]of [['Номер',lead.id.slice(0,8)],['Создана',dateText(lead.created_at)],['Клиент',lead.name],['Телефон',lead.phone],['Услуга',lead.service],['Письмо',labels[lead.mail_status]||lead.mail_status],['Попыток',lead.attempts],['Последняя попытка',dateText(lead.attempt_at)]])details.append(el('dt','',label),el('dd','',value));const message=el('p','lead-message',lead.message||'Описание не указано.');$('#lead-details').replaceChildren(details,message);$('#processing-status').value=lead.processing_status;$('#retry-mail').disabled=['sent','sending'].includes(lead.mail_status);status('#lead-status',lead.mail_status==='unknown'?'Доставка предыдущего письма неизвестна. Повтор может создать дубликат.':'');if(!$('#lead-dialog').open)$('#lead-dialog').showModal();}catch(error){status('#global-status',error.message,true);}}
$('#close-lead').addEventListener('click',()=>$('#lead-dialog').close());
$('#save-processing').addEventListener('click',async()=>{if(!selectedLead)return;const button=$('#save-processing');button.disabled=true;try{await api('lead-status',{id:selectedLead.id,status:$('#processing-status').value});status('#lead-status','Статус сохранён.');await loadLeads();}catch(error){status('#lead-status',error.message,true);}finally{button.disabled=false;}});
$('#retry-mail').addEventListener('click',async()=>{if(!selectedLead)return;if(selectedLead.mail_status==='unknown'&&!confirm('Предыдущая доставка неизвестна. Повторить отправку с риском дубликата?'))return;const button=$('#retry-mail');button.disabled=true;status('#lead-status','Отправляем письмо…');try{const result=await api('lead-retry',{id:selectedLead.id});await openLead(selectedLead.id);status('#lead-status',result.mail_status==='sent'?'SMTP-сервер принял письмо.':result.mail_status==='unconfigured'?'Сначала настройте и включите почту.':'Не удалось отправить письмо. Заявка сохранена.',result.mail_status!=='sent');await loadLeads();}catch(error){status('#lead-status',error.message,true);button.disabled=false;}});
function renderMail(settings){const form=$('#mail-form');for(const key of ['host','port','encryption','username','from_email','from_name','to_email'])form.elements.namedItem(key).value=settings[key];form.elements.enabled.checked=settings.enabled;form.elements.password.value='';form.elements.clear_password.checked=false;$('#password-hint').textContent=settings.password_saved?'Пароль сохранён. Оставьте поле пустым, чтобы не менять его.':'Пароль пока не сохранён.';}
async function saveMail(){const form=$('#mail-form'),data=Object.fromEntries(new FormData(form));data.enabled=form.elements.enabled.checked;data.clear_password=form.elements.clear_password.checked;data.port=Number(data.port);const result=await api('mail-save',data);renderMail(result.settings);return result;}
$('#mail-form').addEventListener('submit',event=>{event.preventDefault();busy(event.currentTarget,async()=>{status('#mail-status');try{await saveMail();status('#mail-status','Настройки сохранены.');}catch(error){status('#mail-status',error.message,true);}});});
$('#mail-test').addEventListener('click',()=>{if(!$('#mail-form').reportValidity())return;busy($('#mail-form'),async()=>{status('#mail-status','Сохраняем настройки и отправляем проверочное письмо…');try{await saveMail();const result=await api('mail-test',{});status('#mail-status',result.message);}catch(error){status('#mail-status',error.message,true);}});});
$('#password-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget,data=Object.fromEntries(new FormData(form));if(data.new_password!==data.confirm_password){status('#password-status','Новые пароли не совпадают.',true);return;}busy(form,async()=>{status('#password-status');try{await api('password',data);form.reset();status('#password-status','Пароль изменён. Другие сессии завершены.');}catch(error){status('#password-status',error.message,true);}});});
initialize();
