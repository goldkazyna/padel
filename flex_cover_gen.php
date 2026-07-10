<?php
// Генератор под ПОКРЫТИЕ: каждый игрок должен пересечься с каждым (партнёр ИЛИ соперник).
// Для раскладов с большим отдыхом (напр. 16 игроков / 2 корта), где всех в партнёры не успеть.

function pairKey(int $a,int $b):string{ return $a<$b?"$a-$b":"$b-$a"; }

function bestPartition(array $ids,array $pUsed,array $oUsed):array{
  if(count($ids)<4) return ['cost'=>0,'courts'=>[]];
  $first=$ids[0]; $rest=array_values(array_slice($ids,1)); $n=count($rest);
  $best=['cost'=>PHP_INT_MAX,'courts'=>[]];
  for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++)for($k=$j+1;$k<$n;$k++){
    $four=[$first,$rest[$i],$rest[$j],$rest[$k]];
    $splits=[[[$four[0],$four[1]],[$four[2],$four[3]]],[[$four[0],$four[2]],[$four[1],$four[3]]],[[$four[0],$four[3]],[$four[1],$four[2]]]];
    $bs=null;$bc=PHP_INT_MAX;
    foreach($splits as $s){ [$t1,$t2]=$s; $c=0;
      $c+=1000*($pUsed[pairKey($t1[0],$t1[1])]??0); $c+=1000*($pUsed[pairKey($t2[0],$t2[1])]??0);
      foreach([$t1[0],$t1[1]] as $a)foreach([$t2[0],$t2[1]] as $b){ $x=$oUsed[pairKey($a,$b)]??0; $c+=$x*$x; }
      if($c<$bc){$bc=$c;$bs=$s;} }
    $rem=[]; for($t=0;$t<$n;$t++) if($t!==$i&&$t!==$j&&$t!==$k)$rem[]=$rest[$t];
    $sub=bestPartition($rem,$pUsed,$oUsed); $tot=$bc+$sub['cost'];
    if($tot<$best['cost']) $best=['cost'=>$tot,'courts'=>array_merge([$bs],$sub['courts'])];
  }
  return $best;
}

function buildSchedule(int $N,int $C,int $R,int $restarts,int $seed):array{
  mt_srand($seed); $playPer=$C*4; $byePer=$N-$playPer;
  $bestSched=null;$bestCov=PHP_INT_MAX;
  for($r=0;$r<$restarts;$r++){
    $pUsed=[];$oUsed=[];$byeCount=array_fill(0,$N,0);$sched=[];
    for($round=0;$round<$R;$round++){
      $order=range(0,$N-1);
      usort($order,fn($a,$b)=>$byeCount[$a]!==$byeCount[$b]?$byeCount[$a]<=>$byeCount[$b]:mt_rand(-1,1));
      $byes=$byePer>0?array_slice($order,0,$byePer):[];
      $playing=array_values(array_diff(range(0,$N-1),$byes)); shuffle($playing);
      $part=bestPartition($playing,$pUsed,$oUsed);
      foreach($part['courts'] as $crt){ [$t1,$t2]=$crt;
        $pUsed[pairKey($t1[0],$t1[1])]=($pUsed[pairKey($t1[0],$t1[1])]??0)+1;
        $pUsed[pairKey($t2[0],$t2[1])]=($pUsed[pairKey($t2[0],$t2[1])]??0)+1;
        foreach([$t1[0],$t1[1]] as $a)foreach([$t2[0],$t2[1]] as $b) $oUsed[pairKey($a,$b)]=($oUsed[pairKey($a,$b)]??0)+1; }
      foreach($byes as $b)$byeCount[$b]++;
      $sched[]=['courts'=>$part['courts'],'byes'=>$byes];
    }
    $cov=uncovered($sched,$N);
    if($cov<$bestCov){$bestCov=$cov;$bestSched=$sched;}
    if($bestCov===0) break;
  }
  return ['schedule'=>$bestSched,'uncovered'=>$bestCov];
}

