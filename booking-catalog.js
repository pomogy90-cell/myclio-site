(function(g){
'use strict';
var data={services:[],masters:[]}, pending=null;
function norm(s){return String(s||'').toLowerCase().trim();}
function matches(category,s){
 var c=norm(category),d=norm(s.dir),n=norm(s.name);
 if(c==='маникюр')return d==='ногтевой сервис'&&!/педикюр|стоп|пальчик/.test(n);
 if(c==='педикюр')return d==='ногтевой сервис'&&(/педикюр|стоп|пальчик/.test(n)||!/маникюр|наращивание|моделирование|укрепление/.test(n));
 if(/макияж|брови/.test(c))return d==='визаж'||d==='брови, ресницы';
 if(/массаж|спа/.test(c))return d==='массаж и спа';
 if(/парикмахер/.test(c))return d==='парикмахерский зал';
 return c===d;
}
function refresh(){
 if(pending)return pending;
 pending=fetch('https://myclio.ru/api/masters.php',{cache:'no-store'}).then(function(r){if(!r.ok)throw Error('catalog');return r.json();}).then(function(j){if(!j.ok||!Array.isArray(j.services)||!Array.isArray(j.masters))throw Error('catalog');data=j;return true;}).catch(function(){data={services:[],masters:[]};return false;}).finally(function(){pending=null;});return pending;
}
function rows(category,id){return data.services.filter(function(s){return matches(category,s)&&(!id||data.masters.some(function(m){return m.id===id&&m.services.some(function(x){return x.dir===s.dir&&x.name===s.name;});}));});}
function masters(category,row){return data.masters.filter(function(m){return m.services.some(function(s){return row?s.dir===row.dir&&s.name===row.name:matches(category,s);});});}
function selectRows(select,category,id,keep){select.replaceChildren(new Option('Уточнить при записи',''));rows(category,id).forEach(function(s){var o=new Option(s.name+(s.price?' · '+s.price:''),JSON.stringify([s.dir,s.name]));select.add(o);});if(keep&&Array.from(select.options).some(function(o){return o.value===keep;}))select.value=keep;}
function row(select){try{var a=JSON.parse(select.value);return {dir:a[0],name:a[1]};}catch(e){return null;}}
function fillMasters(select,category,detail,keep){select.replaceChildren(new Option(category==='Солярий'?'Без мастера':'Любой мастер',''));masters(category,detail).forEach(function(m){select.add(new Option(m.name,m.id));});if(keep&&Array.from(select.options).some(function(o){return o.value===keep;}))select.value=keep;}
function bind(category,detail,master){function paint(){var d=row(detail);fillMasters(master,category.value,d,master.value);}category.addEventListener('change',function(){master.value='';selectRows(detail,category.value,'','');paint();});detail.addEventListener('change',paint);master.addEventListener('change',function(){selectRows(detail,category.value,master.value,detail.value);});return function(){selectRows(detail,category.value,master.value,detail.value);paint();};}
g.KlioBooking={refresh:refresh,rows:rows,masters:masters,selectRows:selectRows,row:row,fillMasters:fillMasters,bind:bind};
})(window);
