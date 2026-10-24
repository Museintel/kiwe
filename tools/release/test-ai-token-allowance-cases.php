<?php
// Included by test-ai-allowance.php: actual allowance/provider code, synthetic DB/time/HTTP only.
use DSA\AI\AI_Allowance_Service as Allowance;
use DSA\AI\AI_Provider_Service as Provider;
use DSA\AI\AI_Usage_Service as Usage;

$s=setup(['minute'=>60,'day'=>100,'month'=>100]);$s->ai['token_allowance']=['minute'=>1000,'day'=>2000,'month'=>3000];$a=new Allowance($s);
check('token status is lazy and marks no tracking before first request',$a->status()['tokenBudget']['startedAt']===0&&$wpdb->writes===0);
$r=$a->reserve(800);$ticket=$r['ticket'];
check('request and token units reserve in one atomic write',$r['ok']&&$wpdb->writes===1&&$a->status()['used']['minute']===1&&$a->status()['tokenBudget']['used']['minute']===800);
$before=$wpdb->raw;check('another consumer cannot spend reserved token units',(new Allowance($s))->reserve(300)['reason']==='site_ai_token_minute_limit'&&$wpdb->raw===$before);
check('known usage releases unused token units but never refunds requests',$a->settle($ticket,100)['settled']&&$a->status()['tokenBudget']['used']['minute']===100&&$a->status()['used']['minute']===1);
$before=$wpdb->raw;check('duplicate settlement is idempotent and cannot refund twice',!$a->settle($ticket,1)['settled']&&$wpdb->raw===$before);
check('released units allow another consumer to progress',$a->reserve(900)['ok']);
check('too-large request is immediately actionable rather than endlessly waiting',$a->reserve(1001)['reason']==='site_ai_token_request_too_large'&&$a->reserve(1001)['retryAt']===0);
$s->ai['native_api_key']='changed-synthetic';$s->ai['token_allowance']['month']=0;
check('key changes and lowered limits preserve token usage',$a->status()['tokenBudget']['used']['month']===1000&&$a->reserve(1)['reason']==='site_ai_token_request_too_large');

$s=setup(['minute'=>60,'day'=>100,'month'=>100]);$s->ai['token_allowance']=['minute'=>1000,'day'=>2000,'month'=>3000];$a=new Allowance($s);$b=new Allowance($s);
$wpdb->contender=static function()use($b){$b->reserve(700);};
check('simulated token insert contention rereads remaining balance',$a->reserve(700)['reason']==='site_ai_token_minute_limit'&&$a->status()['tokenBudget']['used']['minute']===700);
$pending=json_decode($wpdb->raw,true)['pending'];$ticket=array_key_first($pending);$wpdb->contender=static function()use($b,$ticket){$b->settle($ticket,100);};
check('competing settlement cannot double release units',!$a->settle($ticket,100)['settled']&&$a->status()['tokenBudget']['used']['minute']===100);
$r=$a->reserve(700);$wpdb->contender=static function()use($b){$b->reserve(100);};
check('settlement CAS preserves a concurrent new reservation',$a->settle($r['ticket'],200)['settled']&&$a->status()['tokenBudget']['used']['minute']===400);

$s=setup();$a=new Allowance($s);$r=$a->reserve(900);$before=$wpdb->raw;$wpdb->failWrite=true;
check('failed settlement write cannot release a reservation',!$a->settle($r['ticket'],100)['ok']&&$wpdb->raw===$before);$wpdb->failWrite=false;
check('unknown usage retains its entire reservation',$a->settle($r['ticket'],null)['charged']===900&&$a->status()['tokenBudget']['used']['minute']===900);
$r=$a->reserve(900);$clock+=301;$before=$wpdb->raw;
check('abandoned reservation expires without refund and without read-side writes',!$a->settle($r['ticket'],1)['settled']&&$a->status()['tokenBudget']['used']['day']===1800&&$wpdb->raw===$before);

$clock=strtotime('2026-10-31 23:59:30 UTC');$s=setup();$a=new Allowance($s);$r=$a->reserve(1000);$clock+=40;
check('month rollover does not move old reservation spend into new windows',$a->settle($r['ticket'],200)['settled']&&$a->status()['tokenBudget']['used']===['minute'=>0,'day'=>0,'month'=>0]);
$r=$a->reserve(800);$clock+=60;$a->reserve(600);
check('late settlement refunds only matching original windows',$a->settle($r['ticket'],100)['settled']&&$a->status()['tokenBudget']['used']===['minute'=>600,'day'=>700,'month'=>700]);