function uncovered(array $sched,int $N):int{
  $seen=[];
  foreach($sched as $rd) foreach($rd['courts'] as $crt){ [$t1,$t2]=$crt;
    $four=[$t1[0],$t1[1],$t2[0],$t2[1]];
    foreach($four as $a)foreach($four as $b) if($a<$b) $seen[pairKey($a,$b)]=1;
  }
  $total=$N*($N-1)/2; return $total-count($seen);
}

function toFlat(array $sched):array{ $f=[]; foreach($sched as $rd){ $p=[]; foreach($rd['courts'] as $c){[$t1,$t2]=$c;$p[]=$t1[0];$p[]=$t1[1];$p[]=$t2[0];$p[]=$t2[1];} $f[]=['play'=>$p,'byes'=>$rd['byes']]; } return $f; }
function flatToSched(array $flat,int $C):array{ $o=[]; foreach($flat as $rd){ $cs=[]; for($c=0;$c<$C;$c++)$cs[]=[[$rd['play'][4*$c],$rd['play'][4*$c+1]],[$rd['play'][4*$c+2],$rd['play'][4*$c+3]]]; $o[]=['courts'=>$cs,'byes'=>$rd['byes']]; } return $o; }

// энергия: покрытие(каждый с каждым) важнее всего, потом ровный отдых,
// потом повторы партнёров, потом баланс соперников
function energy(array $flat,int $N,int $C):array{
  $p=[];$o=[];$byeCount=array_fill(0,$N,0);
  foreach($flat as $rd){
    for($c=0;$c<$C;$c++){
      $a=$rd['play'][4*$c];$b=$rd['play'][4*$c+1];$d=$rd['play'][4*$c+2];$e=$rd['play'][4*$c+3];
      $p[pairKey($a,$b)]=($p[pairKey($a,$b)]??0)+1; $p[pairKey($d,$e)]=($p[pairKey($d,$e)]??0)+1;
      foreach([$a,$b] as $x)foreach([$d,$e] as $y) $o[pairKey($x,$y)]=($o[pairKey($x,$y)]??0)+1;
    }
    foreach($rd['byes'] as $z)$byeCount[$z]++;
  }
  $cov=[]; foreach($p as $k=>$v)$cov[$k]=1; foreach($o as $k=>$v)$cov[$k]=1;
  $unc=($N*($N-1)/2)-count($cov);
  $pr=array_sum(array_map(fn($c)=>max(0,$c-1),$p));
  $oppOver=array_sum(array_map(fn($c)=>max(0,$c-2),$o));
  $byeSpread=max($byeCount)-min($byeCount);
  $E=1000000*$unc + 50000*max(0,$byeSpread-1) + 3000*$pr + 20*$oppOver;
  return ['E'=>$E,'unc'=>$unc,'pr'=>$pr,'oppMax'=>$o?max($o):0,'partnerMax'=>$p?max($p):0,'byeSpread'=>$byeSpread];
}

