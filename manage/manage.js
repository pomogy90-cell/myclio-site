/* КЛИО · Управление салоном — общий модуль (админка + приложение).
   Эргономика журнала по ТЗ (DIKIDI Business): День/Месяц-матрица/Список, лента дат,
   липкие заголовки, масштаб сетки, нижняя навигация. Светлая фирменная тема КЛИО.
   Работает внутри <div id="klioManage">. Токен: data-token (админка) или localStorage (приложение). */
(function(){
'use strict';
var root = document.getElementById('klioManage');
if (!root) return;
var API = root.dataset.api || '/api/manage.php';
var CONTENT = root.dataset.content || '/api/content.json';
var WORK_START = 10, WORK_END = 21;

var S = {
  token: null, name: '', panel: false,
  version: 0, items: {}, meta: {specialists: []}, dirs: [],
  view: 'cal', calMode: 'day', date: todayStr(),
  fSpec: '', fDir: '', bal: {mode:'today', from:'', to:'', fSpec:'', fPay:'', group:'svc'},
  lastSeen: 0, timer: null, online: true, dirty: false,
  evSeq: 0, notifSeq: 0, leadsCount: 0,
  lst: {q:'', per:'upcoming', spec:'', dir:'', st:'', sort:'date'},
  ui: {step:15, hourH:64, hideSpecs:[], compact:false, groupBy:'dir'},
  cash: {opening_set:false, opening:0, in:0, out:0, balance:0, payouts:[]},
  cashStamp: '', cashFlt: 'all'
};
try {
  var uis = JSON.parse(localStorage.getItem('klio_manage_ui') || 'null');
  if (uis) { for (var k in S.ui) if (uis[k] !== undefined) S.ui[k] = uis[k]; }
} catch(e){}
try { S.notifSeq = +(localStorage.getItem('klio_manage_nfseq') || 0); } catch(e){}
function saveUi(){ try { localStorage.setItem('klio_manage_ui', JSON.stringify(S.ui)); } catch(e){} }
function hh(){ return +S.ui.hourH || 64; }

/* источник действий: приложение (APK заходит с ?src=app), админка или браузер */
function appSrc(){
  if (/[?&]src=app\b/.test(location.search)) return 'app';
  if (location.pathname.indexOf('/admin') === 0) return 'panel';
  return 'web';
}

function todayStr(){ var d = new Date(); return d.getFullYear() + '-' + p2(d.getMonth()+1) + '-' + p2(d.getDate()); }
function p2(n){ return (n<10?'0':'') + n; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function rub(n){ return (n==null?'0':String(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽'; }
function dRus(d){ var p = d.split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
function dRusT(d){ /* 26 ноября */ var p = d.split('-'); return (+p[2]) + ' ' + ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'][+p[1]-1]; }
function addDays(d, n){ var t = new Date(d + 'T12:00:00'); t.setDate(t.getDate()+n); return t.getFullYear() + '-' + p2(t.getMonth()+1) + '-' + p2(t.getDate()); }
function weekDay(d){ return ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][new Date(d + 'T12:00:00').getDay()]; }
function monthName(d){ return ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'][+d.split('-')[1]-1]; }
function t2m(t){ var p = t.split(':'); return (+p[0])*60 + (+p[1]); }
function m2t(m){ return p2(Math.floor(m/60)) + ':' + p2(m%60); }

/* отпечаток состояния кассы — чтобы перерисовывать блок только при реальном изменении */
function cashStamp(c){ return JSON.stringify([c.opening_set, c.opening, c.in, c.in_full, c.rewards, c.dep, c.out, c.balance, (c.history || []).length]); }
/* статьи расходов категории «Затраты» (зеркало списка в api/manage.php) */
var CASH_ARTS = ['Расходы на салон «Клио»', 'Офисные расходы', 'Представительские расходы',
  'Оплата сервисов и программного обеспечения', 'Оплата сторонних услуг',
  'Оплата связи и коммуникаций', 'Прочие расходы'];
/* иллюстрация кассового аппарата — фирменная линейная графика (бордо + золото) */
var CASH_SVG = '<svg viewBox="0 0 140 112" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">' +
  '<path d="M88 8h20v26l-3-2.4-3 2.4-3-2.4-3 2.4-3-2.4-3 2.4z" fill="#fffdfa"/>' +
  '<path d="M92 15h12M92 20h12M92 25h8" stroke="#d4af37"/>' +
  '<rect x="26" y="10" width="56" height="34" rx="5" fill="#fffdfa"/>' +
  '<rect x="32" y="16" width="44" height="22" rx="3" fill="#f3e7c8" stroke="#d4af37"/>' +
  '<text x="54" y="33" text-anchor="middle" font-size="16" font-weight="700" fill="#7d2a35" stroke="none" font-family="Marcellus,serif">₽</text>' +
  '<path d="M54 44v8"/>' +
  '<path d="M18 52h104a6 6 0 0 1 6 6v12H12V58a6 6 0 0 1 6-6z" fill="#fffdfa"/>' +
  '<path d="M22 59h9M35 59h9M22 65h9M35 65h9" stroke="#c9b98f"/>' +
  '<rect x="88" y="54" width="30" height="12" rx="2.5" fill="#f8f3e9"/>' +
  '<path d="M12 70h116v22a6 6 0 0 1-6 6H18a6 6 0 0 1-6-6z" fill="#f8f3e9"/>' +
  '<rect x="58" y="78" width="24" height="8" rx="4" fill="#fffdfa"/>' +
  '<circle cx="30" cy="84" r="6" fill="#f3e7c8" stroke="#d4af37"/>' +
  '<circle cx="42" cy="88.5" r="6" fill="#f3e7c8" stroke="#d4af37"/>' +
'</svg>';
var ST_RU = {planned:'Запланирована', service:'Услуга оказана · ждём расчёт', paid:'Оплата внесена · ждём расчёт со специалистом',
             done:'Полностью завершена', canceled:'Отменена', noshow:'Клиент не пришёл'};
var ST_SHORT = {planned:'Запланирована', service:'В работе', paid:'Оплачена', done:'Выполнено', canceled:'Отменена', noshow:'Не пришла'};
var PAY_RU = {cash:'Наличные', qr:'QR-код', sbp:'СБП', term:'Банковский терминал'};

/* тинты направлений: приглушённые розовый / оливковый / золотой / лиловый */
var TINTS = ['rose','olive','gold','lilac'];
function dirTint(dir){
  var idx = -1;
  for (var i = 0; i < S.dirs.length; i++) if (S.dirs[i].cat === dir) { idx = i; break; }
  if (idx < 0) { /* неизвестное направление — стабильный оттенок по имени */
    idx = 0; var s = String(dir || '');
    for (var j = 0; j < s.length; j++) idx = (idx * 31 + s.charCodeAt(j)) >>> 0;
  }
  return TINTS[idx % TINTS.length];
}
var SPEC_CL = ['#a95d6d','#8f9457','#c9a24b','#9580a8','#7d8ca8','#c27a3a'];
function specColor(s){
  var i = S.meta.specialists.indexOf(s);
  return SPEC_CL[(i < 0 ? 0 : i) % SPEC_CL.length];
}
function visSpecs(){
  var hide = S.ui.hideSpecs || [];
  return S.meta.specialists.filter(function(s){ return hide.indexOf(s) < 0; });
}

/* ---------- API ---------- */
function api(params, body){
  var opt = {headers: {'X-Token': S.token || ''}};
  var url = API;
  if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  else if (params) url += (url.indexOf('?')<0?'?':'&') + params;
  return fetch(url, opt).then(function(r){
    setOnline(true);
    return r.json().then(function(j){
      if (r.status === 401) { doLogout(true); throw {auth:true}; }
      if (!j.ok) throw {msg: j.err || 'Ошибка сервера'};
      return j;
    });
  }).catch(function(e){
    if (e && (e.auth || e.msg)) throw e;
    setOnline(false);
    throw {msg:'Нет соединения с сервером'};
  });
}
function setOnline(on){
  if (S.online === on) return;
  S.online = on;
  var b = document.getElementById('mngOff');
  if (b) b.style.display = on ? 'none' : 'block';
}

/* ---------- тосты ---------- */
function toast(html){
  var w = document.getElementById('mngToasts');
  if (!w) { w = document.createElement('div'); w.id = 'mngToasts'; w.className = 'mng-toasts'; root.appendChild(w); }
  var t = document.createElement('div'); t.className = 'mng-toast'; t.innerHTML = html;
  w.appendChild(t);
  setTimeout(function(){ t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 5200);
  setTimeout(function(){ t.remove(); }, 5700);
}

/* ---------- своё окно подтверждения ----------
   В WebView штатного приложения нативный confirm() не отображается (нет onJsConfirm),
   поэтому «Удалить»/«Отменить»/«Не пришёл» молча не срабатывали. Своя модалка. */
function askYes(title, text, yesLabel, onYes){
  var old = document.getElementById('mngAsk'); if (old) old.remove();
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngAsk';
  ovl.style.zIndex = '90'; /* поверх карточки записи (50) и шторок (55) */
  ovl.innerHTML = '<div class="mng-card" style="padding:20px 20px calc(20px + env(safe-area-inset-bottom,0px));max-width:430px">' +
    '<h2 style="margin:0 0 8px">' + esc(title) + '</h2>' +
    '<div class="who" style="white-space:pre-line;line-height:1.45">' + esc(text) + '</div>' +
    '<div style="display:flex;gap:10px;margin-top:20px">' +
      '<button class="mng-btn" id="mngAskNo" style="flex:1">Назад</button>' +
      '<button class="mng-btn gold" id="mngAskYes" style="flex:1.6">' + esc(yesLabel) + '</button>' +
    '</div></div>';
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  root.appendChild(ovl);
  document.getElementById('mngAskNo').onclick = function(){ ovl.remove(); };
  document.getElementById('mngAskYes').onclick = function(){ ovl.remove(); onYes(); };
}

/* своё окно ввода текста — замена prompt() (в WebView приложения prompt() не работает) */
function askInput(title, placeholder, onOk){
  var old = document.getElementById('mngAsk'); if (old) old.remove();
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngAsk';
  ovl.style.zIndex = '90';
  ovl.innerHTML = '<div class="mng-card" style="padding:20px 20px calc(20px + env(safe-area-inset-bottom,0px));max-width:430px">' +
    '<h2 style="margin:0 0 12px">' + esc(title) + '</h2>' +
    '<input id="mngAskInp" class="mng-inp" type="text" maxlength="120" placeholder="' + esc(placeholder || '') + '" autocomplete="off">' +
    '<div style="display:flex;gap:10px;margin-top:16px">' +
      '<button class="mng-btn" id="mngAskNo" style="flex:1">Назад</button>' +
      '<button class="mng-btn gold" id="mngAskYes" style="flex:1.6">Готово</button>' +
    '</div></div>';
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  root.appendChild(ovl);
  var inp = document.getElementById('mngAskInp');
  document.getElementById('mngAskNo').onclick = function(){ ovl.remove(); };
  document.getElementById('mngAskYes').onclick = function(){ var v = inp.value.trim(); ovl.remove(); onOk(v); };
  inp.onkeydown = function(e){ if (e.key === 'Enter') { e.preventDefault(); document.getElementById('mngAskYes').click(); } };
  setTimeout(function(){ inp.focus(); }, 60);
}

/* ---------- авторизация ---------- */
function renderLogin(err){
  root.innerHTML =
    '<div class="mng-login"><div class="logo">КЛИО</div><div class="sub">Управление салоном · вход для команды</div>' +
    (err ? '<div class="mng-err">' + esc(err) + '</div>' : '') +
    '<label class="mng-lbl">Код доступа (пароль админ-панели)</label>' +
    '<input class="mng-inp" type="password" id="mngCode" autocomplete="off" placeholder="Код доступа">' +
    '<label class="mng-lbl">Ваше имя (видно в истории действий)</label>' +
    '<input class="mng-inp" type="text" id="mngName" maxlength="40" placeholder="Например: Мария">' +
    '<button class="mng-btn gold" id="mngGo" style="width:100%;margin-top:20px;padding:13px">Войти</button></div>';
  var go = function(){
    var code = document.getElementById('mngCode').value.trim();
    var name = document.getElementById('mngName').value.trim();
    if (!code || !name) { renderLogin('Введите код доступа и имя.'); return; }
    api(null, {action:'login', code:code, name:name, src:appSrc()}).then(function(j){
      S.token = j.token; S.name = j.name;
      try { localStorage.setItem('klio_manage', JSON.stringify({token:S.token, name:S.name})); } catch(e){}
      boot();
    }).catch(function(e){ renderLogin(e.msg || 'Ошибка входа'); });
  };
  document.getElementById('mngGo').onclick = go;
  document.getElementById('mngName').onkeydown = function(e){ if (e.key === 'Enter') go(); };
}
function askName(){
  /* админка: имя спрашиваем один раз, привязываем к токену */
  var nm = '';
  try { nm = localStorage.getItem('klio_manage_name') || ''; } catch(e){}
  if (nm) { S.name = nm; api(null, {action:'bind', name:nm}).then(boot).catch(function(){ boot(); }); return; }
  root.innerHTML =
    '<div class="mng-login"><div class="logo">КЛИО</div><div class="sub">Как вас подписывать в истории действий?</div>' +
    '<label class="mng-lbl">Ваше имя</label>' +
    '<input class="mng-inp" type="text" id="mngName2" maxlength="40" placeholder="Например: Мария">' +
    '<button class="mng-btn gold" id="mngGo2" style="width:100%;margin-top:20px;padding:13px">Сохранить и войти</button></div>';
  document.getElementById('mngGo2').onclick = function(){
    var name = document.getElementById('mngName2').value.trim();
    if (!name) return;
    S.name = name;
    try { localStorage.setItem('klio_manage_name', name); } catch(e){}
    api(null, {action:'bind', name:name}).then(boot).catch(function(){ boot(); });
  };
}
function doLogout(silent){
  if (!silent && S.token) api(null, {action:'logout'}).catch(function(){});
  S.token = null;
  try { localStorage.removeItem('klio_manage'); } catch(e){}
  clearInterval(S.timer);
  if (S.panel) { askName(); } else renderLogin();
}

/* ---------- загрузка и синхронизация ---------- */
function pushBridge(){
  /* штатное Android-приложение: передаём токен нативному сервису уведомлений */
  try { if (window.KlioPush && window.KlioPush.setToken && S.token) window.KlioPush.setToken(S.token, S.name || ''); } catch(e){}
}
function handleDeepLink(){
  /* открытие из push-уведомления: ?open=b12 | lead:klio-... | hist */
  try {
    var q = new URLSearchParams(location.search);
    var open = q.get('open');
    if (!open) return;
    if (open === 'hist') { S.view = 'hist'; renderShell(); return; }
    if (open.indexOf('lead:') === 0) {
      var lid = open.slice(5);
      api('action=leads').then(function(j){
        var l = (j.leads || []).filter(function(x){ return x.id === lid; })[0];
        if (l) { S.view = 'leads'; renderShell(); setTimeout(function(){ openLeadCard(l); }, 350); }
        else { S.view = 'hist'; S.hf.oid = lid; renderShell(); } /* заявка уже обработана/удалена — покажем её историю */
      }).catch(function(){});
      return;
    }
    if (S.items[open]) { openCard(open); }
    else { S.view = 'hist'; S.hf.oid = open; renderShell(); } /* запись удалена — покажем цепочку в истории */
  } catch(e){}
}
function boot(){
  root.innerHTML = '<div class="mng-empty">Загружаем данные…</div>';
  pushBridge();
  var pContent = fetch(CONTENT).then(function(r){ return r.json(); }).catch(function(){ return {prices:[]}; });
  pContent.then(function(c){
    S.dirs = (c.prices || []).map(function(x){ var pr = {}; (x.rows||[]).forEach(function(r){ var p = parseInt(String(r[1]||'').replace(/[^\d]/g,''),10); pr[String(r[0]).trim()] = isNaN(p) ? null : p; }); return {cat:x.cat, rows:(x.rows||[]).map(function(r){ return String(r[0]).trim(); }), price:pr}; });
    if(!S.dirs.some(function(d){return d.cat===SOL_DIR;})) S.dirs.push({cat:SOL_DIR,rows:SOL_SERVICES.slice(),price:{}});
    return api('action=state&since=0');
  }).then(function(j){
    S.version = j.version;
    S.items = {};
    (j.items || []).forEach(function(it){ S.items[it.id] = it; });
    if (j.meta) S.meta = j.meta;
    if (j.cash) { S.cash = j.cash; S.cashStamp = cashStamp(j.cash); }
    S.lastSeen = Math.floor(Date.now()/1000);
    try { S.lastSeen = +(localStorage.getItem('klio_manage_seen') || S.lastSeen); } catch(e){}
    return api('action=events&since=2147483647').then(function(e2){
      S.evSeq = (e2 && typeof e2.seq === 'number') ? e2.seq : 0;
      if (!S.notifSeq) { S.notifSeq = S.evSeq; saveNfSeq(); } /* первый вход — без бейджа */
    }).catch(function(){ S.evSeq = 0; });
  }).then(function(){
    renderShell();
    /* новости с прошлого визита */
    var news = [];
    Object.keys(S.items).forEach(function(id){
      var it = S.items[id];
      (it.history || []).forEach(function(h){
        if (h.t > S.lastSeen && h.u !== S.name) news.push({t:h.t, u:h.u, m:h.m, s:it.service});
      });
    });
    news.sort(function(a,b){ return a.t - b.t; });
    news.slice(-4).forEach(function(n){ toast('<b>' + esc(n.u) + '</b> · ' + esc(n.m) + '<br><small>' + esc(n.s) + '</small>'); });
    markSeen();
    startPoll();
    setTimeout(handleDeepLink, 300);
  }).catch(function(e){
    root.innerHTML = '<div class="mng-empty">' + esc(e.msg || 'Не удалось загрузить данные. Проверьте интернет и обновите страницу.') + '</div>';
  });
}
function markSeen(){
  S.lastSeen = Math.floor(Date.now()/1000);
  try { localStorage.setItem('klio_manage_seen', S.lastSeen); } catch(e){}
}
function saveNfSeq(){ try { localStorage.setItem('klio_manage_nfseq', S.notifSeq); } catch(e){} }
function startPoll(){
  clearInterval(S.timer);
  S.timer = setInterval(poll, 1100);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
}
function poll(){
  if (document.hidden || !S.token) return;
  api('action=state&since=' + S.version).then(function(j){
    if (j.meta) S.meta = j.meta;
    if (j.cash) { /* касса: обновление у всех пользователей, даже если записи не менялись */
      var cs = cashStamp(j.cash);
      if (cs !== S.cashStamp) {
        S.cashStamp = cs; S.cash = j.cash;
        if (S.view === 'bal') renderBal();
      }
    }
    var changed = j.items || [];
    if (changed.length || j.version !== S.version) {
      S.version = j.version;
      var mine = false, foreign = [];
      changed.forEach(function(it){
        if (it.deleted) { delete S.items[it.id]; return; }
        var last = (it.history || []).slice(-1)[0];
        if (last && last.u === S.name) mine = true;
        else if (last) foreign.push({u:last.u, m:last.m, s:it.service});
        S.items[it.id] = it;
      });
      foreign.slice(-3).forEach(function(n){ toast('<b>' + esc(n.u) + '</b> · ' + esc(n.m) + '<br><small>' + esc(n.s) + '</small>'); });
      markSeen();
      refreshViews();
      updateBadges();
      var modal = document.getElementById('mngCardModal');
      if (modal && !S.dirty && modal.dataset.bid && S.items[modal.dataset.bid]) openCard(modal.dataset.bid, true);
    }
  }).catch(function(){});
  /* лента событий: бейджи колокольчика и заявок */
  api('action=events&since=' + (S.evSeq || 0)).then(function(j){
    if (typeof j.seq === 'number' && j.seq > (S.evSeq || 0)) {
      S.evSeq = j.seq;
      updateBadges();
    }
    (j.items || []).forEach(function(ev){
      if (ev.type === 'cash_payout' || ev.type === 'cash_opening') { /* операции кассы других пользователей */
        if (ev.user !== S.name) toast((ev.type === 'cash_payout' ? '💸' : '🏦') + ' <b>' + esc(ev.title || 'Касса') + '</b><br><small>' + esc(ev.info || '') + (ev.user ? ' · ' + esc(ev.user) : '') + '</small>');
        return;
      }
      if (ev.type !== 'lead_new') return;
      toast('🔔 <b>Новая заявка</b><br><small>' + esc(ev.info || '') + '</small>');
      S.leadsCount = (S.leadsCount || 0) + 1;
      updateBadges();
      if (S.view === 'leads') renderLeads();
    });
  }).catch(function(){});
}

/* ---------- иконки (SVG, фирменная линия) ---------- */
var IC = {
  back:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>',
  menu:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
  cal:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg>',
  inbox:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13l2.7-7.2A1.6 1.6 0 0 1 8.2 4.8h7.6a1.6 1.6 0 0 1 1.5 1L20 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5z"/><path d="M4 13h4.5l1 2.2h5l1-2.2H20"/></svg>',
  bal:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M12 4v16M8.5 7.5h5.2a2.6 2.6 0 0 1 0 5.2H9.8a2.6 2.6 0 0 0 0 5.2h6"/><circle cx="12" cy="12" r="9.2"/></svg>',
  hist:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="8.6"/><path d="M12 7.5V12l3 2"/></svg>',
  bell:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 2 6H4c.5-.5 2-1.5 2-6z"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/></svg>',
  search:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.2"/><path d="M15.4 15.4L20 20"/></svg>',
  plus:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
  gear:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.1"/><path d="M12 2.8l1 2.4 2.6-.6 1.1 2.3 2.6.4-.2 2.6 2 1.7-2 1.7.2 2.6-2.6.4-1.1 2.3-2.6-.6-1 2.4-1-2.4-2.6.6-1.1-2.3-2.6-.4.2-2.6-2-1.7 2-1.7-.2-2.6 2.6-.4 1.1-2.3 2.6.6z" stroke-width="0"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1.11-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.05A1.7 1.7 0 0 0 4.6 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34h.09A1.7 1.7 0 0 0 10 3V3a2 2 0 1 1 4 0v.09c0 .68.4 1.3 1 1.55.61.26 1.32.1 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87v.09c.25.6.87 1 1.55 1H21a2 2 0 1 1 0 4h-.09c-.68 0-1.3.4-1.51 1z"/></svg>',
  chevL:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5.5L8 12l6.5 6.5"/></svg>',
  chevR:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 5.5L16 12l-6.5 6.5"/></svg>',
  calPick:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/><circle cx="12" cy="15" r="2.2" fill="currentColor" stroke="none"/></svg>',
  users:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="9" cy="8.5" r="3.5"/><path d="M3.5 19.5c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M16 5.5a3.2 3.2 0 1 1 0 6.3M17.5 14.8c1.9.5 3.2 2 3.6 4.2"/></svg>',
  cash:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="7" rx="1.8"/><path d="M7 8h4M3 11.5h18V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="16" r="2.1"/><path d="M17 15.5h.01M7 15.5h.01"/></svg>',
  out:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h-8a1.5 1.5 0 0 0-1.5 1.5v13A1.5 1.5 0 0 0 6 20h8M10 12h11M17.5 8.5L21 12l-3.5 3.5"/></svg>',
  spec:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5.5 20c.7-3.6 3.3-5.6 6.5-5.6s5.8 2 6.5 5.6"/></svg>'
};

/* ---------- каркас ---------- */
function unfinishedList(){
  var now = Math.floor(Date.now()/1000), out = [];
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted) return;
    if (it.unfinished && it.unfinished.length) out.push(it);
  });
  out.sort(function(a,b){ return (a.date + a.start) < (b.date + b.start) ? -1 : 1; });
  return out;
}
function viewTitle(){
  return {cal:'Журнал', leads:'Заявки', bal:'Баланс', hist:'История действий', spec:'Специалисты', notif:'Уведомления'}[S.view] || 'Журнал';
}
function updateBadges(){
  /* нижняя навигация: заявки + незавершённые */
  var map = {cal: unfinishedList().length, leads: S.leadsCount || 0};
  Array.prototype.forEach.call(root.querySelectorAll('.mng-bni'), function(b){
    var v = b.dataset.v, n = map[v] || 0;
    var bdg = b.querySelector('.bdg');
    if (n) { if (bdg) bdg.textContent = n; else b.insertAdjacentHTML('beforeend', '<span class="bdg">' + n + '</span>'); }
    else if (bdg) bdg.remove();
  });
  /* колокольчик: непрочитанные события */
  var bell = document.getElementById('mngBell');
  if (bell) {
    var n2 = Math.max(0, Math.min(99, (S.evSeq || 0) - (S.notifSeq || 0)));
    var b2 = bell.querySelector('.bdg');
    if (n2) { if (b2) b2.textContent = n2; else bell.insertAdjacentHTML('beforeend', '<span class="bdg">' + n2 + '</span>'); }
    else if (b2) b2.remove();
  }
}
function renderShell(){
  root.classList.remove('hasovl');
  root.innerHTML =
    '<div class="mng-off" id="mngOff">Нет соединения — изменения не сохраняются. Проверьте интернет.</div>' +
    '<div class="mng-topbar">' +
      (appSrc()==='app' ? '<button class="mng-ic" id="mngHome" title="Вернуться в приложение">' + IC.back + '</button>' : '') +
      '<button class="mng-ic" id="mngMenu" title="Меню">' + IC.menu + '</button>' +
      '<img class="mng-logo" src="/img/klio-logo-200.jpg" alt="КЛИО">' +
      '<div class="mng-brand"><b>КЛИО</b><small>САЛОН КРАСОТЫ</small></div>' +
      '<div class="mng-tbtitle">' + esc(viewTitle()) + '</div>' +
      '<span class="sp"></span>' +
      '<button class="mng-ic" id="mngSearch" title="Поиск записи">' + IC.search + '</button>' +
      '<button class="mng-ic mng-bell" id="mngBell" title="Уведомления">' + IC.bell + '</button>' +
      '<button class="mng-plus" id="mngNew" title="Новая запись">' + IC.plus + '</button>' +
    '</div>' +
    '<div class="mng-body" id="mngBody"></div>' +
    '<nav class="mng-bnav">' +
      '<button class="mng-bni' + (S.view==='cal'?' on':'') + '" data-v="cal">' + IC.cal + '<span>Журнал</span></button>' +
      '<button class="mng-bni' + (S.view==='leads'?' on':'') + '" data-v="leads">' + IC.inbox + '<span>Заявки</span></button>' +
      '<button class="mng-bni' + (S.view==='bal'?' on':'') + '" data-v="bal">' + IC.bal + '<span>Баланс</span></button>' +
      '<button class="mng-bni' + (S.view==='hist'?' on':'') + '" data-v="hist">' + IC.hist + '<span>История</span></button>' +
    '</nav>';
  Array.prototype.forEach.call(root.querySelectorAll('.mng-bni'), function(b){
    b.onclick = function(){ S.view = b.dataset.v; renderShell(); };
  });
  document.getElementById('mngMenu').onclick = menuSheet;
  var mngHomeBtn = document.getElementById('mngHome');
  if (mngHomeBtn) mngHomeBtn.onclick = function(){
    if (window.KlioPush && typeof window.KlioPush.goHome === 'function') { KlioPush.goHome(); return; }
    if (history.length > 1) { history.back(); return; }
    location.href = 'index.html';
  };
  document.getElementById('mngSearch').onclick = function(){
    S.view = 'cal'; S.calMode = 'list'; renderShell();
    setTimeout(function(){ var q = document.getElementById('lstQ'); if (q) q.focus(); }, 120);
  };
  document.getElementById('mngBell').onclick = function(){
    S.view = 'notif'; S.notifSeq = S.evSeq || 0; saveNfSeq(); renderShell();
  };
  document.getElementById('mngNew').onclick = function(){
    openCard(null, false, {date: S.calMode === 'day' ? S.date : todayStr(), spec: S.fSpec || ''});
  };
  if (S.view === 'cal') renderCal();
  else if (S.view === 'bal') renderBal();
  else if (S.view === 'leads') renderLeads();
  else if (S.view === 'hist') renderHist();
  else if (S.view === 'notif') renderNotif();
  else renderSpecs();
  updateBadges();
  if (S.view === 'cal' && !S.unfShown) { S.unfShown = true; showUnfinished(); }
}
function refreshViews(){
  if (S.view === 'cal') renderCal(true);
  else if (S.view === 'bal') renderBal(true);
}
function menuSheet(){
  var old = document.getElementById('mngSheet'); if (old) old.remove();
  var sh = document.createElement('div');
  sh.className = 'mng-sheet'; sh.id = 'mngSheet';
  sh.innerHTML = '<div class="mng-sheet-c">' +
    '<h3>Меню</h3>' +
    '<div style="color:var(--mut);font-size:13px;margin-bottom:6px">Вы вошли как <b style="color:var(--wine)">' + esc(S.name || '—') + '</b> · все действия подписываются этим именем</div>' +
    '<button class="mng-menurow" id="mshSpec">' + IC.users + 'Специалисты салона</button>' +
    '<button class="mng-menurow" id="mshNf">' + IC.bell + 'Уведомления</button>' +
    '<button class="mng-menurow" id="mshHist">' + IC.hist + 'История действий</button>' +
    '<button class="mng-menurow danger" id="mshOut">' + IC.out + 'Выйти из модуля</button>' +
  '</div>';
  root.appendChild(sh);
  root.classList.add('hasovl');
  sh.onclick = function(e){ if (e.target === sh) sh.remove(); };
  document.getElementById('mshSpec').onclick = function(){ sh.remove(); S.view = 'spec'; renderShell(); };
  document.getElementById('mshNf').onclick = function(){ sh.remove(); S.view = 'notif'; S.notifSeq = S.evSeq || 0; saveNfSeq(); renderShell(); };
  document.getElementById('mshHist').onclick = function(){ sh.remove(); S.view = 'hist'; renderShell(); };
  document.getElementById('mshOut').onclick = function(){ sh.remove(); doLogout(); };
}
/* нижняя навигация прячется, когда открыта шторка/карточка */
new MutationObserver(function(){
  root.classList.toggle('hasovl', !!root.querySelector('.mng-ovl,.mng-sheet,.mng-pick'));
}).observe(root, {childList: true});

/* ================= ЖУРНАЛ: панели ================= */
function itemsOfDay(d){
  var out = [];
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted || it.date !== d) return;
    if (S.fSpec === '__nospec__') { if (it.spec && specInList(it.spec)) return; }
    else if (S.fSpec && it.spec !== S.fSpec) return;
    if (S.fDir === '__other__') { if (dirInList(it.dir)) return; }
    else if (S.fDir && it.dir !== S.fDir) return;
    out.push(it);
  });
  out.sort(function(a,b){ return a.start < b.start ? -1 : 1; });
  return out;
}
function calTools(){
  /* переключатель режимов + настройки */
  var h = '<div class="mng-ctools"><div class="mng-segrow"><div class="mng-seg">' +
    '<button data-m="day"' + (S.calMode==='day'?' class="on"':'') + '>День</button>' +
    '<button data-m="month"' + (S.calMode==='month'?' class="on"':'') + '>Месяц</button>' +
    '<button data-m="list"' + (S.calMode==='list'?' class="on"':'') + '>Список</button>' +
    '</div><button class="mng-ic" id="calSet" title="Настройки журнала">' + IC.gear + '</button></div>';
  /* навигация по датам */
  if (S.calMode === 'month') {
    h += '<div class="mng-datenav"><button class="mng-navb" id="calPrev">' + IC.chevL + '</button>' +
      '<div class="mng-tv tt" id="calPick" style="cursor:pointer">' + esc(monthName(S.date)) + ' ' + S.date.split('-')[0] + ' <small>▾</small></div>' +
      '<button class="mng-today" id="calToday">Сегодня</button>' +
      '<button class="mng-navb" id="calNext">' + IC.chevR + '</button></div>';
  } else if (S.calMode === 'day') {
    h += '<div class="mng-datenav"><button class="mng-navb" id="calPrev">' + IC.chevL + '</button>' +
      '<div class="mng-tt tt" id="calPick">' + esc(weekDay(S.date)) + ', ' + esc(dRusT(S.date)) + ' <small>▾</small></div>' +
      '<button class="mng-today" id="calToday">Сегодня</button>' +
      '<button class="mng-navb" id="calNext">' + IC.chevR + '</button></div>' +
      dateStrip();
  }
  h += '</div>';
  return h;
}
function dateStrip(){
  /* горизонтальная лента дат: число + день недели, выбранная — бордо, сегодня — золотая точка */
  var t = todayStr();
  var h = '<div class="mng-dstrip" id="mngDStrip">';
  for (var i = -10; i <= 21; i++) {
    var d = addDays(S.date, i);
    var p = d.split('-');
    var wd = new Date(d + 'T12:00:00').getDay();
    var mo = (p[2] === '01' || i === -10) ? '<span class="mo">' + ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'][+p[1]-1] + '</span>' : '';
    h += '<div class="mng-dc' + (d === S.date ? ' on' : '') + (d === t ? ' today' : '') + ((wd === 0 || wd === 6) ? ' wknd' : '') + '" data-d="' + d + '">' +
      mo + '<span class="d">' + (+p[2]) + '</span><span class="w">' + weekDay(d) + '</span></div>';
  }
  h += '</div>';
  return h;
}
/* направления (категории услуг) из прайса */
function allDirs(){ return S.dirs.map(function(d){ return d.cat; }); }
function masterProfiles(){
  if(Array.isArray(S.meta.master_profiles)) return S.meta.master_profiles;
  if(Array.isArray(S.meta.master_profiles_view)) return S.meta.master_profiles_view;
  return S.meta.specialists.map(function(s){return {name:s,id:'',active:true,configured:false,services:[]};});
}
function masterAllows(p,dir,names){
  if(!p.active)return false;
  if(!p.configured)return !Array.isArray(S.meta.master_profiles);
  return names.length ? names.every(function(n){return p.services.some(function(x){return x.dir===dir&&x.name===n;});}) : p.services.some(function(x){return x.dir===dir;});
}
function svcLine(it){ return (it.services && it.services.length) ? it.services.join(' + ') : (it.service || ''); }
/* ---- Солярий: отдельный режим ---- */
var SOL_DIR = 'Солярий';
var SOL_SERVICES = ['Солярий синий', 'Солярий оранжевый', 'Крем'];
var SOL_SPECS = ['Солярий синий', 'Солярий оранжевый'];
function solDirObj(){ return S.dirs.filter(function(x){ return x.cat === SOL_DIR; })[0]; }
function solMinPrice(){ var d = solDirObj(); var p = d && d.price ? d.price['Солярий 1 минута'] : null; return p || 50; }
function solCreamPrice(){ var d = solDirObj(); var p = d && d.price ? (d.price['Крем для солярия'] != null ? d.price['Крем для солярия'] : d.price['Крем']) : null; return p || 200; }
function svcPrice(dirCat, name, durMin){
  if (dirCat === SOL_DIR) {
    if (name === 'Крем') return solCreamPrice();
    if (SOL_SPECS.indexOf(name) >= 0) return solMinPrice() * (durMin || 10);
    return null;
  }
  var d = S.dirs.filter(function(x){ return x.cat === dirCat; })[0];
  return d && d.price ? d.price[name] : null;
}
function dirInList(dir){ return allDirs().indexOf(dir) >= 0; }
function specInList(sp){ return !!sp && S.meta.specialists.indexOf(sp) >= 0; }
var TINT_HEX = {rose:'#a95d6d', olive:'#8f9457', gold:'#c9a24b', lilac:'#9580a8'};
function dirColor(dir){ return TINT_HEX[dirTint(dir)] || '#a95d6d'; }
/* переключатель «по услугам / по мастерам» + чипсы выбранной группы */
function groupChips(){
  var byDir = (S.ui.groupBy !== 'spec') && S.dirs.length;
  var h = '<div class="mng-grow"><div class="mng-seg mng-gseg">' +
    '<button data-g="dir"' + (byDir ? ' class="on"' : '') + '>По услугам</button>' +
    '<button data-g="spec"' + (!byDir ? ' class="on"' : '') + '>По мастерам</button>' +
    '</div></div>';
  if (byDir) {
    var hasOther = false;
    Object.keys(S.items).forEach(function(id){
      var it = S.items[id];
      if (!it.deleted && !dirInList(it.dir)) hasOther = true;
    });
    h += '<div class="mng-chipsrow">' +
      '<button class="mng-schip' + (!S.fDir ? ' on' : '') + '" data-dr="">Все</button>' +
      allDirs().map(function(d){
        return '<button class="mng-schip' + (S.fDir === d ? ' on' : '') + '" data-dr="' + esc(d) + '"><span class="dot" style="background:' + dirColor(d) + '"></span>' + esc(d) + '</button>';
      }).join('') +
      (hasOther ? '<button class="mng-schip' + (S.fDir === '__other__' ? ' on' : '') + '" data-dr="__other__"><span class="dot" style="background:#9a8f7d"></span>Прочее</button>' : '') +
      '</div>';
  } else {
    var hasNoSpec = false;
    Object.keys(S.items).forEach(function(id){
      var it = S.items[id];
      if (!it.deleted && !specInList(it.spec)) hasNoSpec = true;
    });
    h += '<div class="mng-chipsrow">' +
      '<button class="mng-schip' + (!S.fSpec ? ' on' : '') + '" data-sp="">Все</button>' +
      visSpecs().map(function(s){
        return '<button class="mng-schip' + (S.fSpec === s ? ' on' : '') + '" data-sp="' + esc(s) + '"><span class="dot" style="background:' + specColor(s) + '"></span>' + esc(s) + '</button>';
      }).join('') +
      (hasNoSpec ? '<button class="mng-schip' + (S.fSpec === '__nospec__' ? ' on' : '') + '" data-sp="__nospec__"><span class="dot" style="background:#9a8f7d"></span>Без мастера</button>' : '') +
      '</div>';
  }
  return h;
}
function bindCalTools(){
  Array.prototype.forEach.call(root.querySelectorAll('[data-m]'), function(b){
    b.onclick = function(){ if (S.calMode !== b.dataset.m) { S.calMode = b.dataset.m; renderCal(); } };
  });
  var pv = document.getElementById('calPrev'), nx = document.getElementById('calNext');
  if (pv) pv.onclick = function(){ shiftCal(-1); };
  if (nx) nx.onclick = function(){ shiftCal(1); };
  var td = document.getElementById('calToday');
  if (td) td.onclick = function(){ S.date = todayStr(); if (S.calMode === 'list') S.lst.per = 'today'; renderCal(); };
  var pk = document.getElementById('calPick');
  if (pk) pk.onclick = function(){ miniCalPick(); };
  var st = document.getElementById('calSet');
  if (st) st.onclick = settingsSheet;
  Array.prototype.forEach.call(root.querySelectorAll('.mng-dc'), function(c){
    c.onclick = function(){ S.date = c.dataset.d; renderCal(); };
  });
  Array.prototype.forEach.call(root.querySelectorAll('.mng-schip'), function(c){
    c.onclick = function(){
      if (c.dataset.dr !== undefined) { S.fDir = c.dataset.dr; S.fSpec = ''; }
      else { S.fSpec = c.dataset.sp; S.fDir = ''; }
      renderCal();
    };
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-g]'), function(b){
    b.onclick = function(){
      if ((S.ui.groupBy || 'dir') !== b.dataset.g) {
        S.ui.groupBy = b.dataset.g; S.fSpec = ''; S.fDir = ''; saveUi(); renderCal();
      }
    };
  });
  /* центрируем выбранную дату в ленте */
  var strip = document.getElementById('mngDStrip');
  if (strip) {
    var sel = strip.querySelector('.mng-dc.on');
    if (sel) strip.scrollLeft = sel.offsetLeft - strip.clientWidth/2 + sel.offsetWidth/2;
  }
}
function shiftCal(n){
  if (S.calMode === 'month') {
    var p = S.date.split('-'), m = +p[1] + n, y = +p[0];
    while (m < 1) { m += 12; y--; } while (m > 12) { m -= 12; y++; }
    S.date = y + '-' + p2(m) + '-01';
  } else S.date = addDays(S.date, n);
  renderCal();
}
function renderCal(keepScroll){
  if (S.view !== 'cal') return; /* устаревшие таймеры не трогают чужой раздел */
  var body = document.getElementById('mngBody');
  if (!body) return;
  var gw = root.querySelector('.mng-gridwrap');
  var sy = keepScroll && gw ? gw.scrollTop : 0, sx = keepScroll && gw ? gw.scrollLeft : 0;
  var mw = root.querySelector('.mng-mxwrap');
  var my = keepScroll && mw ? mw.scrollTop : 0, mx = keepScroll && mw ? mw.scrollLeft : 0;
  var lq = keepScroll ? (document.getElementById('lstQ') || {value:null}).value : null;

  if (S.calMode === 'month') body.innerHTML = calTools() + groupChips() + renderMatrix();
  else if (S.calMode === 'list') body.innerHTML = calTools() + renderListHtml();
  else body.innerHTML = calTools() + groupChips() + renderDay();

  bindCalTools();
  if (S.calMode === 'day') {
    bindGrid();
    var g2 = root.querySelector('.mng-gridwrap');
    if (g2) {
      if (keepScroll) { g2.scrollTop = sy; g2.scrollLeft = sx; }
      else if (S.date === todayStr()) g2.scrollTop = Math.max(0, (new Date().getHours() - WORK_START - 1) * hh());
      bindPinch(g2);
    }
  } else if (S.calMode === 'month') {
    bindMatrix();
    var m2 = root.querySelector('.mng-mxwrap');
    if (m2) {
      if (keepScroll) { m2.scrollTop = my; m2.scrollLeft = mx; }
      else { /* прокрутка к сегодняшнему дню месяца */
        var th = m2.querySelector('th.today');
        if (th) m2.scrollLeft = Math.max(0, th.offsetLeft - 120);
      }
    }
  } else bindList(lq);
}
function weekStart(d){
  var t = new Date(d + 'T12:00:00'), wd = (t.getDay() + 6) % 7;
  t.setDate(t.getDate() - wd);
  return t.getFullYear() + '-' + p2(t.getMonth()+1) + '-' + p2(t.getDate());
}

/* ---- мини-календарь для выбора произвольной даты ---- */
function miniCalPick(){
  var old = document.getElementById('mngPick'); if (old) old.remove();
  var cur = S.date;
  var pk = document.createElement('div');
  pk.className = 'mng-pick'; pk.id = 'mngPick';
  function calHtml(d){
    var p = d.split('-'), y = +p[0], m = +p[1];
    var first = new Date(y, m-1, 1), startWd = (first.getDay()+6)%7;
    var daysIn = new Date(y, m, 0).getDate();
    var daysPrev = new Date(y, m-1, 0).getDate();
    var h = '';
    var moOpts = '', yrOpts = '';
    for (var mi = 0; mi < 12; mi++) moOpts += '<option value="' + mi + '"' + (mi === m-1 ? ' selected' : '') + '>' + ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'][mi] + '</option>';
    for (var yi = y-3; yi <= y+3; yi++) yrOpts += '<option' + (yi === y ? ' selected' : '') + '>' + yi + '</option>';
    h += '<div class="ph"><select id="pkM">' + moOpts + '</select><select id="pkY">' + yrOpts + '</select></div>';
    h += '<div class="mng-month">';
    ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].forEach(function(w){ h += '<div class="mng-mh">' + w + '</div>'; });
    var total = Math.ceil((startWd + daysIn) / 7) * 7;
    for (var i = 0; i < total; i++) {
      var dn, dim = false, dObj;
      if (i < startWd) { dn = daysPrev - startWd + 1 + i; dim = true; dObj = new Date(y, m-2, dn); }
      else if (i - startWd < daysIn) { dn = i - startWd + 1; dObj = new Date(y, m-1, dn); }
      else { dn = i - startWd - daysIn + 1; dim = true; dObj = new Date(y, m, dn); }
      var ds = dObj.getFullYear() + '-' + p2(dObj.getMonth()+1) + '-' + p2(dObj.getDate());
      var n = itemsOfDay(ds).length;
      h += '<div class="mng-md' + (dim?' dim':'') + (ds===todayStr()?' today':'') + (ds===S.date?' sel':'') + '" data-d="' + ds + '">' +
        '<div class="dn">' + dn + '</div>' + (n ? '<div class="cnt">' + n + '</div>' : '') + '</div>';
    }
    h += '</div>';
    return h;
  }
  function render(){ pk.querySelector('.mng-pick-c').innerHTML = calHtml(cur); bind(); }
  function bind(){
    pk.querySelector('#pkM').onchange = function(){ var p = cur.split('-'); cur = p[0] + '-' + p2(+this.value+1) + '-01'; render(); };
    pk.querySelector('#pkY').onchange = function(){ var p = cur.split('-'); cur = this.value + '-' + p[1] + '-01'; render(); };
    Array.prototype.forEach.call(pk.querySelectorAll('.mng-md'), function(c){
      c.onclick = function(){
        S.date = c.dataset.d;
        pk.remove();
        renderCal();
      };
    });
  }
  pk.innerHTML = '<div class="mng-pick-c"></div>';
  pk.onclick = function(e){ if (e.target === pk) pk.remove(); };
  root.appendChild(pk);
  render();
}

/* ---- сетка «День»: время вертикально, специалисты горизонтально ---- */
function evHtml(it, narrow, posStyle, showSpec){
  var st = it.status;
  var tint = (st === 'canceled' || st === 'noshow') ? '' : ' tint-' + dirTint(it.dir);
  var ico = (st === 'done') ? '<span class="sic">✓</span>' : (st === 'planned' ? '<span class="sic pl">•</span>' : '');
  var cmp = S.ui.compact;
  var pos = posStyle || '';
  var sub = showSpec ? (it.spec ? ' · ' + esc(it.spec) : '') : (it.phone ? ' · ' + esc(it.phone) : '');
  var sv = svcLine(it);
  if ((narrow || cmp) && it.services && it.services.length > 1) sv = it.services[0] + ' +' + (it.services.length - 1);
  var dkb = (it.src === 'dikidi') ? '<span class="dkb" title="Импортировано из DIKIDI">D</span>' : '';
  var wrn = (it.needinfo && it.needinfo.length) ? '<span class="dkb dk-w" title="Требуется уточнение данных">!</span>' : '';
  return '<div class="mng-ev st-' + st + tint + (cmp ? ' diminfo' : '') + '" data-bid="' + it.id + '" style="' + pos + 'top:' + ((t2m(it.start) - WORK_START*60)/60*hh()) + 'px;height:' + Math.max(24, (t2m(it.end)-t2m(it.start))/60*hh() - 3) + 'px">' +
    ico + '<span class="t">' + esc(it.start) + '–' + esc(it.end) + '</span>' + dkb + wrn + ' <b>' + esc(sv) + '</b>' +
    (narrow || cmp ? '' : '<small>' + esc(it.client) + sub + '</small>') +
    '</div>';
}
function renderDay(){
  var byDir = (S.ui.groupBy !== 'spec') && S.dirs.length;
  var cols;
  if (byDir) {
    cols = allDirs().filter(function(d){ return !S.fDir || S.fDir === d; });
    if (S.fDir === '__other__') cols = ['__other__'];
    if (!cols.length) cols = allDirs();
  } else {
    if (S.fSpec === '__nospec__') cols = ['__nospec__'];
    else {
      cols = visSpecs().filter(function(s){ return !S.fSpec || s === S.fSpec; });
      if (!cols.length) cols = visSpecs();
    }
  }
  var items = itemsOfDay(S.date);
  if (byDir && !S.fDir) { /* записи вне списка направлений — отдельная колонка «Прочее» */
    var anyOther = items.some(function(it){ return !dirInList(it.dir); });
    if (anyOther) cols = cols.concat(['__other__']);
  }
  if (!byDir && !S.fSpec) { /* записи без мастера (солярий и т.п.) — отдельная колонка */
    var anyNoSpec = items.some(function(it){ return !specInList(it.spec); });
    if (anyNoSpec) cols = cols.concat(['__nospec__']);
  }
  var totalH = (WORK_END - WORK_START) * hh();
  var step = +S.ui.step || 15;
  var h = '<div class="mng-gridwrap" id="mngGridWrap"><div class="mng-grid">';
  /* временная шкала — фиксируется слева */
  h += '<div class="mng-gtime"><div class="mng-ghead"></div>';
  for (var hr = WORK_START; hr < WORK_END; hr++) h += '<div class="mng-gh" style="height:' + hh() + 'px"><span>' + p2(hr) + ':00</span></div>';
  h += '</div>';
  cols.forEach(function(col){
    var colItems = items.filter(function(it){
      if (byDir) return (col === '__other__') ? !dirInList(it.dir) : (it.dir === col);
      return (col === '__nospec__') ? !specInList(it.spec) : (it.spec === col);
    });
    var laid = layoutOverlaps(colItems);
    var head = byDir
      ? '<span class="dot" style="background:' + (col === '__other__' ? '#9a8f7d' : dirColor(col)) + '"></span>' + esc(col === '__other__' ? 'Прочее' : col)
      : (col === '__nospec__'
          ? '<span class="dot" style="background:#9a8f7d"></span>Без мастера'
          : '<span style="color:' + specColor(col) + '">●</span> ' + esc(col));
    h += '<div class="mng-gcol"><div class="mng-ghead">' + head + '</div>' +
      '<div class="mng-daycol" data-col="' + esc(col) + '" style="height:' + totalH + 'px">';
    for (var mnt = 0; mnt < (WORK_END - WORK_START) * 60; mnt += step) {
      var isHour = mnt % 60 === 0;
      h += '<div class="mng-slot' + (isHour ? ' hour' : ' minor') + '" style="top:' + (mnt/60*hh()) + 'px;height:' + (step/60*hh()) + 'px"></div>';
    }
    laid.forEach(function(l){
      var w = 100 / l.cols;
      var pos = 'left:calc(' + (l.col*w) + '% + 3px);right:calc(' + (100-(l.col+1)*w) + '% + 3px);';
      h += evHtml(l.it, l.cols > 2, pos, byDir);
    });
    h += nowLine(S.date) + '</div></div>';
  });
  h += '</div></div>';
  if (!cols.length) h = '<div class="mng-empty">Нет видимых колонок. Откройте настройки журнала (⚙) или переключите группировку.</div>';
  setTimeout(function(){
    Array.prototype.forEach.call(root.querySelectorAll('.mng-daycol'), function(c){
      c.onclick = function(e){
        if (e.target.closest('.mng-ev')) return;
        if (S.pinched) return; /* только что был щипок — не создаём запись */
        var r = c.getBoundingClientRect();
        var st2 = +S.ui.step || 15;
        var mins = WORK_START*60 + Math.round(((e.clientY - r.top) / hh() * 60) / st2) * st2;
        mins = Math.max(WORK_START*60, Math.min(WORK_END*60 - 30, mins));
        var pre = {date:S.date, start:m2t(mins), end:m2t(Math.min(mins+60, WORK_END*60))};
        if ((S.ui.groupBy !== 'spec') && S.dirs.length) {
          if (c.dataset.col !== '__other__') pre.dir = c.dataset.col;
          pre.spec = ''; /* по услугам — мастер не нужен (солярий и т.п.) */
        } else pre.spec = (c.dataset.col === '__nospec__') ? '' : c.dataset.col;
        openCard(null, false, pre);
      };
    });
  });
  return h;
}
function layoutOverlaps(items){
  var sorted = items.slice().sort(function(a,b){ return t2m(a.start) - t2m(b.start); });
  var out = [], active = [];
  sorted.forEach(function(it){
    var s = t2m(it.start), e = t2m(it.end);
    active = active.filter(function(a){ return a.e > s; });
    var used = {};
    active.forEach(function(a){ used[a.col] = true; });
    var col = 0; while (used[col]) col++;
    var rec = {it:it, col:col, e:e, cols:1};
    active.push(rec); out.push(rec);
    var group = active.concat();
    var maxc = 0; group.forEach(function(g){ maxc = Math.max(maxc, g.col+1); });
    group.forEach(function(g){ g.cols = Math.max(g.cols, maxc); });
  });
  return out;
}
function nowLine(d){
  if (d !== todayStr()) return '';
  var now = new Date(), mins = now.getHours()*60 + now.getMinutes();
  if (mins < WORK_START*60 || mins > WORK_END*60) return '';
  return '<div class="mng-now" style="top:' + ((mins - WORK_START*60)/60*hh()) + 'px"></div>';
}
function bindGrid(){
  Array.prototype.forEach.call(root.querySelectorAll('.mng-ev'), function(ev){
    ev.onclick = function(e){ e.stopPropagation(); openCard(ev.dataset.bid); };
  });
}
/* масштаб щипком (два пальца) по вертикали сетки; сохраняется на устройстве */
function bindPinch(gw){
  if (gw._pinchBound) return;
  gw._pinchBound = true;
  var d0 = 0, h0 = 0, raf = 0;
  gw.addEventListener('touchstart', function(e){
    if (e.touches.length === 2) {
      d0 = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
      h0 = hh();
      S.pinched = false;
    }
  }, {passive: true});
  gw.addEventListener('touchmove', function(e){
    if (e.touches.length === 2 && d0 > 0) {
      e.preventDefault();
      var d1 = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
      var nh = Math.max(36, Math.min(120, Math.round(h0 * d1 / d0)));
      if (nh !== hh()) {
        S.pinched = true;
        S.ui.hourH = nh;
        if (!raf) raf = requestAnimationFrame(function(){ raf = 0; renderCal(true); });
      }
    }
  }, {passive: false});
  gw.addEventListener('touchend', function(e){
    if (d0 > 0 && e.touches.length < 2) {
      d0 = 0;
      if (S.pinched) { saveUi(); setTimeout(function(){ S.pinched = false; }, 350); }
    }
  }, {passive: true});
}

/* ---- режим «Месяц»: матрица специалисты × даты ---- */
function renderMatrix(){
  var p = S.date.split('-'), y = +p[0], m = +p[1];
  var daysIn = new Date(y, m, 0).getDate();
  var byDir = (S.ui.groupBy !== 'spec') && S.dirs.length;
  var rowsList = byDir ? allDirs() : visSpecs();
  var t = todayStr();
  /* считаем записи (кроме отменённых/неявки); вне списка — «__other__»/«__nospec__» */
  var counts = {}, hasX = false;
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted || it.state) return;
    if (!it.date || it.date.slice(0,7) !== y + '-' + p2(m)) return;
    var rk = byDir ? (dirInList(it.dir) ? it.dir : '__other__') : (specInList(it.spec) ? it.spec : '__nospec__');
    if (rk === '__other__' || rk === '__nospec__') hasX = true;
    var k = rk + '|' + it.date;
    counts[k] = (counts[k] || 0) + 1;
  });
  if (hasX) rowsList = rowsList.concat([byDir ? '__other__' : '__nospec__']);
  var maxC = 1;
  Object.keys(counts).forEach(function(k){ if (counts[k] > maxC) maxC = counts[k]; });

  var h = '<div class="mng-mxwrap"><table class="mng-mx"><thead><tr><th class="mxspec">' + (byDir ? 'Услуга' : 'Специалист') + '</th>';
  for (var d = 1; d <= daysIn; d++) {
    var ds = y + '-' + p2(m) + '-' + p2(d);
    var wd = new Date(y, m-1, d).getDay();
    h += '<th data-d="' + ds + '" class="' + (ds === t ? 'today ' : '') + ((wd === 0 || wd === 6) ? 'wknd' : '') + '" style="cursor:pointer" title="Открыть день">' +
      '<span class="dw">' + ['вс','пн','вт','ср','чт','пт','сб'][wd] + '</span>' + d + '</th>';
  }
  h += '</tr></thead><tbody>';
  rowsList.forEach(function(rk){
    var xrow = (rk === '__other__' || rk === '__nospec__');
    var rlbl = rk === '__other__' ? 'Прочее' : (rk === '__nospec__' ? 'Без мастера' : rk);
    h += '<tr><th class="mxspec">' + (xrow ? '<span class="dot" style="background:#9a8f7d"></span>' : (byDir ? '<span class="dot" style="background:' + dirColor(rk) + '"></span>' : '<span class="dot" style="background:' + specColor(rk) + '"></span>')) + esc(rlbl) + '</th>';
    for (var d2 = 1; d2 <= daysIn; d2++) {
      var ds2 = y + '-' + p2(m) + '-' + p2(d2);
      var n = counts[rk + '|' + ds2] || 0;
      h += '<td class="mxc' + (ds2 === t ? ' today' : '') + '" data-d="' + ds2 + '" data-rk="' + esc(rk) + '">' +
        (n ? '<span class="mxn">' + n + '</span><span class="mxbar" style="width:' + Math.max(18, Math.round(n/maxC*100)) + '%"></span>' : '<span class="mx0">·</span>') + '</td>';
    }
    h += '</tr>';
  });
  h += '</tbody></table></div>' +
    '<div style="color:var(--mut);font-size:12px;margin-top:8px">Число — количество записей ' + (byDir ? 'направления' : 'специалиста') + ' на дату. Нажатие на ячейку открывает день ' + (byDir ? 'этого направления' : 'этого специалиста') + ', на число месяца — день целиком. Колёсико мыши листает дни.</div>';
  return h;
}
function bindMatrix(){
  var byDir = (S.ui.groupBy !== 'spec') && S.dirs.length;
  Array.prototype.forEach.call(root.querySelectorAll('.mng-mx td.mxc'), function(c){
    c.onclick = function(){
      S.date = c.dataset.d;
      if (byDir) { S.fDir = c.dataset.rk || ''; S.fSpec = ''; }
      else { S.fSpec = c.dataset.rk || ''; S.fDir = ''; }
      S.calMode = 'day'; renderCal();
    };
  });
  Array.prototype.forEach.call(root.querySelectorAll('.mng-mx thead th[data-d]'), function(c){
    c.onclick = function(){
      S.date = c.dataset.d; S.fSpec = ''; S.fDir = ''; S.calMode = 'day'; renderCal();
    };
  });
  /* колёсико мыши листает дни, когда по вертикали таблица не скроллится */
  var w = root.querySelector('.mng-mxwrap');
  if (w) {
    w.addEventListener('wheel', function(e){
      if (w.scrollWidth > w.clientWidth + 2 && w.scrollHeight <= w.clientHeight + 2) {
        w.scrollLeft += (e.deltaY || e.deltaX);
        e.preventDefault();
      }
    }, {passive: false});
  }
}