$s=setup();$a=new Allowance($s);$r=$a->reserve(500);
check('reported usage above estimate is charged and pauses all consumers',$a->settle($r['ticket'],900)['exceededReservation']&&$a->status()['tokenBudget']['used']['month']===900&&$a->reserve(10)['reason']==='site_ai_cooldown'&&$a->status()['retryAt']===$clock+3600);
$s=setup();$a=new Allowance($s);$r=$a->reserve(500);$a->cooldown('7200');$a->settle($r['ticket'],900);
check('usage overrun cannot shorten a longer provider cooldown',$a->status()['retryAt']===$clock+7200);

$s=setup(['minute'=>60,'day'=>1000,'month'=>1000]);$a=new Allowance($s);for($i=0;$i<60;$i++)$a->reserve(1);$clock+=60;for($i=0;$i<4;$i++)$a->reserve(1);
check('pending receipts are bounded and fail closed at capacity',$a->reserve(1)['reason']==='site_ai_pending_limit'&&count(json_decode($wpdb->raw,true)['pending'])===64&&strlen($wpdb->raw)<16384);
$clock+=301;check('expired receipts free bookkeeping slots while retaining token charge',$a->reserve(1)['ok']&&$a->status()['tokenBudget']['used']['month']===65&&count(json_decode($wpdb->raw,true)['pending'])===1);

$s=setup();$a=new Allowance($s);$legacy=['schema'=>1,'month'=>gmdate('Y-m',$clock),'day'=>gmdate('Y-m-d',$clock),'minute'=>intdiv($clock,60),'monthUsed'=>7,'dayUsed'=>3,'minuteUsed'=>1,'holdUntil'=>0,'updatedAt'=>$clock];$wpdb->raw=json_encode($legacy);$before=$wpdb->raw;
check('RC59/60 migration projects existing requests without inventing old token usage',$a->status()['used']['month']===7&&$a->status()['tokenBudget']['startedAt']===0&&$wpdb->raw===$before);
check('first token reservation migrates in the same CAS and preserves request counts',$a->reserve(100)['ok']&&$a->status()['used']['month']===8&&json_decode($wpdb->raw,true)['schema']===2&&$a->status()['tokenBudget']['startedAt']===$clock);
$s=setup();$a=new Allowance($s);$legacy['holdUntil']=$clock+600;$wpdb->raw=json_encode($legacy);$before=$wpdb->raw;
check('migration cannot remove an existing provider hold',$a->reserve(100)['reason']==='site_ai_cooldown'&&$wpdb->raw===$before);
$legacy['schema']=3;$wpdb->raw=json_encode($legacy);check('future unknown ledger schema fails closed',$a->reserve(100)['reason']==='site_ai_ledger_invalid');
$s=setup();$a=new Allowance($s);$r=$a->reserve(100);$broken=json_decode($wpdb->raw,true);$broken['tokens']['minute']=1;$wpdb->raw=json_encode($broken);
check('corrupt pending balance cannot create a negative refund',$a->settle($r['ticket'],1)['reason']==='site_ai_ledger_invalid');
$s=setup();$a=new Allowance($s);$r=$a->reserve(100);$before=$wpdb->raw;
check('forged unknown receipts never release units',!$a->settle(str_repeat('f',32),1)['settled']&&$wpdb->raw===$before);
check('malformed reservation and settlement inputs fail closed',!$a->reserve(-1)['ok']&&!$a->reserve(500001)['ok']&&!$a->settle($r['ticket'],0)['ok']&&!$a->settle('bad',1)['ok']);
check('token limits preserve missing fields and reject malformed values',Allowance::sanitize_token_limits(['day'=>[]],['minute'=>5,'day'=>6,'month'=>7])===['minute'=>5,'day'=>0,'month'=>7]);
check('explicit null token limit cannot restore a prior allowance',Allowance::sanitize_token_limits(['day'=>null],['minute'=>5,'day'=>6,'month'=>7])===['minute'=>5,'day'=>0,'month'=>7]);