function anneal(array $flat,int $N,int $C,int $iters,int $seed):array{
  mt_srand($seed); $R=count($flat);$playLen=4*$C;$byeLen=$N-$playLen;
  $cur=$flat;$ce=energy($cur,$N,$C);$best=$cur;$be=$ce;
  for($it=0;$it<$iters;$it++){
    $T=200.0*pow(0.02/200.0,$it/$iters); $r=mt_rand(0,$R-1);
    if($byeLen>0 && mt_rand(0,1)==0){
      // своп играющий <-> отдыхающий — позволяет свести непересёкшихся
      $pi=mt_rand(0,$playLen-1);$bi=mt_rand(0,$byeLen-1);
      $t=$cur[$r]['play'][$pi];$cur[$r]['play'][$pi]=$cur[$r]['byes'][$bi];$cur[$r]['byes'][$bi]=$t;
      $ne=energy($cur,$N,$C);
      if($ne['E']<=$ce['E']||mt_rand()/mt_getrandmax()<exp(($ce['E']-$ne['E'])/$T)){ $ce=$ne; if($ne['E']<$be['E']){$be=$ne;$best=$cur;} }
      else{ $t=$cur[$r]['play'][$pi];$cur[$r]['play'][$pi]=$cur[$r]['byes'][$bi];$cur[$r]['byes'][$bi]=$t; }
    } else {
      $i=mt_rand(0,$playLen-1);$j=mt_rand(0,$playLen-1); if($i==$j)continue;
      $t=$cur[$r]['play'][$i];$cur[$r]['play'][$i]=$cur[$r]['play'][$j];$cur[$r]['play'][$j]=$t;
      $ne=energy($cur,$N,$C);
      if($ne['E']<=$ce['E']||mt_rand()/mt_getrandmax()<exp(($ce['E']-$ne['E'])/$T)){ $ce=$ne; if($ne['E']<$be['E']){$be=$ne;$best=$cur;} }
      else{ $t=$cur[$r]['play'][$i];$cur[$r]['play'][$i]=$cur[$r]['play'][$j];$cur[$r]['play'][$j]=$t; }
    }
    if($be['unc']==0&&$be['pr']==0&&$be['oppOver']==0&&$be['byeSpread']<=1) break;
  }
  return ['flat'=>$best,'E'=>$be];
}

// === 16 игроков / 2 корта / 15 раундов ===
$N=16;$C=2;$R=15;
$bestFlat=null;$bestE=null;
foreach([12345,777,2024,99,555,31337,8,100500] as $sd){
  $base=buildSchedule($N,$C,$R,400,$sd);
  $imp=anneal(toFlat($base['schedule']),$N,$C,1500000,$sd);
  if($bestE===null||$imp['E']['E']<$bestE['E']){$bestE=$imp['E'];$bestFlat=$imp['flat'];}
  if($bestE['unc']==0&&$bestE['pr']==0) break;
}
$sched=flatToSched($bestFlat,$C);

// per-player покрытие
$partner=[];$opp=[];$games=array_fill(0,$N,0);$byes=array_fill(0,$N,0);
foreach($bestFlat as $rd){
  $play=$rd['play'];
  foreach($play as $x)$games[$x]++;
  foreach($rd['byes'] as $z)$byes[$z]++;
  for($c=0;$c<$C;$c++){ $a=$play[4*$c];$b=$play[4*$c+1];$d=$play[4*$c+2];$e=$play[4*$c+3];
    $partner[$a][$b]=1;$partner[$b][$a]=1;$partner[$d][$e]=1;$partner[$e][$d]=1;
    foreach([$a,$b] as $x)foreach([$d,$e] as $y){$opp[$x][$y]=1;$opp[$y][$x]=1;} }
}
echo "16 игроков / 2 корта / 15 раундов\n";
echo "Непокрытых пар (никогда не пересекались): {$bestE['unc']}\n";
echo "Повторов партнёров: {$bestE['pr']}, макс с одним соперником: {$bestE['oppMax']}\n\n";
for($pl=0;$pl<$N;$pl++){
  $np=count($partner[$pl]??[]); $no=count($opp[$pl]??[]);
  $both=array_unique(array_merge(array_keys($partner[$pl]??[]),array_keys($opp[$pl]??[])));
  printf("Слот %2d: игр:%d отдых:%d | партнёров(разных):%2d | соперников(разных):%2d | всего разных людей:%2d из %d\n",
    $pl,$games[$pl],$byes[$pl],$np,$no,count($both),$N-1);
}

// merge в JSON
$path=__DIR__.'/database/data/americano_flex_schedules.json';
$all=is_file($path)?(json_decode(file_get_contents($path),true)?:[]):[];
$all["16-2"]=['players'=>$N,'courts'=>$C,'rounds'=>$R,'partner_repeats'=>$bestE['pr'],'opp_max'=>$bestE['oppMax'],'uncovered'=>$bestE['unc'],'schedule'=>$sched];
file_put_contents($path,json_encode($all,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "\nСохранено 16-2 в $path\n";
