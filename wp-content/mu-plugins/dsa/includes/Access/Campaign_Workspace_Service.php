<?php
namespace DSA\Access;

use DSA\Notifications\Notification_Preference_Service;
use DSA\Notifications\Notification_Campaign_Service;
use DSA\Notifications\New_Post_Notification_Service;
use DSA\Notifications\Campaign_Unsubscribe_Service;
use DSA\Notifications\Push_Service;
use DSA\Commerce\Abandoned_Cart_Service;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Utilities\Origin_Checker;
use DSA\Settings;

if ( ! defined('ABSPATH') ) exit;

/** Drafts and review around the existing notification consent and three-recipient queue. */
final class Campaign_Workspace_Service {
	public const OPTION='kiwe_campaign_drafts_v1';
	private const LOCK='kiwe_campaign_workspace_lock';
	private Notification_Preference_Service $preferences;
	private Notification_Campaign_Service $delivery;
	private ?New_Post_Notification_Service $posts;
	private ?Abandoned_Cart_Service $carts;
	public function __construct(Notification_Preference_Service $p,Notification_Campaign_Service $d,?New_Post_Notification_Service $posts=null,?Abandoned_Cart_Service $carts=null){$this->preferences=$p;$this->delivery=$d;$this->posts=$posts;$this->carts=$carts;}
	public function register():void{add_action('rest_api_init',[$this,'routes']);(new Campaign_Unsubscribe_Service($this->preferences))->register();add_action('kiwe_campaign_finished',[$this,'finished']);}
	public function permission(\WP_REST_Request $r):bool{return Team_App_Service::enabled()&&is_user_logged_in()&&current_user_can('kiwe_manage_notification_policy')&&User_Group_Service::allowed()&&in_array($r->get_method(),['GET','POST'],true)&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')&&Origin_Checker::is_same_site_request()&&('GET'===$r->get_method()||true===Origin_Checker::mutation_allowed($r));}
	public function routes():void{if(!Team_App_Service::enabled())return;register_rest_route('dsa/v1','/workspace/campaigns',[[ 'methods'=>'GET','callback'=>[$this,'read'],'permission_callback'=>[$this,'permission'] ],[ 'methods'=>'POST','callback'=>[$this,'write'],'permission_callback'=>[$this,'permission'] ]]);}
	private function error(string $message,int $status=400):\WP_Error{return new \WP_Error('kiwe_campaign_workspace',$message,['status'=>$status]);}
	private function response($value):\WP_REST_Response{return is_wp_error($value)?Task_Workspace_UI::failure($value,(int)($value->get_error_data()['status']??400)):Task_Workspace_UI::response($value);}
	private function store():array{$s=get_option(self::OPTION,[]);return is_array($s)?array_slice(array_values(array_filter($s,static fn($d)=>is_array($d)&&isset($d['id'],$d['copies'],$d['status']))),0,40):[];}
	private function proof(array $d):string{return hash_hmac('sha256',wp_json_encode($d),wp_salt('auth'));}
	private function project(array $d):array{return [...$d,'expected'=>$this->proof($d)];}
	private function find(string $id){foreach($this->store()as$d)if($d['id']===$id)return $d;return $this->error('This campaign no longer exists. Refresh the campaigns.',409);}
	private function locked(callable $work){if(!add_option(self::LOCK,time()+30,'',false)){if((int)get_option(self::LOCK,0)>time())return $this->error('Another campaign change is saving. Try again.',409);delete_option(self::LOCK);if(!add_option(self::LOCK,time()+30,'',false))return $this->error('Another campaign change is saving.',409);}try{return $work();}finally{delete_option(self::LOCK);}}
	public function catalog():array{
		$types=[['id'=>'newsletter','label'=>'Newsletter','topic'=>'newsletter','source'=>''],['id'=>'article','label'=>'News / article','topic'=>'new_post','source'=>'post']];
		if(\DSA\Apps\Store_App_Service::enabled()&&function_exists('wc_get_product')){$types[]=['id'=>'offer','label'=>'Store offer','topic'=>'new_offer','source'=>''];$types[]=['id'=>'product','label'=>'Product announcement','topic'=>'new_offer','source'=>'product'];}
		$channels=[];foreach(['email'=>'Email','sms'=>'SMS','whatsapp'=>'WhatsApp','app'=>'Browser / PWA']as$id=>$name)$channels[]=['id'=>$id,'label'=>$name,'ready'=>$this->delivery->channel_ready($id),'titleLimit'=>'app'===$id?80:160,'bodyLimit'=>['email'=>4000,'sms'=>480,'whatsapp'=>1100,'app'=>240][$id]];
		return ['types'=>$types,'channels'=>$channels,'enabled'=>$this->preferences->enabled(),'groups'=>(new User_Group_Service())->catalog(),'limit'=>500];
	}
	private function type(string $id){foreach($this->catalog()['types']as$t)if($t['id']===$id)return $t;return $this->error('This campaign type is unavailable. Refresh the campaign types.');}
	private function source(array $draft){
		$type=$this->type($draft['type']);if(is_wp_error($type))return $type;
		$id=(int)$draft['sourceId'];if(!$id)return $type['source']?$this->error('Choose a published article or product for this campaign.'):['url'=>home_url('/'),'categories'=>[]];
		$post=get_post($id);if(!$post||'publish'!==$post->post_status||!in_array($post->post_type,['post','product'],true)||($type['source']&&$post->post_type!==$type['source']))return $this->error('This source is no longer published. Choose another record.',409);
		return ['url'=>(string)get_permalink($id),'categories'=>'post'===$post->post_type?array_map('intval',wp_get_post_categories($id)):[]];
	}
	private function sources(string $type,string $search):array{
		$t=$this->type($type);if(is_wp_error($t)||!$t['source'])return [];
		$q=new \WP_Query(['post_type'=>$t['source'],'post_status'=>'publish','posts_per_page'=>20,'s'=>substr(sanitize_text_field($search),0,80),'orderby'=>['date'=>'DESC','ID'=>'DESC'],'no_found_rows'=>true]);$items=[];
		foreach($q->posts as$p)$items[]=['id'=>(int)$p->ID,'name'=>get_the_title($p),'url'=>(string)get_permalink($p),'excerpt'=>wp_html_excerpt(wp_strip_all_tags($p->post_excerpt?:$p->post_content),240,'…')];return $items;
	}
	public function read(\WP_REST_Request $r):\WP_REST_Response{
		if(!$this->permission($r))return $this->response($this->error('Campaigns are unavailable for this account.',403));$q=$r->get_query_params();
		if('sources'===($q['view']??''))return $this->response(['items'=>$this->sources(sanitize_key((string)($q['type']??'')),(string)($q['search']??''))]);
		if('automations'===($q['view']??''))return $this->response($this->automations());
		$id=is_string($q['id']??null)?$q['id']:'';if($id){$d=$this->find($id);return $this->response(is_wp_error($d)?$d:['draft'=>$this->project($d),'catalog'=>$this->catalog()]);}
		$items=$this->store();$search=strtolower(substr(sanitize_text_field((string)($q['search']??'')),0,80));$status=sanitize_key((string)($q['status']??''));$items=array_values(array_filter($items,static fn($d)=>(!$search||str_contains(strtolower($d['name']),$search))&&(!$status||$d['status']===$status)));$sort=$q['sort']??'changed_desc';usort($items,static fn($a,$b)=>'name_asc'===$sort?strnatcasecmp($a['name'],$b['name']):(('created_asc'===$sort?1:-1)*($a['updated']<=>$b['updated'])));
		return $this->response(['items'=>array_map([$this,'project'],$items),'catalog'=>$this->catalog(),'delivery'=>$this->delivery->status()]);
	}
	private static function length(string $s):int{return (int)preg_match_all('/./us',$s);}
	private function validate(array $input){
		if(array_diff(array_keys($input),['id','expected','name','type','sourceId','group','channels','copies','scheduledAt']))return $this->error('Choose the supported campaign fields.');
		$name=is_string($input['name']??null)?trim(sanitize_text_field($input['name'])):'';$type=is_string($input['type']??null)?$this->type($input['type']):$this->error('Choose a campaign type.');$group=is_string($input['group']??null)?(new User_Group_Service())->find($input['group']):$this->error('Choose a user group.');
		if(!$name||self::length($name)>100)return $this->error('Give the campaign a name up to 100 characters.');if(is_wp_error($type))return $type;if(is_wp_error($group))return $group;
		$channels=$input['channels']??null;if(!is_array($channels)||!count($channels)||count($channels)>4||count(array_unique($channels))!==count($channels)||array_diff($channels,['email','sms','whatsapp','app']))return $this->error('Choose 1 to 4 channels in delivery preference order.');
		$copies=$input['copies']??null;if(!is_array($copies)||array_diff(array_keys($copies),$channels))return $this->error('Write a version for each selected channel.');$clean=[];
		foreach($channels as$channel){$copy=$copies[$channel]??null;if(!is_array($copy)||array_diff(array_keys($copy),['title','body'])||!is_string($copy['title']??null)||!is_string($copy['body']??null))return $this->error('Complete the title and message for '.$channel.'.');$title=trim(sanitize_text_field($copy['title']));if('sms'===$channel&&!$title)$title=$name;$body=trim(sanitize_textarea_field($copy['body']));$limit=['email'=>4000,'sms'=>480,'whatsapp'=>1100,'app'=>240][$channel];if(!$title||!$body||self::length($title)>('app'===$channel?80:160)||self::length($body)>$limit)return $this->error('The '.$channel.' title or message is empty or too long.');$clean[$channel]=['title'=>$title,'body'=>$body];}
		$source=$input['sourceId']??0;if(!is_int($source)||$source<0)return $this->error('Choose an available published source.');$at=$input['scheduledAt']??0;if(!is_int($at)||$at<0||($at&&($at<time()+60||$at>time()+30*DAY_IN_SECONDS)))return $this->error('Schedule between one minute and 30 days from now, or send after review.');
		return ['name'=>$name,'type'=>$type['id'],'topic'=>$type['topic'],'sourceId'=>$source,'group'=>$group['id'],'channels'=>array_values($channels),'copies'=>$clean,'scheduledAt'=>$at];
	}
	public function save(array $input){return $this->locked(function()use($input){$value=$this->validate($input);if(is_wp_error($value))return $value;$rows=$this->store();$id=is_string($input['id']??null)?$input['id']:'';$before=$id?$this->find($id):null;
		if($id&&(is_wp_error($before)||'draft'!==$before['status']||!is_string($input['expected']??null)||!hash_equals($this->proof($before),$input['expected'])))return $this->error('This campaign changed or has already been queued. Reload it before editing.',409);
		if(!$id&&count($rows)>=40)return $this->error('This workspace stores 40 campaigns. Archive an old draft before creating another.');
		$d=[...$value,'id'=>$id?:'c-'.substr(str_replace('-','',wp_generate_uuid4()),0,16),'status'=>'draft','created'=>$before['created']??time(),'updated'=>time(),'author'=>get_current_user_id()];
		$source=$this->source($d);if(is_wp_error($source))return $source;if($id){foreach($rows as&$row)if($row['id']===$id)$row=$d;unset($row);}else array_unshift($rows,$d);update_option(self::OPTION,$rows,false);$after=$this->find($d['id']);if(!is_wp_error($after)&&$after===$d){if(!$id)do_action('kiwe_campaign_created',$d['id']);return $this->project($after);}return $this->error('The draft save could not be confirmed. Refresh before retrying.',409);
	});}
	private function accepts(int $id,string $channel,array $d,array $categories,array $push_ids):bool{
		if(Team_Workspace_Service::suspended($id)||!get_userdata($id)||!$this->preferences->user_accepts($id,$channel,$d['topic']))return false;
		if('new_post'===$d['topic']){$p=get_user_meta($id,'dsa_notification_preferences',true);if(!empty($p['postCategories'])&&!array_intersect($categories,array_map('intval',(array)$p['postCategories'])))return false;}
		if('app'===$channel){$p=get_user_meta($id,'dsa_notification_preferences',true);return 'granted'===($p['browserPermission']??'')&&in_array($id,$push_ids,true);}
		return ''!==PhoneKey_Bridge::campaign_contact($id,$channel);
	}
	/** Returns private targets only to the queue; the REST response exposes aggregate counts and copy previews. */
	public function plan(array $d){
		$source=$this->source($d);if(is_wp_error($source))return $source;$g=(new User_Group_Service())->find($d['group']);if(is_wp_error($g))return $g;$set=(new User_Group_Service())->candidates($d['group']);$push=(new Push_Service())->active_user_ids($set['ids']);
		$counts=array_fill_keys($d['channels'],0);$reasons=['noConsent'=>0,'unverified'=>0,'noDevice'=>0,'unavailable'=>0,'banned'=>0,'duplicate'=>0];$targets=[];$seen=[];$expiry=(int)(floor(time()/DAY_IN_SECONDS)+91)*DAY_IN_SECONDS;
		foreach($set['ids']as$id){if(Team_Workspace_Service::suspended($id)){$reasons['banned']++;continue;}$route='';$consent=false;$verified=false;$ready=false;
			foreach($d['channels']as$channel){$consent=$consent||$this->preferences->user_accepts($id,$channel,$d['topic']);$has=$this->accepts($id,$channel,$d,$source['categories'],$push);$verified=$verified||$has;if(!$has)continue;$ready=$ready||$this->delivery->channel_ready($channel);if($this->delivery->channel_ready($channel)){$route=$channel;break;}}
			if(!$route){$reasons[!$consent?'noConsent':(!$verified?'unverified':'unavailable')]++;continue;}
			$contact_proof='app'===$route?'':hash_hmac('sha256',$route.':'.PhoneKey_Bridge::campaign_contact($id,$route),wp_salt('auth'));$key='app'===$route?'u:'.$id:$contact_proof;if(isset($seen[$key])){$reasons['duplicate']++;continue;}$seen[$key]=true;$targets[]=['user_id'=>$id,'visitor_hash'=>'','channel'=>$route,'contactProof'=>$contact_proof];$counts[$route]++;
		}
		$anonymous=['hashes'=>[],'limited'=>false];if('app'===$d['group']&&in_array('app',$d['channels'],true)){$anonymous=$this->preferences->campaign_visitors($d['topic']);$active=(new Push_Service())->active_visitor_hashes($anonymous['hashes']);foreach($anonymous['hashes']as$hash){if(!$this->preferences->campaign_visitor_accepts($hash,$d['topic'],$source['categories'])){$reasons['noConsent']++;continue;}if(!in_array($hash,$active,true)){$reasons['noDevice']++;continue;}if(!$this->delivery->channel_ready('app')){$reasons['unavailable']++;continue;}$targets[]=['user_id'=>0,'visitor_hash'=>$hash,'channel'=>'app'];$counts['app']++;}}
		$limited=$set['limited']||$anonymous['limited']||count($targets)>500;$copy=[];foreach($d['channels']as$channel){$text=$this->render($d,['user_id'=>1,'visitor_hash'=>'','channel'=>$channel],$source['url'],$expiry);if(self::length($text['body'])>('email'===$channel?6000:1600))return $this->error('The final '.$channel.' message is too long after adding links. Shorten its copy.');$segments='sms'===$channel?self::sms_segments($text['body']):null;$text['body']=preg_replace('#https?://[^\s]+kiwe_unsubscribe=[^\s]+#','{personal unsubscribe link}',$text['body']);$copy[$channel]=[...$text,'segments'=>$segments];}
		$plan=['draft'=>$d,'group'=>$g,'targets'=>$targets,'url'=>$source['url'],'categories'=>$source['categories'],'expires'=>$expiry];
		$preview=['count'=>count($targets),'registered'=>array_sum(array_map(static fn($t)=>$t['user_id']?1:0,$targets)),'anonymous'=>array_sum(array_map(static fn($t)=>$t['user_id']?0:1,$targets)),'total'=>count($set['ids'])+count($anonymous['hashes']),'counts'=>$counts,'excluded'=>$reasons,'limited'=>$limited,'enabled'=>$this->preferences->enabled(),'copies'=>$copy,'expected'=>hash_hmac('sha256',wp_json_encode($plan),wp_salt('auth'))];
		return ['plan'=>$plan,'preview'=>$preview];
	}
	public static function sms_segments(string $body):array{
		$basic="@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";$extended="^{}\\[~]|€";$chars=preg_split('//u',$body,-1,PREG_SPLIT_NO_EMPTY);$units=0;$gsm=true;foreach((array)$chars as$ch){if(str_contains($basic,$ch))$units++;elseif(str_contains($extended,$ch))$units+=2;else{$gsm=false;break;}}if(!$gsm){$units=0;foreach((array)$chars as$ch)$units+=strlen($ch)>3?2:1;}$single=$gsm?160:70;$multi=$gsm?153:67;return ['encoding'=>$gsm?'GSM-7':'Unicode','units'=>(int)$units,'segments'=>$units<=$single?1:(int)ceil($units/$multi)];
	}
	public function render(array $d,array $target,string $url,int $expires):array{
		$channel=$target['channel'];$copy=$d['copies'][$channel];$stop=Campaign_Unsubscribe_Service::url((int)$target['user_id'],(string)$target['visitor_hash'],$d['topic'],$channel,$expires);$body='whatsapp'===$channel?$copy['title']."\n\n".$copy['body']:$copy['body'];
		if('app'!==$channel)$body.= "\n\n".$url."\n".('sms'===$channel?'Stop: ':'Unsubscribe: ').$stop;
		return ['title'=>$copy['title'],'body'=>$body,'url'=>$url];
	}
	public function write(\WP_REST_Request $r):\WP_REST_Response{
		if(!$this->permission($r))return $this->response($this->error('Campaigns are unavailable for this account.',403));$body=$r->get_json_params();if(!is_array($body))return $this->response($this->error('Choose a campaign task.'));$op=$body['operation']??'';
		if('save'===$op){unset($body['operation']);return $this->response($this->save($body));}
		if('automation'===$op)return $this->response($this->save_automation($body));
		if('resume'===$op)return $this->response($this->delivery->resume(is_string($body['jobId']??null)?$body['jobId']:''));
		if('cancel'===$op)return $this->response($this->delivery->cancel(is_string($body['jobId']??null)?$body['jobId']:'',is_string($body['expected']??null)?$body['expected']:''));
		$id=is_string($body['id']??null)?$body['id']:'';$d=$this->find($id);if(is_wp_error($d))return $this->response($d);if('draft'!==$d['status']||!is_string($body['expected']??null)||!hash_equals($this->proof($d),$body['expected']))return $this->response($this->error('This campaign changed. Reload before continuing.',409));
		if('archive'===$op)return $this->response($this->locked(function()use($id,$d){$fresh=$this->find($id);if(is_wp_error($fresh)||$fresh!==$d)return $this->error('The campaign changed. Refresh before archiving.',409);$rows=array_values(array_filter($this->store(),static fn($row)=>$row['id']!==$id));update_option(self::OPTION,$rows,false);return ['archived'=>true];}));
		if(!in_array($op,['preview','queue'],true))return $this->response($this->error('Choose a campaign task.'));$planned=$this->plan($d);if(is_wp_error($planned))return $this->response($planned);if('preview'===$op)return $this->response($planned['preview']);
		$p=$planned['preview'];if(true!==($body['confirmed']??null)||!is_string($body['audienceExpected']??null)||!hash_equals($p['expected'],$body['audienceExpected']))return $this->response($this->error('The campaign or reachable audience changed. Review it again before sending.',409));if(!$p['enabled']||!$p['count']||$p['limited'])return $this->response($this->error($p['limited']?'This audience exceeds the 500-target campaign limit. Refine the group. Nothing was queued.':'No opted-in verified account or enrolled browser can receive this campaign.',409));
		return $this->response($this->locked(function()use($d,$body){$current=$this->find($d['id']);if(is_wp_error($current)||$current!==$d)return $this->error('This campaign changed. Review it again.',409);$fresh=$this->plan($current);if(is_wp_error($fresh))return $fresh;if(!hash_equals($fresh['preview']['expected'],$body['audienceExpected']))return $this->error('The reachable audience changed. Review it again before sending.',409);$result=$this->delivery->start_plan($fresh['plan']);if(is_wp_error($result))return $result;$rows=$this->store();foreach($rows as&$row)if($row['id']===$d['id']){$row['status']='queued';$row['jobId']=$result['job']['id'];$row['updated']=time();}unset($row);update_option(self::OPTION,$rows,false);return [...$result,'draft'=>$this->project($this->find($d['id']))];}));
	}
	public function finished(array $job):void{if(empty($job['campaignId']))return;$this->locked(function()use($job){$rows=$this->store();foreach($rows as&$row)if($row['id']===$job['campaignId']&&($row['jobId']??'')===$job['id']){$row['status']=$job['status'];$row['receipt']=$job;$row['updated']=time();}unset($row);update_option(self::OPTION,$rows,false);return true;});}
public function automations():array {
		$state=['posts'=>$this->posts?['audience'=>$this->preferences->post_alert_summary(),'delivery'=>$this->posts->status()]:null,'cart'=>null];
		if(!$this->carts||!\DSA\Apps\Store_App_Service::enabled()||!current_user_can('edit_others_shop_orders'))return $state;
		$config=$this->carts->config();
		$fields=array_intersect_key($config,array_flip(['manual_reminders_enabled','automatic_email_enabled','automatic_whatsapp_enabled','inactivity_minutes','retention_days','cooldown_hours','max_reminders','email_subject','email_message','whatsapp_message']));
		foreach(['manual_reminders_enabled','automatic_email_enabled','automatic_whatsapp_enabled']as$key)$fields[$key]=!empty($fields[$key]);
		foreach(['inactivity_minutes','retention_days','cooldown_hours','max_reminders']as$key)$fields[$key]=(int)($fields[$key]??0);
		$cart=['fields'=>$fields,'schemaReady'=>$this->carts->ready(),'canEdit'=>current_user_can('kiwe_manage_context'),'ready'=>['email'=>$this->delivery->channel_ready('email'),'whatsapp'=>$this->delivery->channel_ready('whatsapp')]];
		$cart['expected']=hash_hmac('sha256',serialize([$config,$cart]),wp_salt('auth'));$state['cart']=$cart;return $state;
	}
	private function save_automation(array $body) {
		$cart=$this->automations()['cart'];
		if(!$cart||!$cart['canEdit'])return $this->error('A site manager with order access controls saved-cart automation.',403);
		if(array_diff(array_keys($body),['operation','fields','expected','confirmed'])||true!==($body['confirmed']??null)||!is_string($body['expected']??null)||!hash_equals($cart['expected'],$body['expected']))return $this->error('Review and confirm the current saved-cart settings before saving.',409);
		$f=$body['fields']??null;$keys=array_keys($cart['fields']);
		if(!is_array($f)||array_diff(array_keys($f),$keys)||array_diff($keys,array_keys($f)))return $this->error('Choose the supported saved-cart controls.');
		foreach(['manual_reminders_enabled','automatic_email_enabled','automatic_whatsapp_enabled']as$key)if(!is_bool($f[$key]))return $this->error('Choose the reminder switches.');
		if(($f['automatic_email_enabled']&&!$cart['ready']['email'])||($f['automatic_whatsapp_enabled']&&!$cart['ready']['whatsapp']))return $this->error('A selected channel needs provider setup before automation can be enabled.',409);
		foreach(['inactivity_minutes'=>[15,43200],'retention_days'=>[7,365],'cooldown_hours'=>[1,720],'max_reminders'=>[1,5]]as$key=>$range)if(!is_int($f[$key])||$f[$key]<$range[0]||$f[$key]>$range[1])return $this->error('Choose valid tracking, retention and reminder limits.');
		foreach(['email_subject'=>160,'email_message'=>4000,'whatsapp_message'=>1000]as$key=>$limit){if(!is_string($f[$key])||!trim($f[$key])||self::length($f[$key])>$limit)return $this->error('Complete the saved-cart title and messages within their limits.');$f[$key]='email_subject'===$key?sanitize_text_field($f[$key]):sanitize_textarea_field($f[$key]);}
		foreach(['email_message','whatsapp_message']as$key)if(!str_contains($f[$key],'{recovery_url}'))return $this->error('Keep {recovery_url} in each reminder so it can restore the saved cart.');
		$raw=get_option(DSA_OPTION_SETTINGS,null);$current=$this->automations()['cart'];
		if(!is_array($raw)||!$current||!hash_equals($current['expected'],$body['expected']))return $this->error('The saved-cart settings changed. Reload before saving.',409);
		if(!(new Settings())->compare_and_update_selected($raw,['abandoned_cart'=>$f]))return $this->error('The settings changed or their save is uncertain. Reload before another attempt.',409);
		if(!$this->carts->configure_runtime())return $this->error('Settings were saved, but tracking setup could not be confirmed. Reload and review before saving setup again.',409);
		$after=$this->automations();foreach($f as$key=>$value)if(($after['cart']['fields'][$key]??null)!==$value)return $this->error('The automation save could not be confirmed. Reload before another attempt.',409);
		return $after;
	}
}
