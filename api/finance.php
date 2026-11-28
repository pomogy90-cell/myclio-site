<?php
/* Authenticated management accounting. All money is integer kopecks.
   Existing cash and booking data are read only by this module. */
if (!defined('KLIO_FINANCE_AUTH')) { http_response_code(403); exit; }
function fin_date($s){return is_string($s)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s)&&checkdate((int)substr($s,5,2),(int)substr($s,8,2),(int)substr($s,0,4));}
function fin_day($t){return (new DateTimeImmutable('@'.(int)$t))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');}
function fin_money($v){if(!is_scalar($v)||!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D',(string)$v))throw new InvalidArgumentException('Некорректная сумма.');$a=explode('.',(string)$v);return (int)$a[0]*100+(int)str_pad($a[1]??'',2,'0');}
function fin_text($v){if(!is_string($v)||trim($v)==='')throw new InvalidArgumentException('Заполните название или назначение.');return mb_substr(trim($v),0,300);}
function fin_defaults(){
 $a=[];foreach(['cash'=>'Касса салона','qr'=>'QR (расчётный канал)','sbp'=>'СБП (расчётный канал)','term'=>'Терминал (расчётный канал)','bank'=>'Расчётный счёт'] as $k=>$n)$a[$k]=['id'=>$k,'name'=>$n,'opening'=>0];
 $c=[];foreach(['sales'=>['Прочая выручка','income'],'materials'=>['Расходные материалы','direct'],'rent'=>['Аренда','fixed'],'utilities'=>['Коммунальные платежи','fixed'],'admin'=>['Административный персонал','fixed'],'ads'=>['Реклама','variable'],'fees'=>['Комиссии','variable'],'repair'=>['Ремонт оборудования','variable'],'other'=>['Прочие расходы','variable']] as $k=>$v)$c[$k]=['id'=>$k,'name'=>$v[0],'type'=>$v[1]];
 return ['accounts'=>$a,'categories'=>$c,'rules'=>[],'ops'=>[],'seen'=>[]];
}
function fin_read($fp){
 $s=fin_defaults();rewind($fp);
 while(($line=fgets($fp))!==false){
  if(trim($line)==='')continue;$e=json_decode($line,true);
  if(!is_array($e)||!isset($e['id'],$e['kind'],$e['p'])||isset($s['seen'][$e['id']]))throw new RuntimeException('Повреждение журнала.');
  $s['seen'][$e['id']]=true;$p=$e['p'];
  if($e['kind']==='account')$s['accounts'][$p['id']]=$p;
  elseif($e['kind']==='category')$s['categories'][$p['id']]=$p;
  elseif($e['kind']==='rule')$s['rules'][]=$p;
  elseif($e['kind']==='op')$s['ops'][]=$p+['id'=>$e['id'],'by'=>$e['by']];
  else throw new RuntimeException('Неизвестная запись.');
 }return $s;
}
function fin_storage($write,$fn){
 global $DATA;$path=$DATA.'/finance-events.jsonl';
 if(!$write&&!is_file($path))return $fn(fin_defaults(),null);
 if(!is_dir($DATA))throw new RuntimeException('Нет хранилища.');
 $fp=fopen($path,$write?'c+b':'rb');if(!$fp)throw new RuntimeException('Нет доступа.');
 try{if(!flock($fp,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Нет блокировки.');return $fn(fin_read($fp),$fp);}
 finally{flock($fp,LOCK_UN);fclose($fp);}
}
function fin_append($fp,$id,$kind,$p,$me){
 $line=json_encode(['id'=>$id,'kind'=>$kind,'p'=>$p,'by'=>$me['name'],'created'=>time()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
 fseek($fp,0,SEEK_END);$pos=ftell($fp);
 if(fwrite($fp,$line)!==strlen($line)||!fflush($fp)){ftruncate($fp,$pos);throw new RuntimeException('Ошибка записи.');}
 return ['ok'=>true,'id'=>$id];
}
function fin_row($id,$date,$type,$a,$account,$cat,$label,$source,$extra=[]){return ['id'=>$id,'date'=>$date,'type'=>$type,'amount'=>$a,'account'=>$account,'category'=>$cat,'label'=>$label,'source'=>$source]+$extra;}
function fin_project($s,$b,$cash){
 $rows=[];
 foreach(($b['items']??[]) as $it){
  if(!empty($it['deleted'])||!empty($it['state'])||!fin_date($it['date']??null))continue;
  $id=(string)$it['id'];$a=max(0,(int)($it['amount']??0))*100;$r=max(0,(int)($it['reward']??0))*100;$pay=$it['pay']??'';
  $at=(int)($it['paid_at']??0);if(!$at&&$pay==='cash')$at=(int)($it['cash_at']??0);
  $date=$at?fin_day($at):$it['date'];$x=['dir'=>(string)($it['dir']??'Без направления'),'master'=>(string)($it['spec']??''),'pay'=>$pay];
  if($a>0&&in_array($pay,['cash','qr','sbp','term'],true))$rows[]=fin_row('booking:'.$id.':income',$date,'income',$a,$pay,'service',(string)($it['service']??'Услуга'),'crm',$x);
  if($r>0)$rows[]=fin_row('booking:'.$id.':reward',$date,'expense',$r,$pay==='cash'?'cash':'','reward','Вознаграждение мастеру','crm',$x);
 }
 foreach(($cash['payouts']??[]) as $p)$rows[]=fin_row('cash:payout:'.($p['id']??''),fin_day($p['t']??0),($p['cat']??'cost')==='profit'?'owner_out':'expense',max(0,(int)($p['amount']??0))*100,'cash','cash_cost',(string)($p['article']??'Изъятие'),'cash');
 foreach(($cash['deposits']??[]) as $p)$rows[]=fin_row('cash:deposit:'.($p['id']??''),fin_day($p['t']??0),'owner_in',max(0,(int)($p['amount']??0))*100,'cash','capital',(string)($p['source']??'Пополнение'),'cash');
 foreach($s['ops'] as $p)$rows[]=fin_row($p['id'],$p['date'],$p['type'],$p['amount'],$p['account'],$p['category'],$p['label'],'manual',['to'=>$p['to']??'','by'=>$p['by'],'reversal_of'=>$p['reversal_of']??'']);
 return $rows;
}
function fin_sum($s,$rows){
 $o=['income'=>0,'reward'=>0,'direct'=>0,'fixed'=>0,'variable'=>0,'tax_paid'=>0,'owner_in'=>0,'owner_out'=>0,'investment'=>0,'reward_paid'=>0,'visits'=>0,'crm_income'=>0,'pay'=>['cash'=>0,'qr'=>0,'sbp'=>0,'term'=>0,'other'=>0],'by_dir'=>[],'by_master'=>[],'categories'=>[]];
 foreach($rows as $r){
  $a=$r['amount'];$type=$r['type'];$cat=$r['category'];
  if($type==='income'){$o['income']+=$a;$p=$r['pay']??$r['account'];if(!isset($o['pay'][$p]))$p='other';$o['pay'][$p]+=$a;if($r['source']==='crm'){$o['visits']++;$o['crm_income']+=$a;}}
  elseif($type==='expense'){$k=$cat==='reward'?'reward':($s['categories'][$cat]['type']??'variable');if(!in_array($k,['reward','direct','fixed','variable'],true))$k='variable';$o[$k]+=$a;$o['categories'][$cat]=($o['categories'][$cat]??0)+$a;}
  elseif(isset($o[$type])&&is_int($o[$type]))$o[$type]+=$a;
  foreach(['dir'=>'by_dir','master'=>'by_master'] as $f=>$t){$k=trim($r[$f]??'');if($k===''||!in_array($type,['income','expense'],true))continue;if(!isset($o[$t][$k]))$o[$t][$k]=['name'=>$k,'income'=>0,'cost'=>0];$o[$t][$k][$type==='income'?'income':'cost']+=$a;}
 }
 $o['expenses']=$o['reward']+$o['direct']+$o['fixed']+$o['variable'];$o['gross']=$o['income']-$o['reward']-$o['direct'];$o['before_tax']=$o['income']-$o['expenses'];return $o;
}
function fin_calc($s,$rows,$from,$to){
 $period=array_values(array_filter($rows,function($r)use($from,$to){return $r['date']>=$from&&$r['date']<=$to;}));
 $o=fin_sum($s,$period);$daily=[];foreach($period as $r)$daily[$r['date']][]=$r;$bases=[];
 $days=new DatePeriod(new DateTimeImmutable($from),new DateInterval('P1D'),(new DateTimeImmutable($to))->modify('+1 day'));
 foreach($days as $d){
  $date=$d->format('Y-m-d');$rules=[];foreach($s['rules'] as $p)if($p['start']<=$date&&(!isset($rules[$p['id']])||$rules[$p['id']]['start']<=$p['start']))$rules[$p['id']]=$p;
  $ds=fin_sum($s,$daily[$date]??[]);
  foreach($rules as $p){if(!$p['active'])continue;$key=$p['id'].'|'.$p['start'];if(!isset($bases[$key]))$bases[$key]=['p'=>$p,'base'=>0,'fixed'=>0];
   if($p['mode']==='fixed'){$m=(int)$d->format('n');if($d->format('j')==='1'&&($p['period']==='month'||($p['period']==='quarter'&&in_array($m,[1,4,7,10],true))||($p['period']==='year'&&$m===1)))$bases[$key]['fixed']+=$p['value'];}
   else{$v=$p['base']==='all'?$ds['income']:($p['base']==='profit'?$ds['before_tax']:($p['base']==='noncash'?$ds['income']-$ds['pay']['cash']:($ds['pay'][$p['base']]??0)));$bases[$key]['base']+=$v;}
  }
 }
 $o['tax']=0;$o['accruals']=[];foreach($bases as $b){$p=$b['p'];$a=$p['mode']==='fixed'?$b['fixed']:(int)round(max(0,$b['base'])*$p['value']/10000);$o['tax']+=$a;$o['accruals'][]=['name'=>$p['name'],'amount'=>$a,'base'=>$b['base']];}
 $o['profit']=$o['before_tax']-$o['tax'];$o['margin']=$o['income']?round(100*$o['profit']/$o['income'],2):null;$o['average']=$o['visits']?round($o['crm_income']/$o['visits']):null;
 $contribution=$o['income']-$o['reward']-$o['direct']-$o['variable'];$o['break_even']=$contribution>0&&$o['income']>0?round($o['fixed']*$o['income']/$contribution):null;
 $o['roi']=$o['investment']>0?round(100*$o['profit']/$o['investment'],2):null;
 return [$o,$period];
}
function fin_report($s,$b,$cash,$from,$to){
 $rows=fin_project($s,$b,$cash);[$sum,$period]=fin_calc($s,$rows,$from,$to);
 $bal=[];foreach($s['accounts'] as $k=>$a)$bal[$k]=['name'=>$a['name'],'amount'=>$a['opening']];
 foreach($rows as $r){if($r['date']>$to)continue;$k=$r['account'];$type=$r['type'];$a=$r['amount'];
  if($k!=='cash'&&isset($bal[$k])){if(in_array($type,['income','owner_in'],true))$bal[$k]['amount']+=$a;if(in_array($type,['expense','owner_out','investment','tax_paid','reward_paid','transfer'],true))$bal[$k]['amount']-=$a;}
  if($type==='transfer'&&isset($bal[$r['to']]))$bal[$r['to']]['amount']+=$a;
 }
 $cp=cash_public($cash,$b);$bal['cash']['amount']=!empty($cp['opening_set'])?(int)$cp['balance']*100:null;$bal['cash']['current_only']=true;
 $trend=[];$m=new DateTimeImmutable(substr($from,0,7).'-01');while($m->format('Y-m-d')<=$to){$a=max($from,$m->format('Y-m-d'));$z=min($to,$m->format('Y-m-t'));[$v]=fin_calc($s,$rows,$a,$z);$trend[]=['month'=>$m->format('Y-m'),'income'=>$v['income'],'expenses'=>$v['expenses']+$v['tax'],'profit'=>$v['profit']];$m=$m->modify('+1 month');}
 usort($period,function($a,$b){return strcmp($b['date'],$a['date'])?:strcmp($b['id'],$a['id']);});
 $rules=[];foreach($s['rules'] as $p)$rules[$p['id']]=$p;
 $reversed=[];foreach($s['ops'] as $p)if(!empty($p['reversal_of']))$reversed[]=$p['reversal_of'];
 return ['ok'=>true,'summary'=>$sum,'rows'=>$period,'balances'=>$bal,'accounts'=>array_values($s['accounts']),'categories'=>array_values($s['categories']),'rules'=>array_values($rules),'trend'=>$trend,'reversed'=>$reversed,'revision'=>count($s['seen'])];
}
function fin_handle($action,$body,$me,$method){
 try{
  if($action==='finance_report'&&$method==='GET'){
   $today=fin_day(time());$from=$_GET['from']??substr($today,0,7).'-01';$to=$_GET['to']??$today;
   if(!fin_date($from)||!fin_date($to)||$from>$to||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>731)throw new InvalidArgumentException('Некорректный период (максимум 2 года).');
   out(fin_storage(false,function($s)use($from,$to){return fin_report($s,book_load(),cash_load(),$from,$to);}));
  }
  if($method!=='POST')out(['ok'=>false,'err'=>'Метод не поддерживается.'],405);
  $id=$body['op']??'';if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$id))throw new InvalidArgumentException('Неверный ID операции.');
  $result=fin_storage(true,function($s,$fp)use($id,$body,$action,$me){
   if(isset($s['seen'][$id]))return ['ok'=>true,'duplicate'=>true];
   if($action==='finance_account')return fin_append($fp,$id,'account',['id'=>'acc_'.$id,'name'=>fin_text($body['name']??''),'opening'=>fin_money($body['opening']??'0')],$me);
   if($action==='finance_category'){$t=$body['type']??'';if(!in_array($t,['income','direct','fixed','variable'],true))throw new InvalidArgumentException('Неверный тип статьи.');return fin_append($fp,$id,'category',['id'=>'cat_'.$id,'name'=>fin_text($body['name']??''),'type'=>$t],$me);}
   if($action==='finance_rule'){
    $start=$body['start']??'';$mode=$body['mode']??'';$base=$body['base']??'all';$period=$body['period']??'month';$value=fin_money($body['value']??'0');
    if(!fin_date($start)||!in_array($mode,['percent','fixed'],true)||!in_array($base,['all','cash','qr','sbp','term','other','noncash','profit'],true)||!in_array($period,['month','quarter','year'],true)||($mode==='percent'&&$value>10000))throw new InvalidArgumentException('Проверьте параметры начисления.');
    $rid=$body['rule_id']??'';if($rid==='')$rid='rule_'.$id;else{$prev=null;foreach($s['rules'] as $p)if($p['id']===$rid)$prev=$p;if(!$prev||$start<=$prev['start']||$start<fin_day(time()))throw new InvalidArgumentException('Редакция должна действовать с новой даты, не задним числом.');}
    return fin_append($fp,$id,'rule',['id'=>$rid,'name'=>fin_text($body['name']??''),'start'=>$start,'mode'=>$mode,'base'=>$base,'period'=>$period,'value'=>$value,'active'=>!empty($body['active'])],$me);
   }
   if($action==='finance_reverse'){
    $target=$body['target']??'';$p=null;foreach($s['ops'] as $x){if(($x['reversal_of']??'')===$target)throw new InvalidArgumentException('Уже сторнировано.');if($x['id']===$target)$p=$x;}
    if(!$p||!empty($p['reversal_of']))throw new InvalidArgumentException('Сторно только ручной исходной операции.');
    $p['amount']=-$p['amount'];$p['date']=fin_day(time());$p['label']='Сторно: '.$p['label'];$p['reversal_of']=$target;unset($p['id'],$p['by']);return fin_append($fp,$id,'op',$p,$me);
   }
   if($action==='finance_add'){
    $t=$body['type']??'';$d=$body['date']??'';$k=$body['account']??'';$to=$body['to']??'';$cat=$body['category']??'';$a=fin_money($body['amount']??'');
    if(!fin_date($d)||$a<=0||!in_array($t,['income','expense','transfer','owner_in','owner_out','tax_paid','investment','reward_paid'],true))throw new InvalidArgumentException('Неверные дата, сумма или тип.');
    if(!isset($s['accounts'][$k])||$k==='cash')throw new InvalidArgumentException('Наличные операции проводятся в существующей кассе.');
    if($t==='transfer'&&(!isset($s['accounts'][$to])||$to==='cash'||$to===$k))throw new InvalidArgumentException('Выберите другой безналичный счёт.');
    if($t==='income'||$t==='expense'){if(!isset($s['categories'][$cat])||($t==='income')!==($s['categories'][$cat]['type']==='income'))throw new InvalidArgumentException('Выберите подходящую статью.');}else $cat=$t;
    return fin_append($fp,$id,'op',['date'=>$d,'type'=>$t,'amount'=>$a,'account'=>$k,'to'=>$t==='transfer'?$to:'','category'=>$cat,'label'=>fin_text($body['label']??'')],$me);
   }
   throw new InvalidArgumentException('Неизвестная операция.');
  });out($result);
 }catch(InvalidArgumentException $e){out(['ok'=>false,'err'=>$e->getMessage()],400);}catch(Throwable $e){error_log('Klio finance: '.$e->getMessage());out(['ok'=>false,'err'=>'Ошибка финансового хранилища.'],500);}
}