/* ---- режим «Список» ---- */
function listItems(){
  var t = todayStr();
  var from = '', to = '';
  var p = S.lst.per;
  if (p === 'today') { from = t; to = t; }
  else if (p === 'tomorrow') { var tm = addDays(t,1); from = tm; to = tm; }
  else if (p === 'week') { from = weekStart(t); to = addDays(weekStart(t), 6); }
  else if (p === 'month') { from = t.slice(0,8) + '01'; to = t.slice(0,8) + '31'; }
  else if (p === 'upcoming') { from = t; to = '9999'; }
  else if (p === 'past') { from = '0000'; to = addDays(t, -1); }
  var q = (S.lst.q || '').toLowerCase();
  var qd = q.replace(/\D/g, '');
  var out = [];
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted) return; /* удалённые — только в глобальной истории */
    if (it.date < from || it.date > to) return;
    if (S.lst.spec && it.spec !== S.lst.spec) return;
    if (S.lst.dir && it.dir !== S.lst.dir) return;
    if (S.lst.st && it.status !== S.lst.st) return;
    if (q) {
      var hay = ((it.client || '') + ' ' + (it.phone || '') + ' ' + (it.service || '')).toLowerCase();
      var ok = hay.indexOf(q) >= 0;
      if (!ok && qd) ok = (it.phone || '').replace(/\D/g, '').indexOf(qd) >= 0;
      if (!ok) return;
    }
    out.push(it);
  });
  var key = function(it){
    if (S.lst.sort === 'created') return (it.history && it.history[0] ? it.history[0].t : 0);
    if (S.lst.sort === 'updated') { var hst = it.history || []; return hst.length ? hst[hst.length-1].t : 0; }
    return it.date + it.start;
  };
  out.sort(function(a,b){
    var ka = key(a), kb = key(b);
    if (S.lst.sort === 'created' || S.lst.sort === 'updated') return kb - ka; /* свежие сверху */
    if (S.lst.per === 'past') return ka < kb ? 1 : -1;
    return ka < kb ? -1 : 1;
  });
  return out;
}
function dayLabel(d){
  if (d === todayStr()) return 'Сегодня, ' + dRusT(d);
  if (d === addDays(todayStr(), 1)) return 'Завтра, ' + dRusT(d);
  if (d === addDays(todayStr(), -1)) return 'Вчера, ' + dRusT(d);
  return weekDay(d) + ', ' + dRusT(d);
}
function renderListHtml(){
  var specOpts = S.meta.specialists.map(function(s){ return '<option' + (S.lst.spec===s?' selected':'') + '>' + esc(s) + '</option>'; }).join('');
  var h = '<div class="mng-lst-ctl">' +
    '<div class="mng-lst-row"><input class="mng-inp" id="lstQ" placeholder="Поиск: клиент, телефон, услуга…" value="' + esc(S.lst.q) + '"></div>' +
    '<div class="mng-lst-row">' +
      '<select class="mng-sel" id="lstPer">' +
        [['upcoming','Предстоящие'],['today','Сегодня'],['tomorrow','Завтра'],['week','Эта неделя'],['month','Этот месяц'],['past','Прошедшие'],['all','Все даты']].map(function(o){
          return '<option value="' + o[0] + '"' + (S.lst.per===o[0]?' selected':'') + '>' + o[1] + '</option>';
        }).join('') + '</select>' +
      '<select class="mng-sel" id="lstSpec"><option value="">Все специалисты</option>' + specOpts + '</select>' +
      '<select class="mng-sel" id="lstDir"><option value="">Все услуги</option>' + allDirs().map(function(d){ return '<option' + (S.lst.dir===d?' selected':'') + '>' + esc(d) + '</option>'; }).join('') + '</select>' +
    '</div><div class="mng-lst-row">' +
      '<select class="mng-sel" id="lstSt"><option value="">Все состояния</option>' +
        Object.keys(ST_SHORT).map(function(k){ return '<option value="' + k + '"' + (S.lst.st===k?' selected':'') + '>' + ST_SHORT[k] + '</option>'; }).join('') + '</select>' +
      '<select class="mng-sel" id="lstSort">' +
        [['date','По дате посещения'],['created','Недавно созданные'],['updated','Недавно изменённые']].map(function(o){
          return '<option value="' + o[0] + '"' + (S.lst.sort===o[0]?' selected':'') + '>' + o[1] + '</option>';
        }).join('') + '</select>' +
    '</div></div>';
  var items = listItems();
  if (!items.length) return h + '<div class="mng-empty">Записей по выбранным условиям нет.</div>';
  var lastD = '';
  items.forEach(function(it){
    if (S.lst.sort === 'date' && it.date !== lastD) {
      lastD = it.date;
      var n = items.filter(function(x){ return x.date === lastD; }).length;
      h += '<div class="mng-lst-g"><b>' + esc(dayLabel(it.date)) + '</b><span>' + n + ' зап.</span><i></i></div>';
    }
    var off = (it.status === 'canceled' || it.status === 'noshow');
    var dkb = (it.src === 'dikidi') ? ' <span class="dkb" title="Импортировано из DIKIDI">D</span>' : '';
    var wrn = (it.needinfo && it.needinfo.length) ? ' <span class="dkb dk-w" title="Требуется уточнение данных">!</span>' : '';
    h += '<div class="mng-lr' + (off ? ' off' : '') + '" data-bid="' + it.id + '">' +
      '<div class="lt">' + esc(it.start) + '<br><span style="color:var(--mut);font-weight:700">' + esc(it.end) + '</span></div>' +
      '<div class="li"><div class="ln">' + esc(it.client || 'Клиент') + dkb + wrn + '</div>' +
      '<div class="ls">' + esc(svcLine(it)) + '</div></div>' +
      '<div class="lsp"><span class="dot" style="background:' + specColor(it.spec) + '"></span>' + esc(it.spec) + '</div>' +
      '<span class="mng-st stt-' + it.status + '">' + ST_SHORT[it.status] + '</span></div>';
  });
  return h;
}
function bindList(restoreQ){
  var q = document.getElementById('lstQ');
  var tm = null;
  if (q) {
    if (restoreQ != null) q.value = restoreQ;
    q.oninput = function(){ clearTimeout(tm); tm = setTimeout(function(){
      S.lst.q = q.value.trim();
      var pos = q.selectionStart;
      renderCal(true);
      var q2 = document.getElementById('lstQ');
      if (q2) { q2.focus(); try { q2.setSelectionRange(pos, pos); } catch(e){} }
    }, 500); };
  }
  function bf(id, key){ var el = document.getElementById(id); if (el) el.onchange = function(){ S.lst[key] = el.value; renderCal(true); }; }
  bf('lstPer','per'); bf('lstSpec','spec'); bf('lstDir','dir'); bf('lstSt','st'); bf('lstSort','sort');
  Array.prototype.forEach.call(root.querySelectorAll('.mng-lr'), function(r){
    r.onclick = function(){ openCard(r.dataset.bid); };
  });
}

