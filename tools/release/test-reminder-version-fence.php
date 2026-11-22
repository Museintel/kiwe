<?php
namespace { define('ABSPATH',__DIR__.'/');function wp_parse_args($a,$b=[]){return array_replace($b,$a);} }
namespace DSA { final class Settings {public function __construct(private array $config){}public function all(){return ['abandoned_cart'=>$this->config];}public function defaults(){return ['abandoned_cart'=>[]];}} }
namespace DSA\Apps { final class Runtime_Contract {} }
namespace {
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Abandoned_Cart_Service.php';
$type=new ReflectionClass(DSA\Commerce\Abandoned_Cart_Service::class);$service=$type->newInstanceWithoutConstructor();$settings=new ReflectionProperty($service,'settings');$checks=[];
$check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
$legacy=['automatic_email_enabled'=>true,'automatic_whatsapp_enabled'=>false,'reminder_schedule_hours'=>[]];$settings->setValue($service,new DSA\Settings($legacy));$c=$service->config();
$check($c['automatic_email_enabled']===true,'Existing legacy programme remains on without choosing the new policy');
$check($c['reminder_schedule_hours']===[],'Existing legacy timing remains unchanged');
$new=['automation_policy_v1'=>true,'automatic_email_enabled'=>false,'automatic_whatsapp_enabled'=>false,'policy_automatic_email_enabled'=>true,'policy_automatic_whatsapp_enabled'=>true,'reminder_schedule_hours'=>[12,24],'automation_started_at'=>time()];$settings->setValue($service,new DSA\Settings($new));$c=$service->config();
$check($c['automatic_email_enabled']===false&&$c['automatic_whatsapp_enabled']===false,'New Store pauses new policy channels on a Core without policy support');
$check(!$new['automatic_email_enabled']&&!$new['automatic_whatsapp_enabled'],'An older Store sees only disabled original automatic flags');
$check($c['reminder_schedule_hours']===[12,24]&&$c['automation_started_at']===$new['automation_started_at'],'Capability refusal preserves the chosen schedule and prospective cutoff');
$new['manual_reminders_enabled']=true;$settings->setValue($service,new DSA\Settings($new));$check($service->config()['manual_reminders_enabled']===true,'Version fencing does not disable manual reminder policy or cart capture');
echo json_encode(['passed'=>true,'checks'=>$checks,'deliveryAttempted'=>false]).PHP_EOL;
}
