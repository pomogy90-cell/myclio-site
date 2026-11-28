<?php
/* Synthetic data only. CLI regression tests for finance.php. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('KLIO_FINANCE_AUTH',true);
require __DIR__.'/../api/finance.php';
function cash_public($cash,$book){return ['opening_set'=>true,'balance'=>9000];}
function out($a,$c=200){throw new FinResponse($a,$c);}
class FinResponse extends Exception {public $data;public $status;function __construct($a,$c){$this->data=$a;$this->status=$c;}}
$n=0;$bad=0;
function check($name,$actual,$expected){global $n,$bad;$n++;if($actual==$expected){echo "PASS $name\n";}else{$bad++;echo "FAIL $name expected ".json_encode($expected)." actual ".json_encode($actual)."\n";}}
$s=fin_defaults();
$book=['items'=>[
 ['id'=>'b1','date'=>'2026-11-02','amount'=>5000,'reward'=>2000,'pay'=>'cash','dir'=>'Massage','spec'=>'A'],
 ['id'=>'b2','date'=>'2026-11-03','amount'=>3000,'reward'=>1000,'pay'=>'qr','dir'=>'Nails','spec'=>'B'],
 ['id'=>'b3','date'=>'2026-11-04','amount'=>4000,'reward'=>1500,'pay'=>'sbp','dir'=>'Nails','spec'=>'B'],
 ['id'=>'b4','date'=>'2026-11-05','amount'=>8000,'reward'=>3000,'pay'=>'term','dir'=>'Massage','spec'=>'A'],
 ['id'=>'b5','date'=>'2026-11-05','amount'=>9000,'reward'=>4000,'pay'=>'cash','state'=>'canceled'],
 ['id'=>'b6','date'=>'2026-11-05','amount'=>9000,'reward'=>4000,'pay'=>'cash','deleted'=>true],
 ['id'=>'b7','date'=>'2026-11-06','amount'=>9000,'pay'=>null],
 ['id'=>'b8','date'=>'2026-10-31','paid_at'=>strtotime('2026-11-07T10:00:00+03:00'),'amount'=>1000,'reward'=>0,'pay'=>'term']
]];
$cash=['payouts'=>[['id'=>'p1','t'=>strtotime('2026-11-07T10:00:00+03:00'),'cat'=>'cost','amount'=>500,'article'=>'Test expense'],['id'=>'p2','t'=>strtotime('2026-11-07T10:00:00+03:00'),'cat'=>'profit','amount'=>1000]],'deposits'=>[['id'=>'d1','t'=>strtotime('2026-11-07T10:00:00+03:00'),'amount'=>6000]]];
function op($id,$type,$a,$account,$cat,$to=''){return ['id'=>$id,'date'=>'2026-11-08','type'=>$type,'amount'=>$a,'account'=>$account,'category'=>$cat,'label'=>$id,'by'=>'test','to'=>$to];}
$s['ops']=[op('manual01','expense',200000,'bank','rent'),op('manual02','expense',100000,'bank','materials'),op('manual03','transfer',300000,'qr','transfer','bank'),op('manual04','tax_paid',30000,'bank','tax_paid'),op('manual05','investment',1000000,'bank','investment'),op('manual06','reward_paid',100000,'bank','reward_paid')];
function rule($id,$base,$value,$mode='percent',$start='2026-11-01',$active=true,$period='month'){return ['id'=>$id,'name'=>$id,'start'=>$start,'mode'=>$mode,'base'=>$base,'period'=>$period,'value'=>$value,'active'=>$active];}
$s['rules']=[rule('tax','all',600)];
$r=fin_report($s,$book,$cash,'2026-11-01','2026-11-30');$v=$r['summary'];
check('gross revenue includes all payment methods, excludes cancelled/deleted/unpaid', $v['income'],2100000);
check('cash full revenue', $v['pay']['cash'],500000);
check('QR', $v['pay']['qr'],300000);check('SBP', $v['pay']['sbp'],400000);check('terminal incl actual payment date', $v['pay']['term'],900000);
check('rewards counted once',$v['reward'],750000);check('materials',$v['direct'],100000);check('fixed',$v['fixed'],200000);check('cash costs',$v['variable'],50000);
check('expenses',$v['expenses'],1100000);check('gross profit',$v['gross'],1250000);check('before tax',$v['before_tax'],1000000);
check('tax 6 percent',$v['tax'],126000);check('net profit',$v['profit'],874000);check('margin',$v['margin'],41.62);
check('average CRM check',$v['average'],420000);check('visit count',$v['visits'],5);
check('owner deposits excluded from profit',$v['owner_in'],600000);check('owner draws excluded from costs',$v['owner_out'],100000);
check('paid tax separately',$v['tax_paid'],30000);check('investment separately',$v['investment'],1000000);check('reward payout not second expense',$v['reward_paid'],100000);
check('cash authoritative',$r['balances']['cash']['amount'],900000);check('QR transfer zero remainder',$r['balances']['qr']['amount'],0);check('bank transfer less expenses and payments',$r['balances']['bank']['amount'],-1130000);
check('month trend',$r['trend'][0]['profit'],874000);check('master A revenue',$v['by_master']['A']['income'],1300000);
foreach(['cash'=>30000,'noncash'=>96000,'profit'=>60000,'qr'=>18000,'sbp'=>24000,'term'=>54000] as $base=>$expected){$s['rules']=[rule('tax',$base,600)];[$v]=fin_calc($s,fin_project($s,$book,$cash),'2026-11-01','2026-11-30');check('tax base '.$base,$v['tax'],$expected);}
$s['rules']=[rule('tax','all',0)];[$v]=fin_calc($s,fin_project($s,$book,$cash),'2026-11-01','2026-11-30');check('zero rate',$v['tax'],0);
$s['rules']=[rule('tax','all',100000,'fixed')];[$v]=fin_calc($s,[],'2026-11-01','2026-12-31');check('fixed monthly twice',$v['tax'],200000);
$s['rules']=[rule('tax','all',100000,'fixed','2026-01-01',true,'quarter')];[$v]=fin_calc($s,[],'2026-01-01','2026-12-31');check('fixed quarterly four times',$v['tax'],400000);
$s['rules']=[rule('tax','all',100000,'fixed','2026-01-01',true,'year')];[$v]=fin_calc($s,[],'2026-01-01','2026-12-31');check('fixed annually once',$v['tax'],100000);
$s['rules']=[rule('tax','all',600),rule('tax','all',600,'percent','2026-11-04',false)];[$v]=fin_calc($s,fin_project($s,$book,$cash),'2026-11-01','2026-11-30');check('disable effective date',$v['tax'],48000);
[$v]=fin_calc(fin_defaults(),[],'2026-11-01','2026-11-30');check('zero income margin',$v['margin'],null);check('zero income break even',$v['break_even'],null);check('zero investment ROI',$v['roi'],null);check('zero visit average',$v['average'],null);
check('kopecks exact',fin_money('19.99'),1999);check('zero allowed',fin_money('0'),0);check('leap date',fin_date('2028-02-29'),true);check('invalid date',fin_date('2026-02-29'),false);
foreach(['-1','1.001','1e3','1,50'] as $x){try{fin_money($x);check('invalid money '.$x,false,true);}catch(InvalidArgumentException $e){check('invalid money '.$x,true,true);}}
$DATA=sys_get_temp_dir().'/klio-fin-'.bin2hex(random_bytes(6));mkdir($DATA);
$me=['name'=>'test'];
// Test storage directly to avoid manage.php's exit response contract.
fin_storage(true,function($s,$fp)use($me){fin_append($fp,'testop001','op',op('testop001','expense',12345,'bank','rent'),$me);});
$read=fin_storage(false,function($s){return $s;});
check('persistent append',$read['ops'][0]['amount'],12345);check('persistent op identity',isset($read['seen']['testop001']),true);
fin_storage(true,function($s,$fp)use($me){$p=op('testop002','expense',-12345,'bank','rent');$p['reversal_of']='testop001';fin_append($fp,'testop002','op',$p,$me);});
$read=fin_storage(false,function($s){return $s;});
check('original retained',count($read['ops']),2);check('storno net zero',fin_sum($read,fin_project($read,[],[]))['fixed'],0);
unlink($DATA.'/finance-events.jsonl');rmdir($DATA);
echo "$n checks; $bad failures\n";exit($bad?1:0);
