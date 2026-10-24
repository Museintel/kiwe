<?php
declare(strict_types=1);
define('ABSPATH',__DIR__);class WP_Error {public function __construct(...$args){}}
function is_wp_error($v){return$v instanceof WP_Error;}
function current_user_can(...$args){throw new RuntimeException('Disabled Woo must not ask capabilities');}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Order_Workspace_Service.php';
$s=new DSA\Access\Order_Workspace_Service();$n=0;
function prove($ok,$label){global$n;if(!$ok)throw new RuntimeException($label);$n++;}
prove($s->refund(1,[]) instanceof WP_Error,'non-Woo refunds stop before capability/database work');
$m=new ReflectionMethod($s,'refund_minor');$d=new ReflectionMethod($s,'refund_decimal');
foreach([['0',2,0],['1',2,100],['1.2',2,120],['0.01',2,1],['20.00',2,2000],['11',0,11],['0.000001',6,1],['999999999999.999999',6,999999999999999999]]as[$v,$p,$expected])prove($m->invoke($s,$v,$p)===$expected,'exact minor units '.$v);
foreach(['','-1','+1','1e3','01','1,2','.1','1.',' 1','1 ','NaN','INF','1.001','9999999999999','<b>1</b>']as$v)prove($m->invoke($s,$v,2)===null,'refuse financial coercion '.$v);
prove($m->invoke($s,1,2)===null,'numeric JSON amount refused');
prove($m->invoke($s,'1',7)===null,'unsupported precision refused');
prove($m->invoke($s,'0.1',0)===null,'zero-decimal currency rejects a fraction');
foreach([[1,2,'0.01'],[120,2,'1.20'],[100,0,'100'],[1,6,'0.000001']]as[$v,$p,$expected])prove($d->invoke($s,$v,$p)===$expected,'canonical decimal '.$expected);
echo json_encode(['passed'=>true,'checks'=>$n,'scope'=>'Disabled-feature and exact money behavior; native refund/gateway authority is tested separately'])."\n";
