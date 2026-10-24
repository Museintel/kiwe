<?php
/** Execute the native private projection without delivery, persistence or an order mutation. */
define('ABSPATH',__DIR__.'/../../');
function absint($v){return abs((int)$v);}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return (string)$v;}function sanitize_textarea_field($v){return (string)$v;}function esc_url_raw($v){return (string)$v;}function __($v,$domain=''){return $v;}
$lookups=[];$status='completed';
function wc_get_order($id){global $lookups,$status;$lookups[]=$id;return $id===41?new class($status){function __construct(private $state){}function get_status(){return $this->state;}}:false;}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php';
$class=new ReflectionClass(DSA\Notifications\Admin_Event_Notification_Service::class);$owner=$class->newInstanceWithoutConstructor();$projection=$class->getMethod('project_workspace_item');$checks=[];
$check=static function($ok,$label)use(&$checks){$checks[]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new RuntimeException($label);};
$item=['id'=>'order-41','type'=>'admin_new_order','createdAt'=>1,'objectId'=>41];
foreach(['completed','processing','cancelled','on-hold','custom-status']as$value){$status=$value;$out=$projection->invoke($owner,$item);$check($out['recordStatus']===$value,'Current native order state projected: '.$value);}
$status='completed';unset($item['objectId']);$check($projection->invoke($owner,$item)['recordStatus']==='completed','Legacy order notification resolves its original object ID');
$item['id']='order-42';$check($projection->invoke($owner,$item)['recordStatus']==='','Deleted/missing order does not invent success');
$before=count($lookups);$item['type']='admin_abandoned_cart';$check($projection->invoke($owner,$item)['recordStatus']===''&&count($lookups)===$before,'A non-order update does not read or infer order status');
echo json_encode(['passed'=>true,'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