/* ---- настройки отображения журнала (локальные, на устройстве) ---- */
function settingsSheet(){
  var old = document.getElementById('mngSheet'); if (old) old.remove();
  var sh = document.createElement('div');
  sh.className = 'mng-sheet'; sh.id = 'mngSheet';
  var stepChips = [5,10,15,30].map(function(v){
    return '<button class="mng-stepchip' + (S.ui.step===v?' on':'') + '" data-step="' + v + '">' + v + ' мин</button>';
  }).join('');
  var specRows = S.meta.specialists.map(function(s){
    var on = (S.ui.hideSpecs || []).indexOf(s) < 0;
    return '<div class="mng-optrow"><span><span style="color:' + specColor(s) + '">●</span> ' + esc(s) + '</span>' +
      '<label class="mng-sw"><input type="checkbox" data-spec="' + esc(s) + '"' + (on?' checked':'') + '><i></i></label></div>';
  }).join('');
  sh.innerHTML = '<div class="mng-sheet-c">' +
    '<h3>Настройки журнала</h3>' +
    '<div style="color:var(--mut);font-size:12.5px">Локальные предпочтения этого устройства — расписание и данные коллег не меняются.</div>' +
    '<div class="mng-sh-sec">Шаг временной сетки</div>' +
    '<div class="mng-optrow">' + stepChips + '</div>' +
    '<div class="mng-sh-sec">Масштаб (высота часа)</div>' +
    '<div class="mng-optrow"><input class="mng-range" type="range" id="setH" min="36" max="120" step="4" value="' + hh() + '"><b id="setHv" style="min-width:56px;text-align:right">' + hh() + ' px</b></div>' +
    '<div class="mng-sh-sec">Блоки записей</div>' +
    '<div class="mng-optrow"><span>Компактный вид (только время и услуга)</span><label class="mng-sw"><input type="checkbox" id="setCompact"' + (S.ui.compact?' checked':'') + '><i></i></label></div>' +
    '<div class="mng-sh-sec">Отображаемые специалисты</div>' + specRows +
    '<div class="mng-actions"><button class="mng-btn" id="setReset">Сбросить к стандартным</button>' +
    '<button class="mng-btn gold" id="setOk" style="flex:1">Готово</button></div>' +
  '</div>';
  root.appendChild(sh);
  sh.onclick = function(e){ if (e.target === sh) sh.remove(); };
  Array.prototype.forEach.call(sh.querySelectorAll('[data-step]'), function(b){
    b.onclick = function(){
      S.ui.step = +b.dataset.step; saveUi();
      Array.prototype.forEach.call(sh.querySelectorAll('[data-step]'), function(x){ x.classList.toggle('on', +x.dataset.step === S.ui.step); });
      if (S.view === 'cal') renderCal(true);
    };
  });
  document.getElementById('setH').oninput = function(){
    S.ui.hourH = +this.value; saveUi();
    document.getElementById('setHv').textContent = this.value + ' px';
    if (S.view === 'cal' && S.calMode === 'day') renderCal(true);
  };
  document.getElementById('setCompact').onchange = function(){
    S.ui.compact = this.checked; saveUi();
    if (S.view === 'cal' && S.calMode === 'day') renderCal(true);
  };
  Array.prototype.forEach.call(sh.querySelectorAll('[data-spec]'), function(c){
    c.onchange = function(){
      var s = c.dataset.spec;
      S.ui.hideSpecs = (S.ui.hideSpecs || []).filter(function(x){ return x !== s; });
      if (!c.checked) S.ui.hideSpecs.push(s);
      saveUi();
      if (S.view === 'cal') renderCal(true);
    };
  });
  document.getElementById('setReset').onclick = function(){
    S.ui = {step:15, hourH:64, hideSpecs:[], compact:false, groupBy:'dir'};
    saveUi(); sh.remove();
    if (S.view === 'cal') renderCal(); else renderShell();
  };
  document.getElementById('setOk').onclick = function(){ sh.remove(); };
}