$usage=['total_input_tokens'=>100,'total_output_tokens'=>40,'total_thought_tokens'=>60,'total_tokens'=>200,'total_cached_tokens'=>50];
check('Interactions counts reasoning and does not add cached input twice',Usage::reported('gemini','gemini-3.7-flash',['usage'=>$usage])===['input'=>100,'output'=>40,'thinking'=>60,'total'=>200]);
check('generateContent usage includes thinking',Usage::reported('gemini','gemini-2.5-flash',['usageMetadata'=>['promptTokenCount'=>20,'candidatesTokenCount'=>10,'thoughtsTokenCount'=>30,'totalTokenCount'=>60]])['total']===60);
check('missing reasoning on Interactions is unknown rather than a refund',Usage::reported('gemini','gemini-3.7-flash',['usage'=>array_diff_key($usage,['total_thought_tokens'=>0])])===null);
foreach(['100',-1,1.2,[]] as $value){$bad=$usage;$bad['total_input_tokens']=$value;check('invalid provider usage cannot refund: '.get_debug_type($value),Usage::reported('gemini','gemini-3.7-flash',['usage'=>$bad])===null);}
$bad=$usage;$bad['total_tokens']=100;check('internally inconsistent totals are unknown',Usage::reported('gemini','gemini-3.7-flash',['usage'=>$bad])===null);
check('unverified provider usage adapters retain estimates',Usage::reported('openai_compatible','synthetic',['usage'=>['total_tokens'=>1]])===null);
check('reservation includes serialized schema and UTF-8 bytes',Usage::estimate(['input'=>'hello','schema'=>['title'=>'संकेत']],800)>Usage::estimate(['input'=>'hello'],800)&&Usage::estimate(['input'=>'hello'],800)>8000);
check('unencodable and excessive payload estimates fail closed',Usage::estimate(['input'=>"\xB1\x31"],800)===0&&Usage::estimate(['input'=>str_repeat('x',250001)],800)===0);

$s=setup();$s->ai['token_allowance']=['minute'=>1,'day'=>100000,'month'=>100000];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('provider token rejection performs no HTTP or partial request charge',!$r['called']&&count($network)===0&&$wpdb->writes===0&&$r['reason']==='site_ai_token_request_too_large');
$s=setup();$responses=[['status'=>200,'body'=>json_encode(['usage'=>$usage,'steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>'Synthetic response']]]]])]];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('successful transport settles only reported units',$r['ok']&&$r['usage']['total']===200&&(new Allowance($s))->status()['tokenBudget']['used']['month']===200&&json_decode($wpdb->raw,true)['pending']===[]);
check('provider result exposes no reservation ticket',!str_contains(json_encode($r),'ticket'));
$s=setup();$responses=[['status'=>503,'body'=>json_encode(['usage'=>$usage])],['status'=>200,'body'=>json_encode(['usage'=>$usage,'steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>'Synthetic response']]]]])]];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);$estimate=Usage::estimate($network[0],800);
check('retry charges first failed estimate plus successful reported usage',$r['ok']&&(new Allowance($s))->status()['tokenBudget']['used']['month']===$estimate+200&&(new Allowance($s))->status()['used']['month']===2);
$s=setup();$s->ai['token_allowance']=['minute'=>15000,'day'=>100000,'month'=>100000];$responses=[['status'=>503]];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('retry must fit both request and token allowance',$r['called']&&$r['reason']==='site_ai_token_minute_limit'&&count($network)===1);
$s=setup();$responses=[new WP_Error('timeout','Synthetic timeout')];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('timeout retains token estimate with no dangling receipt',!$r['ok']&&(new Allowance($s))->status()['tokenBudget']['used']['month']===Usage::estimate($network[0],800)&&json_decode($wpdb->raw,true)['pending']===[]);
$s=setup();$responses=[['status'=>429,'headers'=>['retry-after'=>'120']]];$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('429 retains its token reservation before setting shared cooldown',$r['httpStatus']===429&&(new Allowance($s))->status()['tokenBudget']['used']['month']===Usage::estimate($network[0],800));
$s=setup();$s->ai['native_provider']='wordpress_ai_client';$wpAvailable=true;$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('opaque WordPress AI invocation keeps estimate and never claims reported use',$r['called']&&!$r['tokenAccounting']['reported']&&(new Allowance($s))->status()['tokenBudget']['used']['month']>8000);
$s=setup();$s->ai['native_provider']='wordpress_ai_client';$s->ai['token_allowance']=['minute'=>0,'day'=>100000,'month'=>100000];$before=$wpCalls;$r=(new Provider($s))->generate(['user'=>'Synthetic request']);
check('zero token limit also gates WordPress AI entry',!$r['called']&&$before===$wpCalls&&$wpdb->writes===0);
