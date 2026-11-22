<?php
namespace DSA\Access;

use DSA\Discussion\Feedback_Access as App;
use DSA\Utilities\Origin_Checker;
use DSA\Discussion\Discussion_Settings;

if ( !defined('ABSPATH') ) exit;

/** Separate activity and configuration surfaces over the same native owners. */
final class Feedback_Workspace_Service {
	public function register(): void {
		if ( !App::enabled() ) return;
		add_action('admin_menu',[$this,'menu'],35);add_action('rest_api_init',[$this,'routes']);
	}
	public static function settings_allowed(): bool { return App::allowed() || current_user_can('manage_woocommerce'); }
	public function menu(): void {
		foreach ( ['kiwe-feedback'=>current_user_can('moderate_comments'),'kiwe-feedback-settings'=>$this->settings_allowed()] as $page=>$allowed ) {
			if(!$allowed)continue;
			$hook=add_submenu_page(null,'Feedback','Feedback','read',$page,[$this,'document']);add_action('load-'.$hook,[$this,'document'],1);
		}
	}
	public function document(): void {
		$settings=isset($_GET['page']) && 'kiwe-feedback-settings'===sanitize_key(wp_unslash($_GET['page']));
		if(!App::enabled() || ($settings?!$this->settings_allowed():!current_user_can('moderate_comments')))wp_die('Feedback is unavailable to this account.','',['response'=>403]);
		if($settings){wp_safe_redirect(App_Workspace_Service::url('settings','feedback'));exit;}
		Task_Workspace_UI::app_document('feedback','Feedback',['endpoint'=>rest_url('dsa/v1/workspace/feedback')]);
	}
	public function routes(): void {
		foreach(['feedback','feedback-settings']as$area)register_rest_route('dsa/v1','/workspace/'.$area,[['methods'=>'GET','callback'=>[$this,'read'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save'],'permission_callback'=>[$this,'permission']]]);
	}
	public function permission(\WP_REST_Request $request): bool {
		$settings=str_ends_with($request->get_route(),'/feedback-settings');
		return App::enabled() && is_user_logged_in() && !Team_Workspace_Service::suspended(get_current_user_id()) && (!is_multisite()||is_user_member_of_blog(get_current_user_id())) && ($settings?$this->settings_allowed():current_user_can('moderate_comments')) && in_array($request->get_method(),['GET','POST'],true) && (bool)wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest') && Origin_Checker::is_same_site_request() && ('GET'===$request->get_method()||true===Origin_Checker::mutation_allowed($request));
	}
	private function response($value): \WP_REST_Response { return is_wp_error($value)?Task_Workspace_UI::failure($value,(int)($value->get_error_data()['status']??400)):Task_Workspace_UI::response($value); }
	private function policy(): array {
		$reviews=null;
		if(\DSA\Apps\Store_App_Service::enabled()&&class_exists('\DSA\Commerce\Discussion_Review_Adapter')){$value=\DSA\Commerce\Discussion_Review_Adapter::policy();if(!is_wp_error($value))$reviews=$value;}
		return ['discussion'=>Discussion_Settings::read(),'reviews'=>$reviews];
	}
	public function read(\WP_REST_Request $request): \WP_REST_Response {
		if(!$this->permission($request))return $this->response(new \WP_Error('kiwe_feedback_denied','Feedback is unavailable.',['status'=>403]));
		if(str_ends_with($request->get_route(),'/feedback-settings'))return $this->response($this->policy());
		return $this->response((new Comment_Moderation_Service())->listing($request->get_query_params()));
	}
	public function save(\WP_REST_Request $request): \WP_REST_Response {
		if(!$this->permission($request))return $this->response(new \WP_Error('kiwe_feedback_denied','Feedback is unavailable.',['status'=>403]));
		$input=$request->get_json_params();if(!is_array($input)||!is_string($input['operation']??null))return $this->response(new \WP_Error('kiwe_feedback_input','Choose one feedback action.',['status'=>400]));
		$operation=$input['operation'];unset($input['operation']);
		if(str_ends_with($request->get_route(),'/feedback-settings')){
			if('discussion'===$operation)$result=Discussion_Settings::save($input);
			elseif('reviews'===$operation&&\DSA\Apps\Store_App_Service::enabled()&&class_exists('\DSA\Commerce\Discussion_Review_Adapter'))$result=\DSA\Commerce\Discussion_Review_Adapter::save_policy($input);
			else return $this->response(new \WP_Error('kiwe_feedback_input','Choose a setting available on this website.',['status'=>400]));
			return $this->response(is_wp_error($result)?$result:$this->policy());
		}
		$service=new Comment_Moderation_Service();
		$method=['status'=>'change_status','selection'=>'select','reply'=>'reply','reaction'=>'reaction','reports_reviewed'=>'resolve_reports'][$operation]??'';
		return $this->response($method?$service->{$method}($input):new \WP_Error('kiwe_feedback_input','Choose an activity action. Settings live in App settings → Feedback.',['status'=>400]));
	}
}