/* ================= КАРТОЧКА ЗАПИСИ (нижняя шторка) ================= */
function statusOf(it){
  if (it.state === 'canceled') return 'canceled';
  if (it.state === 'noshow') return 'noshow';
  if (it.reward != null) return 'done';
  if (it.amount != null && it.pay) return 'paid';
  if (it.done) return 'service';
  return 'planned';
}
function freeSlots(spec, date, dur){
  if (!spec || !date) return [];
  var busy = [];
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted || it.state) return;
    if (it.date !== date || it.spec !== spec) return;
    busy.push([t2m(it.start), t2m(it.end)]);
  });
  var out = [];
  var nowM = null;
  if (date === todayStr()) { var nw = new Date(); nowM = nw.getHours()*60 + nw.getMinutes() + 10; }
  for (var m = WORK_START*60; m + dur <= WORK_END*60; m += 15) {
    if (nowM !== null && m < nowM) continue;
    var clash = false;
    for (var b = 0; b < busy.length; b++) if (m < busy[b][1] && m + dur > busy[b][0]) { clash = true; break; }
    if (!clash) out.push(m);
    if (out.length >= 24) break;
  }
  return out;
}
function openCard(bid, soft, prefill){
  var it = bid ? S.items[bid] : null;
  var isNew = !it;
  if (isNew) {
    it = {id:'', date:(prefill&&prefill.date)||S.date, start:(prefill&&prefill.start)||'12:00', end:(prefill&&prefill.end)||'13:00',
      client:(prefill&&prefill.client)||'', phone:(prefill&&prefill.phone)||'', dir:(prefill&&prefill.dir)||(S.dirs[0]&&S.dirs[0].cat)||'',
      service:(prefill&&prefill.service)||'', spec:(prefill&&prefill.spec)||S.fSpec||'', comment:'',
      done:0, amount:null, pay:null, reward:null, state:'', history:[]};
  }
  var st = statusOf(it);
  var old = document.getElementById('mngOvl'); if (old) old.remove();
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngOvl';

  function durOptsFor(dirCat, cur){
    var o = '', d;
    if (dirCat === SOL_DIR) {
      if (!cur || cur < 1 || cur > 30) cur = 10;
      for (d = 1; d <= 30; d++) o += '<option value="' + d + '"' + (cur===d?' selected':'') + '>' + d + ' мин</option>';
      return o;
    }
    for (d = 30; d <= 480; d += 30) o += '<option value="' + d + '"' + (cur===d?' selected':'') + '>' + (d>=60 ? Math.floor(d/60) + ' ч' + (d%60?' '+(d%60)+' мин':'') : d + ' мин') + '</option>';
    if ([30,60,90,120,150,180,210,240,270,300,330,360,390,420,450,480].indexOf(cur) < 0 && cur > 0)
      o = '<option value="' + cur + '" selected>' + cur + ' мин</option>' + o;
    return o;
  }
  function specOptsFor(dirCat, sel, names, retain){
    if(dirCat===SOL_DIR)return SOL_SPECS.map(function(s){return '<option'+(sel===s?' selected':'')+'>'+esc(s)+'</option>';}).join('');
    var list=masterProfiles().filter(function(p){return masterAllows(p,dirCat,names||[]);}).map(function(p){return p.name;});
    var o='<option value="">'+(list.length?'— выберите специалиста —':'Нет подходящих специалистов')+'</option>';
    if(retain && sel && list.indexOf(sel)<0) o+='<option selected>'+esc(sel)+'</option>';
    return o+list.map(function(s){return '<option'+(sel===s?' selected':'')+'>'+esc(s)+'</option>';}).join('');
  }
  var curDur = t2m(it.end) - t2m(it.start);
  var durOpts = durOptsFor(it.dir, curDur);
  var timeOpts = '';
  var onGrid = false;
  for (var tm = WORK_START*60; tm <= WORK_END*60-15; tm += 15) { if (it.start === m2t(tm)) onGrid = true; timeOpts += '<option' + (it.start===m2t(tm)?' selected':'') + '>' + m2t(tm) + '</option>'; }
  if (!onGrid) timeOpts = '<option selected>' + esc(it.start) + '</option>' + timeOpts;
  var dirOpts = S.dirs.map(function(dd){ return '<option' + (it.dir===dd.cat?' selected':'') + '>' + esc(dd.cat) + '</option>'; }).join('');
  var specOpts = specOptsFor(it.dir, it.spec, it.services || (it.service ? [it.service] : []), !isNew);

  var hist = (it.history || []).slice().reverse().map(function(h){
    var d = new Date(h.t*1000);
    var hh2 = p2(d.getHours()) + ':' + p2(d.getMinutes());
    var dd = p2(d.getDate()) + '.' + p2(d.getMonth()+1);
    return '<div class="mng-hrow"><span class="ht">' + dd + ' ' + hh2 + '</span><span><b>' + esc(h.u) + '</b> <span class="hm">' + esc(h.m) + '</span></span></div>';
  }).join('');

  var rest = (it.amount != null ? it.amount : 0) - (it.reward != null ? it.reward : 0);

  /* ТЗ №4: баннер источника и предупреждение о неполных данных для записей из DIKIDI */
  var NEED_RU = {client:'имя клиента', phone:'телефон', spec:'специалист'};
  var dkBanner = '';
  if (!isNew && it.src === 'dikidi') {
    dkBanner = '<div class="dk-src">Импортировано из <b>DIKIDI</b> · синхронизация через Google Calendar</div>';
    if (it.needinfo && it.needinfo.length) {
      var nl = it.needinfo.map(function(k){ return NEED_RU[k] || k; }).join(', ');
      dkBanner += '<div class="dk-warn">⚠ Требуется уточнение: <b>' + esc(nl) + '</b> — заполните вручную ниже.</div>';
    }
  }

  ovl.innerHTML =
  '<div class="mng-card" id="mngCardModal" data-bid="' + esc(it.id) + '">' +
    '<div class="mng-grip" id="mcGrip"></div>' +
    '<button class="mng-x" id="mcX">✕</button>' +
    '<div class="dt">' + esc(dRus(it.date)) + ' · ' + esc(it.start) + '–' + esc(it.end) + ' &nbsp;<span class="mng-st stt-' + st + '">' + ST_RU[st] + '</span></div>' +
    '<h2>' + (isNew ? 'Новая запись' : esc(svcLine(it))) + '</h2>' +
    (isNew ? '' : '<div class="who">Специалист: ' + esc(it.spec) + '<br>Клиент: ' + esc(it.client || '—') +
      (it.phone ? ' · <a href="tel:' + esc(it.phone) + '" style="color:inherit;font-weight:700">' + esc(it.phone) + '</a>' : '') + '</div>') +

    dkBanner +
    (isNew ? '' : '<hr class="mng-hr">') +
    '<div id="mcEdit" style="display:' + (isNew?'block':'none') + '">' +
      '<div class="mng-frm2">' +
      '<div><label class="mng-lbl">Дата</label><input class="mng-inp" type="date" id="mcDate" value="' + esc(it.date) + '"></div>' +
      '<div><label class="mng-lbl">Начало</label><select class="mng-inp" id="mcStart">' + timeOpts + '</select></div>' +
      '<div><label class="mng-lbl">Длительность</label><select class="mng-inp" id="mcDur">' + durOpts + '</select></div>' +
      '<div><label class="mng-lbl">Специалист</label><select class="mng-inp" id="mcSpec">' + specOpts + '</select></div>' +
      '<div class="full"><label class="mng-lbl">Направление</label><select class="mng-inp" id="mcDir">' + dirOpts + '</select></div>' +
      '<div class="full"><label class="mng-lbl">Услуги</label><div id="mcSvcList"></div>' +
      '<button type="button" class="mng-btn sm" id="mcSvcAdd" style="margin-top:2px">+ Добавить услугу</button>' +
      '<div class="mng-svcprice" id="mcSvcPrice" style="display:none"></div></div>' +
      '<div><label class="mng-lbl">Клиент</label><input class="mng-inp" id="mcClient" maxlength="80" value="' + esc(it.client) + '" placeholder="Имя клиента"></div>' +
      '<div><label class="mng-lbl">Телефон</label><input class="mng-inp" id="mcPhone" maxlength="30" value="' + esc(it.phone) + '" placeholder="+7 …"></div>' +
      '<div class="full"><label class="mng-lbl">Комментарий</label><input class="mng-inp" id="mcComm" maxlength="500" value="' + esc(it.comment) + '" placeholder="Пожелания, детали…"></div>' +
      '</div>' +
      '<div class="mng-free" id="mcFree"></div>' +
    '</div>' +
    (isNew ? '' : '<button class="mng-btn sm" id="mcEditBtn" style="margin-top:12px">Изменить детали записи</button>') +

    '<hr class="mng-hr">' +
    '<label class="mng-check' + (it.done?'':' off') + '" id="mcDoneW"><input type="checkbox" id="mcDone"' + (it.done?' checked':'') + '><span>Услуга оказана</span></label>' +
    '<label class="mng-lbl">Получено денег, ₽</label>' +
    '<input class="mng-inp" id="mcAmount" type="number" min="0" step="100" value="' + (it.amount!=null?it.amount:'') + '" placeholder="0">' +
    '<label class="mng-lbl">Способ оплаты</label>' +
    '<div class="mng-radio">' +
      ['cash','qr','sbp','term'].map(function(p){
        return '<label><input type="radio" name="mcPay" value="' + p + '"' + (it.pay===p?' checked':'') + '><span>' + PAY_RU[p] + '</span></label>';
      }).join('') +
    '</div>' +
    '<label class="mng-lbl">Вознаграждение специалисту, ₽</label>' +
    '<input class="mng-inp" id="mcReward" type="number" min="0" step="100" value="' + (it.reward!=null?it.reward:'') + '" placeholder="Ещё не рассчитаны">' +
    '<label class="mng-check' + (it.reward===0?'':' off') + '" id="mcNoRewW" style="margin-top:8px"><input type="checkbox" id="mcNoRew"' + (it.reward===0?' checked':'') + '><span>Без оплаты специалисту</span></label>' +
    '<hr class="mng-hr">' +
    '<div style="display:flex;justify-content:space-between;align-items:baseline"><span style="color:var(--mut)">Остаток салона</span><span class="mng-rest" id="mcRest">' + rub(rest) + '</span></div>' +
    (st==='done' ? '<div class="mng-okline">✓ Запись полностью завершена</div>' : '') +

    '<div class="mng-actions">' +
      '<button class="mng-btn gold" id="mcSave" style="flex:1;padding:13px">Сохранить</button>' +
      (!isNew && st==='planned' ? '<button class="mng-btn" id="mcCancel">Отменить запись</button><button class="mng-btn" id="mcNoShow">Клиент не пришёл</button>' : '') +
      (!isNew && (st==='canceled'||st==='noshow') ? '<button class="mng-btn" id="mcReopen">Вернуть в работу</button>' : '') +
      (!isNew ? '<button class="mng-btn sm" id="mcDel" style="color:var(--red)">Удалить</button>' : '') +
    '</div>' +

    (!isNew ? '<hr class="mng-hr"><details class="mng-hist" open><summary>🕐 История изменений</summary>' + (hist || '<div class="mng-empty">Пока пусто</div>') + '</details>' : '') +
  '</div>';
  root.appendChild(ovl);
  S.dirty = false;

  var $ = function(id){ return document.getElementById(id); };
  /* ---- услуги: мульти-строки ---- */
  function svcNames(){
    var out = [];
    Array.prototype.forEach.call(ovl.querySelectorAll('#mcSvcList select'), function(s){
      var v = (s.value || '').trim(); if (v && out.indexOf(v) < 0) out.push(v);
    });
    return out;
  }
  function updSvcPrice(){
    var box = $('mcSvcPrice'); if (!box) return;
    var names = svcNames();
    if (!names.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    var dur = +$('mcDur').value || 60, dirCat = $('mcDir').value;
    var sum = 0, unknown = [];
    names.forEach(function(n){ var p = svcPrice(dirCat, n, dur); if (p == null) unknown.push(n); else sum += p; });
    box.innerHTML = 'По прайсу: <b>' + rub(sum) + '</b>' +
      (unknown.length ? ' <span style="color:var(--mut)">+ «' + esc(unknown.join('», «')) + '» — нет цены в прайсе</span>' : '') +
      ' <button type="button" class="mng-btn sm" id="mcSvcToAmt" style="margin-left:auto">В «Получено»</button>';
    box.style.display = 'flex';
    $('mcSvcToAmt').onclick = function(){ $('mcAmount').value = sum; updRest(); markDirty(); };
  }
  function fillServSelect(selEl, sel){
    var dirCat = $('mcDir').value, list;
    if (dirCat === SOL_DIR) list = SOL_SERVICES.slice();
    else { var dir = S.dirs.filter(function(x){ return x.cat === dirCat; })[0]; list = dir ? dir.rows.slice() : []; }
    var h = list.map(function(s){ return '<option' + (s===sel?' selected':'') + '>' + esc(s) + '</option>'; }).join('');
    if (sel && list.indexOf(sel) < 0) h += '<option selected>' + esc(sel) + '</option>';
    if (dirCat !== SOL_DIR) h += '<option value="">— другое (вписать) —</option>';
    selEl.innerHTML = h;
  }
  function bindSvcRow(row){
    var selEl = row.querySelector('select');
    selEl.addEventListener('change', function(){
      if (selEl.value === '') {
        askInput('Своя услуга', 'Название услуги', function(nm){
          if (nm) selEl.insertAdjacentHTML('beforeend', '<option selected>' + esc(nm) + '</option>');
          else fillServSelect(selEl, '');
          markDirty(); fillSpec(); renderFree(); updSvcPrice();
        });
        return;
      }
      markDirty(); fillSpec(); renderFree(); updSvcPrice();
    });
    var del = row.querySelector('.mng-svcdel');
    if (del) del.onclick = function(){ row.remove(); markDirty(); fillSpec(); renderFree(); updSvcPrice(); };
  }
  function addSvcRow(sel){
    var list = $('mcSvcList');
    var row = document.createElement('div');
    row.className = 'mng-svcrow';
    row.innerHTML = '<select class="mng-inp"></select><button type="button" class="mng-svcdel" title="Убрать услугу">✕</button>';
    if (!list.children.length) row.querySelector('.mng-svcdel').style.display = 'none'; /* первую услугу не удаляем */
    list.appendChild(row);
    fillServSelect(row.querySelector('select'), sel || '');
    bindSvcRow(row);
    return row;
  }
  var initSvcs = (it.services && it.services.length) ? it.services.slice() : (it.service ? [it.service] : ['']);
  initSvcs.forEach(function(sv){ addSvcRow(sv); });
  updSvcPrice();
  $('mcSvcAdd').onclick = function(){ addSvcRow(''); markDirty(); fillSpec(); renderFree(); updSvcPrice(); };
  function refillSvcSelects(){
    Array.prototype.forEach.call(ovl.querySelectorAll('#mcSvcList select'), function(s){
      var v = s.value; fillServSelect(s, '');
      if (v && Array.prototype.some.call(s.options, function(o){ return o.text === v; })) s.value = v;
    });
  }
  function fillSpec(){ var keep = $('mcSpec').value; $('mcSpec').innerHTML = specOptsFor($('mcDir').value, keep, svcNames(), false); }
  function fillDur(){ var keep = +$('mcDur').value; $('mcDur').innerHTML = durOptsFor($('mcDir').value, keep); }
  $('mcDir').onchange = function(){ refillSvcSelects(); fillSpec(); fillDur(); markDirty(); renderFree(); updSvcPrice(); };
  /* свободные окна: на основании существующих записей специалиста */
  function renderFree(){
    var box = $('mcFree'); if (!box) return;
    if ($('mcEdit').style.display === 'none') { box.innerHTML = ''; return; }
    var dur = +$('mcDur').value || 60;
    if (!$('mcSpec').value) { box.innerHTML = '<div class="fr-t" style="color:var(--mut);font-size:12.5px;margin-top:8px">Специалист не выбран — запись можно сохранить и без него (например, солярий). Свободные окна считаются после выбора специалиста.</div>'; return; }
    var slots = freeSlots($('mcSpec').value, $('mcDate').value, dur);
    if (!slots.length) { box.innerHTML = '<div class="fr-t" style="color:var(--mut);font-size:12.5px;margin-top:8px">Свободных окон на ' + dur + ' мин у этого специалиста нет.</div>'; return; }
    box.innerHTML = '<div class="fr-t">Свободные окна · ' + dur + ' мин</div><div class="fr-c">' +
      slots.map(function(m){ var t = m2t(m); return '<button type="button" class="mng-fchip' + ($('mcStart').value===t?' on':'') + '" data-t="' + t + '">' + t + '</button>'; }).join('') + '</div>';
    Array.prototype.forEach.call(box.querySelectorAll('.mng-fchip'), function(c){
      c.onclick = function(){ $('mcStart').value = c.dataset.t; markDirty(); renderFree(); };
    });
  }
  renderFree();
  $('mcDate').onchange = renderFree; $('mcSpec').onchange = function(){
    var p=masterProfiles().filter(function(x){return x.name===$('mcSpec').value;})[0];
    Array.prototype.forEach.call(ovl.querySelectorAll('#mcSvcList select'),function(el){
      var val=el.value; fillServSelect(el,val);
      if(p&&p.configured) Array.prototype.slice.call(el.options).forEach(function(o){if(o.value!==val && !masterAllows(p,$('mcDir').value,[o.value]))o.remove();});
    }); renderFree();
  };
  $('mcDur').onchange = function(){ renderFree(); updSvcPrice(); };

  function markDirty(){ S.dirty = true; }
  Array.prototype.forEach.call(ovl.querySelectorAll('input,select'), function(el){ el.addEventListener('change', markDirty); el.addEventListener('input', markDirty); });

  var eb = $('mcEditBtn'); if (eb) eb.onclick = function(){ $('mcEdit').style.display = 'block'; eb.style.display = 'none'; renderFree(); };
  $('mcDoneW').onclick = function(e){
    if (e.target.tagName !== 'INPUT') return;
    setTimeout(function(){ $('mcDoneW').className = 'mng-check' + ($('mcDone').checked ? '' : ' off'); });
  };
  function updRest(){
    var a = parseInt($('mcAmount').value, 10); if (isNaN(a)) a = 0;
    var r = parseInt($('mcReward').value, 10); if (isNaN(r)) r = 0;
    $('mcRest').textContent = rub(a - r);
  }
  $('mcAmount').oninput = updRest; $('mcReward').oninput = updRest;
  /* тумблер «Без оплаты специалисту» */
  function applyNoRew(){
    var on = $('mcNoRew').checked;
    $('mcReward').disabled = on;
    if (on) $('mcReward').value = '0';
    else if ($('mcReward').value === '0') $('mcReward').value = '';
    $('mcNoRewW').className = 'mng-check' + (on ? '' : ' off');
    updRest(); markDirty();
  }
  $('mcNoRew').onchange = applyNoRew;
  if ($('mcNoRew').checked) applyNoRew();

  $('mcX').onclick = function(){ ovl.remove(); S.dirty = false; };
  ovl.onclick = function(e){ if (e.target === ovl) { ovl.remove(); S.dirty = false; } };
  /* свайп шторки вниз — закрытие */
  (function(){
    var grip = $('mcGrip'), card = $('mngCardModal');
    if (!grip || !card) return;
    var y0 = 0, dy = 0, drag = false;
    grip.style.touchAction = 'none';
    grip.addEventListener('touchstart', function(e){ y0 = e.touches[0].clientY; drag = true; card.style.transition = 'none'; }, {passive:true});
    grip.addEventListener('touchmove', function(e){
      if (!drag) return;
      dy = Math.max(0, e.touches[0].clientY - y0);
      card.style.transform = 'translateY(' + dy + 'px)';
    }, {passive:true});
    grip.addEventListener('touchend', function(){
      if (!drag) return;
      drag = false; card.style.transition = '';
      if (dy > 110) { ovl.remove(); S.dirty = false; }
      else card.style.transform = '';
      dy = 0;
    }, {passive:true});
  })();

  function collect(){
    var editVisible = $('mcEdit').style.display !== 'none';
    var start = editVisible ? $('mcStart').value : it.start;
    var dur = editVisible ? +$('mcDur').value : (t2m(it.end) - t2m(it.start));
    var endM = t2m(start) + dur; if (endM > WORK_END*60) endM = WORK_END*60;
    var payEl = ovl.querySelector('input[name="mcPay"]:checked');
    var svcs = editVisible ? svcNames() : ((it.services && it.services.length) ? it.services.slice() : (it.service ? [it.service] : []));
    return {
      id: it.id,
      date: editVisible ? $('mcDate').value : it.date,
      start: start, end: m2t(endM),
      client: editVisible ? $('mcClient').value.trim() : it.client,
      phone: editVisible ? $('mcPhone').value.trim() : it.phone,
      dir: editVisible ? $('mcDir').value : it.dir,
      service: svcs.length ? svcs[0] : (editVisible ? '' : it.service),
      services: svcs,
      spec: editVisible ? $('mcSpec').value : it.spec,
      comment: editVisible ? $('mcComm').value.trim() : it.comment,
      done: $('mcDone').checked ? 1 : 0,
      amount: $('mcAmount').value === '' ? null : Math.max(0, parseInt($('mcAmount').value, 10) || 0),
      pay: payEl ? payEl.value : null,
      reward: $('mcNoRew').checked ? 0 : ($('mcReward').value === '' ? null : Math.max(0, parseInt($('mcReward').value, 10) || 0)),
      state: it.state || ''
    };
  }
  function doSave(extra){
    var data = collect();
    if (extra) for (var k in extra) data[k] = extra[k];
    data.action = 'save';
    if (!data.service) { toast('Укажите услугу'); return; }
    if (!data.spec) { toast('Выберите специалиста'); return; }
    $('mcSave').disabled = true;
    api(null, data).then(function(j){
      S.version = j.version;
      S.items[j.item.id] = j.item;
      S.dirty = false;
      refreshViews(); updateBadges();
      ovl.remove(); /* 28.11-В16: после «Сохранить» карточка закрывается */
      toast(extra && extra.state ? 'Готово' : 'Сохранено');
    }).catch(function(e){
      $('mcSave').disabled = false;
      toast(e.msg || 'Не сохранилось');
    });
  }
  $('mcSave').onclick = function(){ doSave(); };
  var c = $('mcCancel'); if (c) c.onclick = function(){ askYes('Отмена записи', 'Отменить запись? Деньги не потребуются.', 'Отменить запись', function(){ doSave({state:'canceled'}); }); };
  var n = $('mcNoShow'); if (n) n.onclick = function(){ askYes('Клиент не пришёл', 'Клиент не пришёл? Запись будет закрыта без сумм.', 'Клиент не пришёл', function(){ doSave({state:'noshow'}); }); };
  var r2 = $('mcReopen'); if (r2) r2.onclick = function(){ doSave({state:''}); };
  var del = $('mcDel'); if (del) del.onclick = function(){
    askYes('Удаление записи', 'Удалить запись «' + svcLine(it) + ' · ' + dRus(it.date) + ' ' + it.start + '»?\n\nЗапись исчезнет из календаря и баланса у всех участников. Полные сведения и кто удалил — сохранятся в разделе «История».', 'Удалить', function(){
      api(null, {action:'delete', id:it.id}).then(function(j){ S.version = j.version; delete S.items[it.id]; ovl.remove(); refreshViews(); updateBadges(); toast('Запись удалена'); }).catch(function(e){ toast(e.msg||'Ошибка'); });
    });
  };
  if (soft !== true) { var md = $('mcDone'); if (md && !isNew) md.focus(); }
}

/* ================= НЕЗАВЕРШЁННЫЕ ================= */
function showUnfinished(){
  var list = unfinishedList();
  if (!list.length) return;
  var old = document.getElementById('mngOvl'); if (old) old.remove();
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngOvl';
  var rows = list.map(function(it){
    return '<div class="mng-unf" data-bid="' + it.id + '">' +
      '<div style="flex:1"><b>' + esc(dRus(it.date)) + ' ' + esc(it.start) + '</b> · ' + esc(svcLine(it)) + ' · ' + esc(it.spec) +
      '<div class="r">' + it.unfinished.map(esc).join(' · ') + '</div></div>→</div>';
  }).join('');
  ovl.innerHTML = '<div class="mng-card"><div class="mng-grip"></div><button class="mng-x" id="muX">✕</button>' +
    '<h2 style="margin-bottom:4px">Незавершённые записи</h2>' +
    '<div style="color:var(--mut);font-size:13.5px;margin-bottom:12px">Время этих записей прошло — заполните расчёт или осознанно закройте запись. Список будет появляться при каждом входе, пока всё не заполнено.</div>' +
    rows + '</div>';
  root.appendChild(ovl);
  document.getElementById('muX').onclick = function(){ ovl.remove(); };
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  Array.prototype.forEach.call(ovl.querySelectorAll('.mng-unf'), function(r){
    r.onclick = function(){ ovl.remove(); openCard(r.dataset.bid); };
  });
}

/* ================= БАЛАНС ================= */
function balRange(){
  var t = todayStr(), y = addDays(t, -1);
  var m = S.bal.mode;
  if (m === 'today') return [t, t];
  if (m === 'yesterday') return [y, y];
  if (m === 'week') return [weekStart(t), addDays(weekStart(t), 6)];
  if (m === 'month') return [t.slice(0,8) + '01', t.slice(0,8) + '31'];
  if (m === 'quarter') { var mm = +t.slice(5,7), q = Math.floor((mm-1)/3)*3 + 1; return [t.slice(0,5) + '-' + p2(q) + '-01', t.slice(0,5) + '-' + p2(q+2) + '-31']; }
  if (m === 'year') return [t.slice(0,4) + '-01-01', t.slice(0,4) + '-12-31'];
  return [S.bal.from || t, S.bal.to || t];
}
function balItems(){
  var r = balRange(), from = r[0], to = r[1];
  var out = [];
  Object.keys(S.items).forEach(function(id){
    var it = S.items[id];
    if (it.deleted || it.state) return;                 /* отменённые/неявка — не в деньгах */
    if (it.date < from || it.date > to) return;
    if (it.amount == null && it.reward == null) return; /* по записи ещё нет денег */
    if (S.bal.fSpec && it.spec !== S.bal.fSpec) return;
    if (S.bal.fPay && it.pay !== S.bal.fPay) return;
    out.push(it);
  });
  out.sort(function(a,b){ return (a.date + a.start) < (b.date + b.start) ? -1 : 1; });
  return out;
}
/* ================= КАССА (блок внизу «Баланса») =================
   Остаток — всегда фактический (считает сервер, от периода не зависит).
   История выдачи — фильтруется выбранным в балансе периодом. */
function cashHtml(r){
  var c = S.cash || {opening_set:false, opening:0, balance:0, payouts:[], history:[]};
  var from = r[0], to = r[1];
  /* 28.11-В17 (ТЗ-5): единая история кассы — автопоступления (receipt), ручные внесения (deposit), изъятия (payout);
     история показывается за выбранный период, остаток кассы всегда текущий */
  var rows = (c.history || []).filter(function(h){
    var d = new Date((h.t || 0) * 1000);
    var ds = d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
    return ds >= from && ds <= to;
  });
  var costSum = 0, profitSum = 0, inSum = 0, depSum = 0;
  rows.forEach(function(h){
    if (h.kind === 'receipt') inSum += h.amount || 0;
    else if (h.kind === 'deposit') depSum += h.amount || 0;
    else if ((h.cat || 'cost') === 'profit') profitSum += h.amount || 0;
    else costSum += h.amount || 0;
  });
  var flt = S.cashFlt || 'all';
  var shown = rows.filter(function(h){
    if (flt === 'all') return true;
    if (flt === 'receipt') return h.kind === 'receipt';
    if (flt === 'deposit') return h.kind === 'deposit';
    return h.kind === 'payout' && (h.cat || 'cost') === flt;
  });

  var left =
    '<div class="mng-cash-l">' +
      '<div class="mc-ico">' + CASH_SVG + '</div>' +
      '<div class="mc-cap">Текущий остаток наличных</div>' +
      '<div class="mc-sum" id="mcCashSum">' + rub(c.balance) + '</div>' +
      ((c.rewards || 0) > 0
        ? '<div class="mc-note">Наличные по записям: ' + rub(c.in_full || 0) + ' · из них мастерам: −' + rub(c.rewards) + '</div>'
        : '') +
      (c.opening_set
        ? '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button class="mng-btn mc-btn-in" id="cashIn" style="flex:1;padding:11px 6px">+ Внести в кассу</button>' +
            '<button class="mng-btn mc-btn-out" id="cashOut" style="flex:1;padding:11px 6px"' + (c.balance > 0 ? '' : ' disabled') + '>− Изъятие кассы</button>' +
          '</div>'
        : '<div class="mc-first">' +
            '<div class="mc-first-t">Первый запуск кассы</div>' +
            '<div class="mc-first-s">Внесите фактический остаток наличных в кассе салона. Операция однократная — дальше остаток считается автоматически.</div>' +
            '<div style="display:flex;gap:8px;margin-top:9px">' +
              '<input class="mng-inp" id="cashOpenSum" type="text" inputmode="numeric" placeholder="0" style="max-width:120px;text-align:center;font-weight:800">' +
              '<button class="mng-btn gold" id="cashOpenBtn" style="flex:1">Задать остаток</button>' +
            '</div>' +
          '</div>') +
      '<div class="mc-hint">В кассу поступает оплата наличными за вычетом вознаграждения мастера — мастер получает свою часть сразу из наличных. Кнопка «Внести в кассу» — для ручного внесения наличных (размен, подкрепление): такие суммы выручкой не считаются. Остаток не зависит от выбранного периода и переносится между днями, месяцами и годами.</div>' +
    '</div>';

  var rowsHtml = shown.length ? shown.map(function(h){
    var d = new Date((h.t || 0) * 1000);
    var dt = p2(d.getDate()) + '.' + p2(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes());
    if (h.kind === 'receipt') {
      var num = String(h.bid || '').replace(/^b/i, '');
      return '<div class="mc-row mc-link" data-bid="' + esc(h.bid || '') + '" title="Открыть запись">' +
        '<div class="mc-r1"><b>Автоматическое поступление</b>' +
          '<span class="mc-cat in">Поступление</span>' +
          '<span class="mc-amt pos">+' + rub(h.amount || 0) + '</span></div>' +
        '<div class="mc-r2">Заявка №' + esc(num) + (h.svc ? ' · ' + esc(h.svc) : '') + (h.spec ? ' · ' + esc(h.spec) : '') + '</div>' +
        ((h.reward || 0) > 0 ? '<div class="mc-r2">Наличные ' + rub(h.gross || 0) + ' − мастеру ' + rub(h.reward) + '</div>' : '') +
        '<div class="mc-r3">' + dt + ' · закрытие заявки</div>' +
      '</div>';
    }
    if (h.kind === 'deposit') {
      return '<div class="mc-row">' +
        '<div class="mc-r1"><b>Ручное внесение</b>' +
          '<span class="mc-cat in">Внесение</span>' +
          '<span class="mc-amt pos">+' + rub(h.amount || 0) + '</span></div>' +
        '<div class="mc-r2">Источник: <b>' + esc(h.source || '—') + '</b>' + (h.comment ? ' · ' + esc(h.comment) : '') + '</div>' +
        '<div class="mc-r3">' + dt + ' · зарегистрировал(а): ' + esc(h.by || '—') + '</div>' +
      '</div>';
    }
    var isP = (h.cat || 'cost') === 'profit';
    return '<div class="mc-row">' +
      '<div class="mc-r1"><b>' + esc(isP ? 'Прибыль' : (h.article || 'Прочие расходы')) + '</b>' +
        '<span class="mc-cat ' + (isP ? 'profit' : 'cost') + '">' + (isP ? 'Прибыль' : 'Затраты') + '</span>' +
        '<span class="mc-amt">−' + rub(h.amount || 0) + '</span></div>' +
      '<div class="mc-r2">' + (isP ? 'Изъятие собственником' : 'Получатель: <b>' + esc(h.receiver || '—') + '</b>') + (h.comment ? ' · ' + esc(h.comment) : '') + '</div>' +
      '<div class="mc-r3">' + dt + ' · зарегистрировал(а): ' + esc(h.by || '—') + '</div>' +
    '</div>';
  }).join('') : '<div class="mc-empty">' + ({all:'За выбранный период операций по кассе не было.', receipt:'Автоматических поступлений за выбранный период нет.', deposit:'Ручных внесений за выбранный период нет.', cost:'Затрат за выбранный период нет.', profit:'Изъятий прибыли за выбранный период нет.'}[flt] || 'За выбранный период операций по кассе не было.') + '</div>';

  var right =
    '<div class="mng-cash-r">' +
      '<div class="mc-kpis">' +
        '<div class="mc-kpi in"><span>Поступления за период</span><b>' + rub(inSum + depSum) + '</b></div>' +
        '<div class="mc-kpi cost"><span>Затраты за период</span><b>' + rub(costSum) + '</b></div>' +
        '<div class="mc-kpi profit"><span>Прибыль за период</span><b>' + rub(profitSum) + '</b></div>' +
        '<div class="mc-kpi dep"><span>Из них внесено вручную</span><b>' + rub(depSum) + '</b></div>' +
      '</div>' +
      '<div class="mc-rh"><span>История операций</span><span class="mc-flt">' +
        [['all','Все'],['receipt','Поступления'],['deposit','Внесения'],['cost','Затраты'],['profit','Прибыль']].map(function(f){
          return '<button class="mng-btn sm' + (flt === f[0] ? ' on' : '') + '" data-cflt="' + f[0] + '">' + f[1] + '</button>';
        }).join('') + '</span></div>' +
      '<div class="mc-list">' + rowsHtml + '</div>' +
    '</div>';

  return '<div class="mng-cash"><div class="mng-cash-t">' + IC.cash + '<span>Касса</span></div>' +
    '<div class="mng-cash-g">' + left + right + '</div></div>';
}
function bindCash(body){
  var outBtn = document.getElementById('cashOut');
  if (outBtn) outBtn.onclick = openCashOut;
  var inBtn = document.getElementById('cashIn');
  if (inBtn) inBtn.onclick = openCashIn;
  /* автопоступление кликабельно — открывает карточку записи */
  Array.prototype.forEach.call(body.querySelectorAll('.mc-row[data-bid]'), function(rw){
    rw.onclick = function(){ if (rw.dataset.bid && S.items[rw.dataset.bid]) openCard(rw.dataset.bid); };
  });
  Array.prototype.forEach.call(body.querySelectorAll('[data-cflt]'), function(b){
    b.onclick = function(){ S.cashFlt = b.dataset.cflt; renderBal(); };
  });
  var opBtn = document.getElementById('cashOpenBtn');
  if (opBtn) opBtn.onclick = function(){
    var v = parseInt((document.getElementById('cashOpenSum').value || '').replace(/[^\d]/g, ''), 10);
    if (isNaN(v)) { toast('Укажите сумму остатка (можно 0)'); return; }
    askYes('Начальный остаток кассы', 'Задать начальный остаток ' + rub(v) + '?\n\nОперация однократная: позже изменить его нельзя. Дальше касса будет считаться автоматически по операциям.', 'Задать остаток', function(){
      api(null, {action:'cash_opening', amount:v}).then(function(j){
        if (j.cash) { S.cash = j.cash; S.cashStamp = cashStamp(j.cash); }
        toast('Начальный остаток кассы сохранён');
        if (S.view === 'bal') renderBal();
      }).catch(function(e){ toast(e.msg || 'Ошибка'); });
    });
  };
}
/* 28.11-В17 (ТЗ-5 п.3): ручное внесение наличных в кассу — не выручка, не вознаграждение */
function openCashIn(){
  var old = document.getElementById('mngOvl'); if (old) old.remove();
  var bal = (S.cash || {}).balance || 0;
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngOvl';
  var nw = new Date();
  var dtStr = p2(nw.getDate()) + '.' + p2(nw.getMonth() + 1) + '.' + nw.getFullYear() + ' ' + p2(nw.getHours()) + ':' + p2(nw.getMinutes());
  ovl.innerHTML =
  '<div class="mng-card">' +
    '<div class="mng-grip"></div><button class="mng-x" id="ciX">✕</button>' +
    '<h2 style="margin-bottom:2px">Внести в кассу</h2>' +
    '<div class="who">Текущий остаток наличных: <b>' + rub(bal) + '</b></div>' +
    '<hr class="mng-hr">' +
    '<label class="mng-lbl">Сумма внесения, ₽</label>' +
    '<input class="mng-inp" id="ciSum" type="text" inputmode="numeric" placeholder="0" autocomplete="off">' +
    '<label class="mng-lbl">Источник поступления денежных средств</label>' +
    '<input class="mng-inp" id="ciSrc" list="ciSrcList" placeholder="Откуда деньги" autocomplete="off">' +
    '<datalist id="ciSrcList"><option value="Подкрепление (размен)"></option><option value="Взнос собственника"></option><option value="Возврат подотчётных"></option><option value="Прочее"></option></datalist>' +
    '<label class="mng-lbl">Комментарий (необязательно)</label>' +
    '<input class="mng-inp" id="ciComm" placeholder="Примечание к операции" autocomplete="off">' +
    '<div class="mc-after">Остаток после внесения: <b id="ciAfterSum">' + rub(bal) + '</b></div>' +
    '<div class="mc-auto">Дата и время: ' + dtStr + ' · Регистрирует: ' + esc(S.name || '—') + '</div>' +
    '<div class="mng-err" id="ciErr" style="display:none"></div>' +
    '<div class="mng-actions">' +
      '<button class="mng-btn" id="ciCancel">Отмена</button>' +
      '<button class="mng-btn gold" id="ciDo" style="flex:1.7;padding:13px">Подтвердить внесение</button>' +
    '</div>' +
  '</div>';
  root.appendChild(ovl);
  document.getElementById('ciX').onclick = function(){ ovl.remove(); };
  document.getElementById('ciCancel').onclick = function(){ ovl.remove(); };
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  setTimeout(function(){ document.getElementById('ciSum').focus(); }, 80);

  var sumEl = document.getElementById('ciSum');
  var curSum = function(){ return parseInt((sumEl.value || '').replace(/[^\d]/g, ''), 10) || 0; };
  sumEl.oninput = function(){ document.getElementById('ciAfterSum').textContent = rub(bal + curSum()); };
  /* идентификатор операции: повторная отправка (двойной тап, обрыв сети) не внесёт деньги дважды */
  var op = 'ci-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);

  document.getElementById('ciDo').onclick = function(){
    var err = document.getElementById('ciErr');
    err.style.display = 'none';
    var v = curSum();
    var src = document.getElementById('ciSrc').value.trim();
    var comm = document.getElementById('ciComm').value.trim();
    if (v < 1) { err.style.display = 'block'; err.textContent = 'Укажите сумму внесения (от 1 ₽).'; return; }
    if (!src) { err.style.display = 'block'; err.textContent = 'Укажите источник поступления денежных средств.'; return; }
    var btn = this;
    btn.disabled = true; btn.textContent = 'Проводим…';
    api(null, {action:'cash_deposit', amount:v, source:src, comment:comm, op:op}).then(function(j){
      if (j.cash) { S.cash = j.cash; S.cashStamp = cashStamp(j.cash); }
      ovl.remove();
      toast('💵 Внесено в кассу: ' + rub(v));
      if (S.view === 'bal') renderBal();
    }).catch(function(e){
      btn.disabled = false; btn.textContent = 'Подтвердить внесение';
      err.style.display = 'block'; err.textContent = e.msg || 'Не удалось провести операцию.';
    });
  };
}
function openCashOut(){
  var old = document.getElementById('mngOvl'); if (old) old.remove();
  var bal = (S.cash || {}).balance || 0;
  if (bal <= 0) { toast('Касса пуста — изъятие невозможно'); return; }
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngOvl';
  var specsDl = (S.meta.specialists || []).map(function(s){ return '<option value="' + esc(s) + '">'; }).join('');
  ovl.innerHTML =
  '<div class="mng-card">' +
    '<div class="mng-grip"></div><button class="mng-x" id="coX">✕</button>' +
    /* ---- шаг 1: ввод ---- */
    '<div id="coStep1">' +
      '<h2 style="margin-bottom:2px">Изъятие кассы</h2>' +
      '<div class="who">Текущий остаток наличных: <b>' + rub(bal) + '</b></div>' +
      '<hr class="mng-hr">' +
      '<label class="mng-lbl">Сумма изъятия, ₽</label>' +
      '<div style="display:flex;gap:8px">' +
        '<input class="mng-inp" id="coSum" type="text" inputmode="numeric" placeholder="0" autocomplete="off" style="flex:1">' +
        '<button class="mng-btn" id="coAll" style="flex:none">Вся сумма</button>' +
      '</div>' +
      '<label class="mng-lbl">Категория операции</label>' +
      '<div class="mc-seg" id="coCat">' +
        '<button type="button" data-cat="cost" class="on">Затраты</button>' +
        '<button type="button" data-cat="profit">Прибыль</button>' +
      '</div>' +
      '<div id="coCostWrap">' +
        '<label class="mng-lbl">Статья расходов</label>' +
        '<select class="mng-sel" id="coArt" style="width:100%;padding:11px 12px;font-size:15px">' +
          '<option value="">— выберите статью —</option>' +
          CASH_ARTS.map(function(a){ return '<option>' + esc(a) + '</option>'; }).join('') +
        '</select>' +
        '<label class="mng-lbl">Получатель денежных средств</label>' +
        '<input class="mng-inp" id="coWho" list="coSpecs" placeholder="Кому выдаются деньги" autocomplete="off"><datalist id="coSpecs">' + specsDl + '</datalist>' +
        '<label class="mng-lbl">Комментарий (необязательно)</label>' +
        '<input class="mng-inp" id="coComm" placeholder="Примечание к операции" autocomplete="off">' +
      '</div>' +
      '<div class="mc-after">Остаток после изъятия: <b id="coAfterSum">' + rub(bal) + '</b></div>' +
      '<div class="mng-err" id="coErr" style="display:none"></div>' +
      '<div class="mng-actions">' +
        '<button class="mng-btn" id="coCancel">Отмена</button>' +
        '<button class="mng-btn gold" id="coNext" style="flex:1.7;padding:13px">Продолжить</button>' +
      '</div>' +
    '</div>' +
    /* ---- шаг 2: подтверждение ---- */
    '<div id="coStep2" style="display:none">' +
      '<h2 style="margin-bottom:2px">Подтверждение изъятия</h2>' +
      '<div class="who">Проверьте данные операции перед списанием</div>' +
      '<hr class="mng-hr">' +
      '<div class="mng-kv" id="coCf"></div>' +
      '<div class="mng-actions">' +
        '<button class="mng-btn" id="coBack">Отмена</button>' +
        '<button class="mng-btn gold" id="coDo" style="flex:1.7;padding:13px">Подтвердить изъятие</button>' +
      '</div>' +
    '</div>' +
  '</div>';
  root.appendChild(ovl);
  document.getElementById('coX').onclick = function(){ ovl.remove(); };
  document.getElementById('coCancel').onclick = function(){ ovl.remove(); };
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  setTimeout(function(){ document.getElementById('coSum').focus(); }, 80);

  var sumEl = document.getElementById('coSum');
  var curSum = function(){ return parseInt((sumEl.value || '').replace(/[^\d]/g, ''), 10) || 0; };
  var updAfter = function(){
    var v = curSum();
    document.getElementById('coAfterSum').textContent = rub(Math.max(0, bal - Math.min(v, bal)));
  };
  sumEl.oninput = updAfter;
  document.getElementById('coAll').onclick = function(){ sumEl.value = String(bal); updAfter(); };
  /* переключатель категории: прибыль — без статьи/получателя */
  var cat = 'cost';
  Array.prototype.forEach.call(document.getElementById('coCat').querySelectorAll('button'), function(b){
    b.onclick = function(){
      cat = b.dataset.cat;
      Array.prototype.forEach.call(document.getElementById('coCat').querySelectorAll('button'), function(x){ x.className = x.dataset.cat === cat ? 'on' : ''; });
      document.getElementById('coCostWrap').style.display = cat === 'cost' ? '' : 'none';
      updAfter();
    };
  });
  /* идентификатор операции: повторная отправка (двойной тап, обрыв сети) не спишет дважды */
  var op = 'co-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);

  document.getElementById('coNext').onclick = function(){
    var err = document.getElementById('coErr');
    var show = function(m){ err.style.display = 'block'; err.textContent = m; };
    err.style.display = 'none';
    var v = curSum();
    var who = document.getElementById('coWho').value.trim();
    var art = document.getElementById('coArt').value;
    var comm = document.getElementById('coComm').value.trim();
    if (v <= 0) return show('Укажите сумму изъятия (от 1 ₽).');
    if (v > bal) return show('Сумма превышает текущий остаток кассы (' + rub(bal) + ').');
    if (cat === 'cost') {
      if (!art) return show('Выберите статью расходов.');
      if (!who) return show('Укажите получателя денежных средств.');
    }
    /* шаг 2 — сводка перед списанием */
    var rows = [['Текущий остаток кассы', rub(bal)],
                ['Сумма изъятия', rub(v)],
                ['Категория операции', cat === 'profit' ? 'Прибыль' : 'Затраты']];
    if (cat === 'cost') rows.push(['Статья расходов', art], ['Получатель', who]);
    if (comm) rows.push(['Комментарий', comm]);
    rows.push(['Остаток после операции', rub(bal - v)]);
    document.getElementById('coCf').innerHTML = rows.map(function(r, i){
      var hot = (i === rows.length - 1) ? ' hot' : '';
      return '<div class="mng-kr' + hot + '"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>';
    }).join('');
    document.getElementById('coStep1').style.display = 'none';
    document.getElementById('coStep2').style.display = '';
  };
  document.getElementById('coBack').onclick = function(){
    document.getElementById('coStep2').style.display = 'none';
    document.getElementById('coStep1').style.display = '';
  };
  document.getElementById('coDo').onclick = function(){
    var btn = this;
    btn.disabled = true; btn.textContent = 'Проводим…';
    var v = curSum();
    api(null, {action:'cash_payout', amount:v, cat:cat,
               article: cat === 'cost' ? document.getElementById('coArt').value : '',
               receiver: cat === 'cost' ? document.getElementById('coWho').value.trim() : '',
               comment: cat === 'cost' ? document.getElementById('coComm').value.trim() : '', op:op}).then(function(j){
      if (j.cash) { S.cash = j.cash; S.cashStamp = cashStamp(j.cash); }
      ovl.remove();
      toast('💸 Изъято из кассы: ' + rub(v) + (cat === 'profit' ? ' (прибыль)' : ''));
      if (S.view === 'bal') renderBal();
    }).catch(function(e){
      btn.disabled = false; btn.textContent = 'Подтвердить изъятие';
      document.getElementById('coStep2').style.display = 'none';
      document.getElementById('coStep1').style.display = '';
      var err = document.getElementById('coErr');
      err.style.display = 'block'; err.textContent = e.msg || 'Не удалось провести операцию.';
    });
  };
}
function renderBal(){
  var body = document.getElementById('mngBody');
  var r = balRange();
  var items = balItems();
  var sumA = 0, sumR = 0, byPay = {cash:0,qr:0,sbp:0,term:0}, bySpec = {};
  items.forEach(function(it){
    var a = it.amount || 0, rw = it.reward || 0;
    sumA += a; sumR += rw;
    if (it.pay && a) byPay[it.pay] += a;
    if (rw) bySpec[it.spec] = (bySpec[it.spec] || 0) + rw;
  });
  var specRows = Object.keys(bySpec).sort().map(function(s){ return '<div>' + esc(s) + ': <b>' + rub(bySpec[s]) + '</b></div>'; }).join('');

  /* группировка: по услугам / по специалистам / по способам оплаты */
  var g = S.bal.group || 'svc';
  var keyOf = g === 'spec' ? function(it){ return it.spec || '— без специалиста —'; }
            : g === 'pay'  ? function(it){ return it.pay ? PAY_RU[it.pay] : '— не указан —'; }
            :                function(it){ return it.service || '—'; };
  var gmap = {}, gorder = [];
  items.forEach(function(it){
    var k = keyOf(it);
    if (!gmap[k]) { gmap[k] = {k:k, n:0, a:0, r:0, its:[]}; gorder.push(k); }
    var G = gmap[k];
    G.n++; G.a += it.amount || 0; G.r += it.reward || 0; G.its.push(it);
  });
  gorder.sort(function(x, y){ return gmap[y].a - gmap[x].a; });
  var occRow = function(it){
    return '<tr class="r" data-bid="' + it.id + '"><td>' + esc(dRus(it.date)) + ' ' + esc(it.start) + '</td><td>' + esc(svcLine(it)) + '</td><td>' + esc(it.spec || '—') + '</td>' +
      '<td>' + (it.amount!=null?rub(it.amount):'—') + '</td><td>' + (it.pay?PAY_RU[it.pay]:'—') + '</td><td>' + (it.reward!=null?rub(it.reward):'—') + '</td>' +
      '<td>' + (it.amount!=null||it.reward!=null ? rub((it.amount||0)-(it.reward||0)) : '—') + '</td></tr>';
  };
  var zapis = function(n){ var m10 = n%10, m100 = n%100; return m10===1&&m100!==11?'запись':(m10>=2&&m10<=4&&(m100<10||m100>=20)?'записи':'записей'); };
  var groupsHtml = gorder.map(function(k){
    var G = gmap[k];
    return '<details class="mng-bgrp"><summary><span class="gn">' + esc(k) + '</span>' +
      '<span class="gc">' + G.n + ' ' + zapis(G.n) + '</span>' +
      '<span class="ga">' + rub(G.a) + '</span></summary>' +
      '<div style="overflow-x:auto"><table class="mng-table"><tr><th>Дата</th><th>Услуга</th><th>Специалист</th><th>Получено</th><th>Оплата</th><th>Вознаграждение</th><th>Остаток</th></tr>' +
      G.its.map(occRow).join('') +
      '<tr class="sum"><td colspan="3">Итого · ' + esc(k) + '</td><td>' + rub(G.a) + '</td><td></td><td>' + rub(G.r) + '</td><td>' + rub(G.a - G.r) + '</td></tr></table></div></details>';
  }).join('');

  body.innerHTML =
    '<div class="mng-bar">' +
      ['today|Сегодня','yesterday|Вчера','week|Неделя','month|Месяц','quarter|Квартал','year|Год','custom|Период'].map(function(x){
        var p = x.split('|');
        return '<button class="mng-btn sm' + (S.bal.mode===p[0]?' on':'') + '" data-bm="' + p[0] + '">' + p[1] + '</button>';
      }).join('') +
    '</div>' +
    (S.bal.mode === 'custom' ?
      '<div class="mng-bar"><input class="mng-inp" style="max-width:170px" type="date" id="bFrom" value="' + esc(S.bal.from||r[0]) + '"> — ' +
      '<input class="mng-inp" style="max-width:170px" type="date" id="bTo" value="' + esc(S.bal.to||r[1]) + '"><button class="mng-btn sm" id="bApply">Показать</button></div>' : '') +
    '<div class="mng-bar">' +
      '<select class="mng-sel" id="bSpec"><option value="">Все специалисты</option>' + S.meta.specialists.map(function(s){ return '<option' + (S.bal.fSpec===s?' selected':'') + '>' + esc(s) + '</option>'; }).join('') + '</select>' +
      '<select class="mng-sel" id="bPay"><option value="">Все способы оплаты</option>' + Object.keys(PAY_RU).map(function(p){ return '<option value="' + p + '"' + (S.bal.fPay===p?' selected':'') + '>' + PAY_RU[p] + '</option>'; }).join('') + '</select>' +
    '</div>' +
    '<div class="mng-bar"><div class="mng-seg mng-gseg">' +
      '<button data-bg="svc"' + (g==='svc'?' class="on"':'') + '>По услугам</button>' +
      '<button data-bg="spec"' + (g==='spec'?' class="on"':'') + '>По специалистам</button>' +
      '<button data-bg="pay"' + (g==='pay'?' class="on"':'') + '>По оплате</button>' +
    '</div></div>' +
    '<div class="mng-balsum">' +
      '<div class="mng-bal gold"><span>Получено денег</span><b>' + rub(sumA) + '</b></div>' +
      '<div class="mng-bal orange"><span>Вознаграждения специалистам</span><b>' + rub(sumR) + '</b></div>' +
      '<div class="mng-bal green"><span>Остаток салона</span><b>' + rub(sumA - sumR) + '</b></div>' +
    '</div>' +
    '<div class="mng-chips">' +
      '<div>Наличные: <b>' + rub(byPay.cash) + '</b></div><div>QR-код: <b>' + rub(byPay.qr) + '</b></div>' +
      '<div>СБП: <b>' + rub(byPay.sbp) + '</b></div><div>Терминал: <b>' + rub(byPay.term) + '</b></div>' +
    '</div>' +
    (specRows ? '<div class="mng-chips">' + specRows + '</div>' : '') +
    (items.length ? groupsHtml : '<div class="mng-empty">За выбранный период денежных операций нет.</div>') +
    cashHtml(r); /* блок «Касса» — внизу, после всех финансовых показателей */
  bindCash(body);
  Array.prototype.forEach.call(body.querySelectorAll('[data-bm]'), function(b){
    b.onclick = function(){ S.bal.mode = b.dataset.bm; renderBal(); };
  });
  Array.prototype.forEach.call(body.querySelectorAll('[data-bg]'), function(b){
    b.onclick = function(){ S.bal.group = b.dataset.bg; renderBal(); };
  });
  var bf = document.getElementById('bApply');
  if (bf) bf.onclick = function(){ S.bal.from = document.getElementById('bFrom').value; S.bal.to = document.getElementById('bTo').value; renderBal(); };
  document.getElementById('bSpec').onchange = function(){ S.bal.fSpec = this.value; renderBal(); };
  document.getElementById('bPay').onchange = function(){ S.bal.fPay = this.value; renderBal(); };
  Array.prototype.forEach.call(body.querySelectorAll('tr.r'), function(tr){
    tr.onclick = function(){ openCard(tr.dataset.bid); };
  });
}

/* ================= ЗАЯВКИ С САЙТА / ПРИЛОЖЕНИЯ ================= */
function leadSvc(l){
  var f = l.fields || {};
  return f['Услуга'] || f['Направление'] || l.type || '';
}
function leadRowView(l){
  var f = l.fields || {};
  var rows = [];
  rows.push(['Клиент', l.name || '—']);
  rows.push(['Телефон', l.phone ? '+' + String(l.phone) : '—']);
  if (f['Направление']) rows.push(['Направление', f['Направление']]);
  if (leadSvc(l) && leadSvc(l) !== f['Направление']) rows.push(['Услуга', leadSvc(l)]);
  if (f['Мастер']) rows.push(['Мастер', f['Мастер']]);
  rows.push(['Желаемая дата', l.wdate || '—']);
  rows.push(['Желаемое время', l.wtime || '—']);
  rows.push(['Заявка поступила', l.date || '—']);
  var skip = {'Имя':1,'Телефон':1,'Услуга':1,'Направление':1,'Мастер':1,'Желаемая дата':1,'Желаемое время':1,'Дата':1,'Дата и время':1,'Желаемые дата и время':1};
  Object.keys(f).forEach(function(k){
    if (skip[k] || !f[k]) return;
    rows.push([k, f[k]]);
  });
  return rows;
}
function openLeadCard(l, readonly){
  var old = document.getElementById('mngOvl'); if (old) old.remove();
  var ovl = document.createElement('div');
  ovl.className = 'mng-ovl'; ovl.id = 'mngOvl';
  var srcRu = l.page === 'app' ? 'из приложения' : 'с сайта';
  ovl.innerHTML =
  '<div class="mng-card">' +
    '<div class="mng-grip"></div>' +
    '<button class="mng-x" id="mlX">✕</button>' +
    '<div class="dt">' + esc(l.date || '') + ' · заявка ' + esc(srcRu) + ' &nbsp;<span class="mng-st ' + (readonly ? 'stt-done' : 'stt-planned') + '">' + (readonly ? 'Обработана' : 'Новая заявка') + '</span></div>' +
    '<h2>' + esc(l.name || 'Без имени') + '</h2>' +
    '<div class="who">' + (l.phone ? '<a href="tel:+' + esc(String(l.phone).replace(/^\+/,'')) + '" style="color:inherit;font-weight:700">+' + esc(String(l.phone).replace(/^\+/,'')) + '</a>' : 'без телефона') +
      (readonly && l.done_by ? '<br>Обработал(а): ' + esc(l.done_by) : '') + '</div>' +
    '<hr class="mng-hr">' +
    '<div class="mng-kv">' +
      leadRowView(l).map(function(r){
        var hot = (r[0] === 'Желаемая дата' || r[0] === 'Желаемое время') ? ' hot' : '';
        return '<div class="mng-kr' + hot + '"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>';
      }).join('') +
    '</div>' +
    (readonly ? '' :
    '<div class="mng-actions">' +
      '<button class="mng-btn gold" id="mlBook" style="flex:1">В расписание</button>' +
      '<button class="mng-btn" id="mlDone">Заявка обработана ✓</button>' +
      '<button class="mng-btn sm" id="mlDel" style="color:var(--red)">Удалить заявку</button>' +
    '</div>') +
  '</div>';
  root.appendChild(ovl);
  document.getElementById('mlX').onclick = function(){ ovl.remove(); };
  ovl.onclick = function(e){ if (e.target === ovl) ovl.remove(); };
  if (readonly) return;
  document.getElementById('mlDone').onclick = function(){
    api(null, {action:'lead_done', id:l.id}).then(function(){ ovl.remove(); toast('Заявка отмечена обработанной'); if (S.view === 'leads') renderLeads(); }).catch(function(e){ toast(e.msg||'Ошибка'); });
  };
  document.getElementById('mlDel').onclick = function(){
    askYes('Удаление заявки', 'Удалить заявку от ' + (l.name || 'клиента') + '?\n\nОна исчезнет из списка заявок у всех. Полные сведения сохранятся в разделе «История».', 'Удалить', function(){
      api(null, {action:'lead_delete', id:l.id}).then(function(){ ovl.remove(); toast('Заявка удалена'); if (S.view === 'leads') renderLeads(); }).catch(function(e){ toast(e.msg||'Ошибка'); });
    });
  };
  document.getElementById('mlBook').onclick = function(){
    /* предзаполняем запись: желаемые дата/время из заявки */
    var fields=l.fields||{}; var requested=fields['Мастер']; var pre = {date: todayStr(), client: l.name || '', phone: l.phone ? '+' + l.phone : '', service: leadSvc(l), dir:fields['Направление']||'', spec:requested==='Любой мастер'||requested==='Без мастера'?'':requested||''};
    var dt = Date.parse((l.wdate || '').replace(/(\d{2})\.(\d{2})\.(\d{4})/, '$2/$1/$3'));
    var iso = '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(l.wdate || '')) iso = l.wdate;
    else if (!isNaN(dt)) { var dd = new Date(dt); iso = dd.getFullYear() + '-' + p2(dd.getMonth()+1) + '-' + p2(dd.getDate()); }
    if (iso && iso >= todayStr()) pre.date = iso;
    var tm = (l.wtime || '').match(/(\d{1,2}):(\d{2})/);
    if (!tm) tm = (l.wdate || '').match(/(\d{1,2}):(\d{2})/); /* время может быть зашито в строке даты */
    if (tm) pre.start = p2(+tm[1]) + ':' + p2(+tm[2]);
    ovl.remove();
    openCard(null, false, pre);
    var mc = document.getElementById('mngOvl');
    if (mc) {
      var note = document.createElement('div');
      note.style.cssText = 'margin-top:10px;width:100%';
      note.innerHTML = '<button class="mng-btn sm" id="mcLeadDone">Заявка обработана ✓</button>';
      mc.querySelector('.mng-actions').appendChild(note);
      document.getElementById('mcLeadDone').onclick = function(){
        api(null, {action:'lead_done', id:l.id}).then(function(){ note.remove(); toast('Заявка отмечена обработанной'); if (S.view === 'leads') renderLeads(); });
      };
    }
  };
}
function renderLeads(){
  var body = document.getElementById('mngBody');
  body.innerHTML = '<div class="mng-empty">Загружаем заявки…</div>';
  Promise.all([api('action=leads'), api('action=leads&done=1').catch(function(){ return {leads:[]}; })]).then(function(rs){
    if (S.view !== 'leads') return; /* пока грузили — пользователь ушёл в другой раздел */
    body = document.getElementById('mngBody');
    if (!body) return;
    var j = rs[0], jd = rs[1] || {leads:[]};
    S.leadsCount = (j.leads || []).length;
    updateBadges();
    var h = '<div class="mng-sec-h"><b>Новые заявки</b>' + (S.leadsCount ? '<span class="cnt">' + S.leadsCount + '</span>' : '') + '</div>';
    if (!j.leads.length) {
      h += '<div class="mng-empty">Новых заявок нет. Заявки с сайта и из приложения появятся здесь — нажмите на заявку, чтобы открыть её карточку.</div>';
    } else {
      h += j.leads.map(function(l, i){
        var wish = (l.wdate || l.wtime) ? ' · хочет: ' + esc(l.wdate || '') + (l.wtime ? ' ' + esc(l.wtime) : '') : '';
        return '<div class="mng-lead" data-lead="' + i + '"><div class="inf"><div class="nm">' + esc(l.name || 'Без имени') + ' · ' + esc(l.phone ? '+' + l.phone : '') + '</div>' +
          '<div class="dt2">поступила ' + esc(l.date || '') + (l.type ? ' · ' + esc(l.type) : '') + '</div>' +
          '<div class="det">' + esc(leadSvc(l)) + wish + '</div></div>' +
          '<span class="src">' + (l.page === 'app' ? 'Приложение' : 'Сайт') + '</span>' +
          '<button class="mng-btn gold sm" data-book="' + i + '">Открыть</button></div>';
      }).join('');
    }
    var done = jd.leads || [];
    if (done.length) {
      h += '<div class="mng-sec-h" style="margin-top:20px"><b>Обработанные</b><span class="cnt" style="background:#eef4e4;color:var(--green)">' + done.length + '</span></div>';
      h += done.map(function(l, i){
        return '<div class="mng-lead done" data-done="' + i + '"><div class="inf"><div class="nm">✓ ' + esc(l.name || 'Без имени') + ' · ' + esc(l.phone ? '+' + l.phone : '') + '</div>' +
          '<div class="dt2">поступила ' + esc(l.date || '') + (l.done_by ? ' · обработал(а) ' + esc(l.done_by) : '') + '</div>' +
          '<div class="det">' + esc(leadSvc(l)) + '</div></div>' +
          '<button class="mng-btn sm" data-dopen="' + i + '">Открыть</button></div>';
      }).join('');
    }
    body.innerHTML = h;
    Array.prototype.forEach.call(body.querySelectorAll('.mng-lead[data-lead]'), function(row){
      row.onclick = function(e){
        if (e.target.tagName === 'BUTTON') return;
        openLeadCard(j.leads[+row.dataset.lead]);
      };
    });
    Array.prototype.forEach.call(body.querySelectorAll('[data-book]'), function(b){
      b.onclick = function(){ openLeadCard(j.leads[+b.dataset.book]); };
    });
    Array.prototype.forEach.call(body.querySelectorAll('.mng-lead[data-done]'), function(row){
      row.onclick = function(e){
        if (e.target.tagName === 'BUTTON') return;
        openLeadCard(done[+row.dataset.done], true);
      };
    });
    Array.prototype.forEach.call(body.querySelectorAll('[data-dopen]'), function(b){
      b.onclick = function(){ openLeadCard(done[+b.dataset.dopen], true); };
    });
  }).catch(function(){ body.innerHTML = '<div class="mng-empty">Не удалось загрузить заявки.</div>'; });
}

/* ================= ИСТОРИЯ ДЕЙСТВИЙ (только просмотр) ================= */
var EV_META = {
  create:        {i:'📅', c:'green',  ru:'Создание записи'},
  lead_new:      {i:'🔔', c:'blue',   ru:'Новая заявка'},
  edit:          {i:'✏️', c:'blue',   ru:'Редактирование записи'},
  reschedule:    {i:'🕐', c:'blue',   ru:'Изменение даты/времени'},
  service_change:{i:'💅', c:'blue',   ru:'Изменение услуги'},
  spec_change:   {i:'👤', c:'blue',   ru:'Изменение специалиста'},
  cancel:        {i:'🚫', c:'gray',   ru:'Отмена записи'},
  noshow:        {i:'⚠️', c:'red',    ru:'Клиент не пришёл'},
  return:        {i:'↩️', c:'blue',   ru:'Возврат в работу'},
  done:          {i:'✅', c:'amber',  ru:'Услуга оказана'},
  pay_new:       {i:'👛', c:'orange', ru:'Внесена оплата'},
  pay_change:    {i:'👛', c:'orange', ru:'Изменена сумма оплаты'},
  paymethod:     {i:'💳', c:'orange', ru:'Изменён способ оплаты'},
  reward_new:    {i:'💰', c:'green',  ru:'Внесено вознаграждение'},
  reward_change: {i:'💰', c:'green',  ru:'Изменено вознаграждение'},
  complete:      {i:'🏁', c:'green',  ru:'Запись завершена'},
  delete:        {i:'🗑️', c:'red',    ru:'Удаление записи'},
  lead_delete:   {i:'🗑️', c:'red',    ru:'Удаление заявки'},
  lead_done:     {i:'📨', c:'blue',   ru:'Заявка обработана'},
  cash_opening:  {i:'🏦', c:'green',  ru:'Касса: начальный остаток'},
  cash_payout:   {i:'💸', c:'orange', ru:'Касса: изъятие наличных'},
  cash_deposit:  {i:'💵', c:'green',  ru:'Касса: ручное внесение'},
  update:        {i:'🔄', c:'blue',   ru:'Обновление из DIKIDI'},
  gcal_conflict: {i:'⚡', c:'red',    ru:'Конфликт расписания (DIKIDI)'},
  gcal_notice:   {i:'📌', c:'amber',  ru:'Требует проверки (DIKIDI)'}
};
var EV_C_HEX = {green:'#6d9350', blue:'#7d8ca8', orange:'#c27a3a', red:'#b3543f', gray:'#8a8578', amber:'#c1912f'};
var SRC_RU = {panel:'Админ-панель', app:'Приложение', web:'Браузер', site:'Сайт', dikidi:'DIKIDI'};
if (!S.hf) S.hf = {tab:'', from:'', to:'', user:'', type:'', src:'', spec:'', q:'', del:false, fin:false, oid:''};

function evDT(t){
  var d = new Date(t*1000);
  return p2(d.getDate()) + '.' + p2(d.getMonth()+1) + '.' + d.getFullYear() + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes());
}
function evDay(t){
  var d = new Date(t*1000);
  var ds = d.getFullYear() + '-' + p2(d.getMonth()+1) + '-' + p2(d.getDate());
  return dayLabel(ds);
}
var HIST_NM = {date:'Дата посещения', start:'Время начала', end:'Время окончания', client:'Клиент', phone:'Телефон',
  dir:'Направление', service:'Услуга', spec:'Специалист', comment:'Комментарий', done:'Услуга оказана',
  amount:'Получено, ₽', pay:'Способ оплаты', reward:'Вознаграждение, ₽', state:'Статус',
  src:'Источник', needinfo:'Требует уточнения', price_src:'Источник цены',
  name:'Клиент', type:'Тип заявки', wdate:'Желаемая дата', wtime:'Желаемое время'};
function histVal(k, v){
  if (v !== null && typeof v === 'object') return null;
  if (k === 'pay') return PAY_RU[v] || v;
  if (k === 'state') return {canceled:'Отменена', noshow:'Клиент не пришёл', '':'—'}[v] || v;
  if (k === 'done') return v ? 'да' : 'нет';
  return v;
}
function evKV(o){
  if (!o) return '';
  return Object.keys(o).map(function(k){
    if (k === 'id' || k === 'ts') return '';
    var v = histVal(k, o[k]);
    if (v === null) return '';
    if (v === '') v = '—';
    return '<div class="mng-kr"><span>' + esc(HIST_NM[k] || k) + '</span><b>' + esc(String(v)) + '</b></div>';
  }).join('');
}
function snapKV(s){
  if (!s) return '';
  var NM = HIST_NM;
  var out = [];
  Object.keys(s).forEach(function(k){
    if (k === 'fields' && typeof s[k] === 'object' && s[k]) { /* заявка: разворачиваем поля формы */
      Object.keys(s[k]).forEach(function(fk){ if (s[k][fk]) out.push([fk, s[k][fk]]); });
      return;
    }
    if (k === 'id' || k === 'ts') return;
    var v = s[k];
    if (k === 'pay') v = PAY_RU[v] || v;
    if (k === 'state') v = {canceled:'Отменена', noshow:'Клиент не пришёл', '':''}[v] || v;
    if (k === 'done') v = v ? 'да' : 'нет';
    if (v === null || v === '' || typeof v === 'object') return;
    out.push([NM[k] || k, String(v)]);
  });
  return out.map(function(r){ return '<div class="mng-kr"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('');
}
function histRowHtml(ev){
  var m = EV_META[ev.type] || {i:'•', c:'blue', ru:ev.type};
  var details = '';
  if (ev.before || ev.after) {
    details += '<div class="mng-ba">' +
      '<div><div class="mng-ba-t">До</div>' + (evKV(ev.before) || '<div class="mng-kr"><span>—</span><b>—</b></div>') + '</div>' +
      '<div><div class="mng-ba-t">После</div>' + (evKV(ev.after) || '<div class="mng-kr"><span>—</span><b>—</b></div>') + '</div></div>';
  }
  if (ev.snap) details += '<div class="mng-ba-t" style="margin-top:10px">Полные сведения ' + (ev.del ? '(удалённый объект — сохранён целиком)' : '') + '</div><div class="mng-kv">' + snapKV(ev.snap) + '</div>';
  if (!details) details = '<div style="color:var(--mut);font-size:13px">Без изменения значений.</div>';
  var d = new Date((ev.t || 0) * 1000);
  return '<div class="mng-hev">' +
    '<div class="ht2">' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + '</div>' +
    '<div class="mng-eic eic-' + m.c + '">' + m.i + '</div>' +
    '<div class="mng-hev-m">' +
      '<div class="mng-hev-t"><b>' + esc(ev.title || m.ru) + '</b>' + (ev.del ? '<span class="mng-delb">удалено</span>' : '') + '</div>' +
      '<div class="mng-hev-i">' + esc(ev.info || '') + '</div>' +
      '<div class="mng-hev-d">' + esc(ev.user || '—') + ' · ' + esc(SRC_RU[ev.src] || ev.src || '—') + '</div>' +
      '<div class="mng-hev-x">' + details +
        '<div style="margin-top:8px"><button class="mng-btn sm" data-chain="' + esc(ev.obj + ':' + ev.oid) + '">Вся цепочка действий по ' + (ev.obj === 'lead' ? 'заявке' : (ev.obj === 'cash' ? 'операции кассы' : 'записи')) + '</button></div>' +
      '</div>' +
    '</div><div class="chv">›</div>' +
  '</div>';
}
function bindHistRows(body){
  Array.prototype.forEach.call(body.querySelectorAll('.mng-hev-m'), function(mEl){
    var tEl = mEl.querySelector('.mng-hev-t'), iEl = mEl.querySelector('.mng-hev-i');
    var toggle = function(){
      var x = mEl.querySelector('.mng-hev-x');
      x.style.display = x.style.display === 'block' ? 'none' : 'block';
    };
    tEl.style.cursor = 'pointer'; tEl.onclick = toggle;
    iEl.style.cursor = 'pointer'; iEl.onclick = toggle;
  });
  Array.prototype.forEach.call(body.querySelectorAll('[data-chain]'), function(b){
    b.onclick = function(e){
      e.stopPropagation();
      S.hf.oid = b.dataset.chain.split(':')[1];
      S.hf.tab = '';
      if (S.view !== 'hist') { S.view = 'hist'; renderShell(); } else renderHist();
    };
  });
}
function renderHist(){
  var body = document.getElementById('mngBody');
  var hf = S.hf;
  body.innerHTML = '<div class="mng-empty">Загружаем историю…</div>';
  var qs = 'action=history&limit=300';
  if (hf.tab) qs += '&tab=' + hf.tab;
  if (hf.from) qs += '&from=' + hf.from;
  if (hf.to) qs += '&to=' + hf.to;
  if (hf.user) qs += '&user=' + encodeURIComponent(hf.user);
  if (hf.type) qs += '&type=' + hf.type;
  if (hf.src) qs += '&src=' + hf.src;
  if (hf.spec) qs += '&spec=' + encodeURIComponent(hf.spec);
  if (hf.oid) qs += '&oid=' + encodeURIComponent(hf.oid);
  if (hf.del) qs += '&del=1';
  if (hf.fin) qs += '&fin=1';
  if (hf.q) qs += '&q=' + encodeURIComponent(hf.q);
  api(qs).then(function(j){
    if (S.view !== 'hist') return; /* раздел уже сменили — не затираем чужой контент */
    body = document.getElementById('mngBody');
    if (!body) return;
    var typeOpts = Object.keys(EV_META).map(function(t){
      return '<option value="' + t + '"' + (hf.type===t?' selected':'') + '>' + EV_META[t].ru + '</option>';
    }).join('');
    var userOpts = (j.users || []).map(function(u){ return '<option' + (hf.user===u?' selected':'') + '>' + esc(u) + '</option>'; }).join('');
    var specOpts = (j.specs || []).map(function(s){ return '<option' + (hf.spec===s?' selected':'') + '>' + esc(s) + '</option>'; }).join('');

    var rows = '', lastDay = '';
    (j.rows || []).forEach(function(ev){
      var dl = evDay(ev.t || 0);
      if (dl !== lastDay) { lastDay = dl; rows += '<div class="mng-hg"><b>' + esc(dl) + '</b><i></i></div>'; }
      rows += histRowHtml(ev);
    });

    body.innerHTML =
      '<div style="color:var(--mut);font-size:12.5px;margin:2px 0 10px">Хронология всех действий команды. Журнал ведётся автоматически, хранится на сервере и недоступен для изменения или очистки — удалённые записи и заявки сохраняются здесь полностью.</div>' +
      '<div class="mng-bar">' +
        '<button class="mng-btn sm' + (hf.tab===''?' on':'') + '" data-htab="">Все действия</button>' +
        '<button class="mng-btn sm' + (hf.tab==='new'?' on':'') + '" data-htab="new">Создания</button>' +
        '<button class="mng-btn sm' + (hf.tab==='del'?' on':'') + '" data-htab="del">Удаления</button>' +
      '</div>' +
      '<div class="mng-hfilters">' +
        '<input class="mng-inp" id="hfQ" placeholder="Поиск: имя или телефон клиента" value="' + esc(hf.q) + '">' +
        '<div class="mng-hf-row">' +
          '<input class="mng-inp" type="date" id="hfFrom" value="' + esc(hf.from) + '" title="С">' +
          '<input class="mng-inp" type="date" id="hfTo" value="' + esc(hf.to) + '" title="По">' +
          '<select class="mng-sel" id="hfUser"><option value="">Все пользователи</option>' + userOpts + '</select>' +
          '<select class="mng-sel" id="hfType"><option value="">Все типы действий</option>' + typeOpts + '</select>' +
          '<select class="mng-sel" id="hfSpec"><option value="">Все специалисты</option>' + specOpts + '</select>' +
          '<select class="mng-sel" id="hfSrc">' +
            '<option value="">Все источники</option>' +
            ['panel','app','web','site','dikidi'].map(function(s){ return '<option value="' + s + '"' + (hf.src===s?' selected':'') + '>' + SRC_RU[s] + '</option>'; }).join('') +
          '</select>' +
        '</div>' +
        '<div class="mng-hf-row" style="align-items:center">' +
          '<label class="mng-hfchk"><input type="checkbox" id="hfDel"' + (hf.del?' checked':'') + '> только удалённые</label>' +
          '<label class="mng-hfchk"><input type="checkbox" id="hfFin"' + (hf.fin?' checked':'') + '> только финансовые изменения</label>' +
          '<button class="mng-btn sm" id="hfReset">Сбросить</button>' +
          (hf.oid ? '<button class="mng-btn sm" id="hfUnchain" style="color:var(--wine)">✕ показаны события одной записи</button>' : '') +
        '</div>' +
      '</div>' +
      (rows ? '<div class="mng-hlist">' + rows + '</div>' : '<div class="mng-empty">Событий по выбранным условиям нет.</div>');

    Array.prototype.forEach.call(body.querySelectorAll('[data-htab]'), function(b){
      b.onclick = function(){ hf.tab = b.dataset.htab; renderHist(); };
    });
    function bindF(id, key, isChk){
      var el = document.getElementById(id); if (!el) return;
      el.onchange = function(){ hf[key] = isChk ? (el.checked ? 1 : '') : el.value; renderHist(); };
    }
    bindF('hfFrom','from'); bindF('hfTo','to'); bindF('hfUser','user'); bindF('hfType','type');
    bindF('hfSpec','spec'); bindF('hfSrc','src'); bindF('hfDel','del',true); bindF('hfFin','fin',true);
    var qEl = document.getElementById('hfQ');
    var qTm = null;
    qEl.oninput = function(){ clearTimeout(qTm); qTm = setTimeout(function(){ hf.q = qEl.value.trim(); renderHist(); }, 600); };
    document.getElementById('hfReset').onclick = function(){
      S.hf = {tab:'', from:'', to:'', user:'', type:'', src:'', spec:'', q:'', del:false, fin:false, oid:''}; renderHist();
    };
    var uc = document.getElementById('hfUnchain');
    if (uc) uc.onclick = function(){ hf.oid = ''; renderHist(); };
    bindHistRows(body);
  }).catch(function(){ body.innerHTML = '<div class="mng-empty">Не удалось загрузить историю.</div>'; });
}

/* ================= УВЕДОМЛЕНИЯ (лента событий из журнала) ================= */
var NF_TABS = [
  ['', 'Все'],
  ['book', 'Записи'],
  ['lead', 'Заявки'],
  ['pay', 'Платежи'],
  ['sys', 'Системные']
];
var NF_GROUPS = {
  book: ['create','edit','reschedule','service_change','spec_change','cancel','noshow','return','done','complete','delete'],
  lead: ['lead_new','lead_done','lead_delete'],
  pay:  ['pay_new','pay_change','paymethod','reward_new','reward_change','cash_opening','cash_payout'],
  sys:  []
};
if (!S.nfTab) S.nfTab = '';
function renderNotif(){
  var body = document.getElementById('mngBody');
  body.innerHTML = '<div class="mng-empty">Загружаем уведомления…</div>';
  api('action=history&limit=150').then(function(j){
    if (S.view !== 'notif') return; /* раздел уже сменили — не затираем чужой контент */
    body = document.getElementById('mngBody');
    if (!body) return;
    var rows = (j.rows || []);
    if (S.nfTab) {
      var grp = NF_GROUPS[S.nfTab] || [];
      rows = rows.filter(function(ev){ return grp.indexOf(ev.type) >= 0; });
    }
    var h = '<div class="mng-bar">' + NF_TABS.map(function(t){
      return '<button class="mng-btn sm' + (S.nfTab===t[0]?' on':'') + '" data-nf="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';
    if (!rows.length) {
      h += '<div class="mng-empty">' + (S.nfTab === 'sys' ? 'Системных уведомлений нет. Здесь появятся служебные сообщения.' : 'Событий пока нет.') + '</div>';
    } else {
      var lastDay = '';
      rows.forEach(function(ev){
        var dl = evDay(ev.t || 0);
        if (dl !== lastDay) { lastDay = dl; h += '<div class="mng-hg"><b>' + esc(dl) + '</b><i></i></div>'; }
        var m = EV_META[ev.type] || {i:'•', c:'blue', ru:ev.type};
        var d = new Date((ev.t || 0) * 1000);
        h += '<div class="mng-nf" data-obj="' + esc(ev.obj || '') + ':' + esc(ev.oid || '') + '" style="--nb:' + (EV_C_HEX[m.c] || '#a95d6d') + ';cursor:pointer">' +
          '<div class="mng-eic eic-' + m.c + '">' + m.i + '</div>' +
          '<div class="mng-hev-m"><div class="mng-hev-t"><b>' + esc(ev.title || m.ru) + '</b></div>' +
          '<div class="mng-hev-i">' + esc(ev.info || '') + '</div>' +
          '<div class="mng-hev-d">' + esc(ev.user || '—') + ' · ' + esc(SRC_RU[ev.src] || ev.src || '—') + '</div></div>' +
          '<div class="ntm">' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + '</div></div>';
      });
    }
    body.innerHTML = h;
    Array.prototype.forEach.call(body.querySelectorAll('[data-nf]'), function(b){
      b.onclick = function(){ S.nfTab = b.dataset.nf; renderNotif(); };
    });
    Array.prototype.forEach.call(body.querySelectorAll('.mng-nf'), function(r){
      r.onclick = function(){
        var p = r.dataset.obj.split(':');
        var oid = p[1] || '';
        if (p[0] === 'booking' && S.items[oid]) { openCard(oid); return; }
        /* объект удалён или это заявка — открываем цепочку в истории */
        S.hf = {tab:'', from:'', to:'', user:'', type:'', src:'', spec:'', q:'', del:false, fin:false, oid:oid};
        S.view = 'hist'; renderShell();
      };
    });
  }).catch(function(){ body.innerHTML = '<div class="mng-empty">Не удалось загрузить уведомления.</div>'; });
}

/* ================= СПЕЦИАЛИСТЫ ================= */
function renderSpecs(){
  var body=document.getElementById('mngBody');
  var draft=masterProfiles().map(function(p){return {id:p.id||'',name:p.name,active:p.active!==false,services:(p.services||[]).map(function(s){return {dir:s.dir,name:s.name};})};});
  var dirs=S.dirs.filter(function(d){return d.cat!==SOL_DIR;});
  var version=S.meta.v||0;
  body.innerHTML='<div style="max-width:680px"><p style="color:var(--mut);font-size:13px">Назначьте мастеру услуги из действующего прайса. Сайт и приложение используют этот список. Отключение не удаляет историю.</p><div id="spRows"></div><button class="mng-btn sm" id="spAdd">+ Добавить специалиста</button><div style="margin-top:18px"><button class="mng-btn gold" id="spSave">Сохранить список</button></div></div>';
  var host=document.getElementById('spRows');
  function has(p,d,n){return p.services.some(function(s){return s.dir===d&&s.name===n;});}
  function render(){
    host.innerHTML='';
    draft.forEach(function(p,i){
      var card=document.createElement('section'); card.style.cssText='border:1px solid #e4d9c8;border-radius:12px;padding:12px;margin:0 0 12px;';
      card.innerHTML='<label class="mng-lbl">Имя специалиста</label><input class="mng-inp sp-name" maxlength="40" value="'+esc(p.name)+'" placeholder="Имя специалиста"><label style="display:block;margin:10px 0"><input type="checkbox" class="sp-active" '+(p.active?'checked':'')+'> Принимает клиентов</label><div class="sp-cats"></div>'+(!p.id&&!S.meta.specialists.includes(p.name)?'<button class="mng-btn sm sp-remove">Убрать новую карточку</button>':'');
      card.querySelector('.sp-name').oninput=function(){p.name=this.value;};
      card.querySelector('.sp-active').onchange=function(){p.active=this.checked;};
      var remove=card.querySelector('.sp-remove'); if(remove)remove.onclick=function(){draft.splice(i,1);render();};
      dirs.forEach(function(d){
        var detail=document.createElement('details');detail.style.cssText='padding:8px 0;border-top:1px solid #eee;';
        var count=d.rows.filter(function(n){return has(p,d.cat,n);}).length;
        detail.innerHTML='<summary style="cursor:pointer">'+esc(d.cat)+' <span style="color:var(--mut)" class="sp-count">'+count+'/'+d.rows.length+'</span></summary><label style="display:block;padding:8px 0"><input type="checkbox" class="sp-all"> Все услуги направления</label><div class="sp-services"></div>';
        var list=detail.querySelector('.sp-services'),all=detail.querySelector('.sp-all');
        function update(){var c=d.rows.filter(function(n){return has(p,d.cat,n);}).length;all.checked=c===d.rows.length&&c>0;all.indeterminate=c>0&&c<d.rows.length;detail.querySelector('.sp-count').textContent=c+'/'+d.rows.length;}
        d.rows.forEach(function(n){
          var label=document.createElement('label');label.style.cssText='display:flex;gap:8px;align-items:flex-start;padding:5px 0;font-size:13px;';
          var cb=document.createElement('input');cb.type='checkbox';cb.checked=has(p,d.cat,n);
          label.appendChild(cb);label.appendChild(document.createTextNode(n+(d.price[n]!=null?' · '+rub(d.price[n]):'')));list.appendChild(label);
          cb.onchange=function(){p.services=p.services.filter(function(s){return !(s.dir===d.cat&&s.name===n);});if(cb.checked)p.services.push({dir:d.cat,name:n});update();};
        });
        all.onchange=function(){var on=all.checked;p.services=p.services.filter(function(s){return s.dir!==d.cat;});if(on)d.rows.forEach(function(n){p.services.push({dir:d.cat,name:n});});Array.prototype.forEach.call(list.querySelectorAll('input'),function(c){c.checked=on;});update();};update();card.querySelector('.sp-cats').appendChild(detail);
      });host.appendChild(card);
    });
  }
  render();
  document.getElementById('spAdd').onclick=function(){draft.push({id:'',name:'',active:true,services:[]});render();};
  document.getElementById('spSave').onclick=function(){
    if(draft.some(function(p){return !p.name.trim()||(p.active&&!p.services.length);})){toast('Укажите имя и услуги каждого активного специалиста');return;}
    var btn=this;btn.disabled=true;
    api(null,{action:'save_meta',meta_v:version,master_profiles:draft}).then(function(j){S.meta=j.meta;toast('Список сохранён');renderSpecs();}).catch(function(e){btn.disabled=false;toast(e.msg||'Не сохранилось');});
  };
}

/* ================= смена ориентации: сохраняем дату, режим, позицию ================= */
var rszTm = null;
window.addEventListener('resize', function(){
  clearTimeout(rszTm);
  rszTm = setTimeout(function(){
    if (S.view === 'cal' && document.getElementById('mngBody')) renderCal(true);
  }, 320);
});

/* ================= INIT ================= */
if (root.dataset.token) {
  /* режим админ-панели: токен выдан сервером */
  S.panel = true;
  S.token = root.dataset.token;
  api('action=whoami').then(function(j){
    if (j.name) { S.name = j.name; boot(); }
    else askName();
  }).catch(function(){ renderLogin('Не удалось проверить доступ. Обновите страницу.'); });
} else {
  var saved = null;
  try { saved = JSON.parse(localStorage.getItem('klio_manage') || 'null'); } catch(e){}
  if (saved && saved.token) {
    S.token = saved.token; S.name = saved.name || '';
    api('action=whoami').then(function(j){ S.name = j.name || S.name; boot(); }).catch(function(e){
      if (e && e.auth) renderLogin(); else renderLogin('Нет соединения. Попробуйте позже.');
    });
  } else renderLogin();
}
})();
