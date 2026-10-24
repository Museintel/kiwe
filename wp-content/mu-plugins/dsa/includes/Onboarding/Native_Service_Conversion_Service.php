<?php
namespace DSA\Onboarding;

use DSA\Backup\Archive_Cipher;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit, bounded plan migration. WordPress owns all resulting records and terms. */
final class Native_Service_Conversion_Service {
	public const OPTION = 'kiwe_service_conversion_v1';
	public const SCHEMA = 'kiwe.service-plan-recovery.v1';
	private const MAX_BYTES = 1048576;
	private const SOURCE = Native_Service_Source_Service::POST_TYPE;
	private const TAXONOMY = Native_Service_Source_Service::TAXONOMY;

	private function error( string $message, int $status = 409 ): \WP_Error { return new \WP_Error( 'kiwe_service_conversion', $message, [ 'status'=>$status ] ); }
	private function failure( \Throwable $error ): \WP_Error { return $this->error( $error instanceof Service_Conversion_Exception ? $error->getMessage() : 'The Service operation could not be confirmed. Reload the saved records before trying again.' ); }
	private static function fingerprint( $value ): string { return hash( 'sha256', wp_json_encode( $value ) ); }
	private static function valid_expected( string $value ): bool { return (bool) preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	/** Settings-only recovery cannot disconnect a separately protected native collection. */
	public static function assert_settings_profile( array $setting ): void {
		if ( ! get_option(self::OPTION,false) ) return;
		$current=get_option(Design_Context_Profile_Service::OPTION_PROFILE,[]);
		$next=!empty($setting['exists']) && is_array($setting['value'] ?? null) ? $setting['value'] : [];
		if ( ($next['services'] ?? []) !== ($current['services'] ?? []) ) throw new Service_Conversion_Exception('Services have retained conversion recovery. Review the native records before changing their source through a settings snapshot.');
	}
	private function allowed( bool $undo = false ): bool {
		if ( ! current_user_can( 'kiwe_manage_context' ) ) return false;
		try { $source=$this->profile()['services']['sourcePostType'] ?? ''; } catch ( \Throwable $error ) { return false; }
		// An unbound plan review uses existing context authority. Native authority
		// can be projected only after this exact source has been selected and registered.
		if ( ! $undo && ! $source && ! post_type_exists(self::SOURCE) && ! taxonomy_exists(self::TAXONOMY) ) return true;
		if ( $source !== self::SOURCE || ! post_type_exists(self::SOURCE) || ! taxonomy_exists(self::TAXONOMY) ) return false;
		foreach ( [ 'edit_kiwe_services','assign_kiwe_service_category','manage_kiwe_service_category', ...($undo ? [ 'delete_kiwe_services','delete_kiwe_service_category' ] : []) ] as $cap ) if ( ! current_user_can( $cap ) ) return false;
		return true;
	}
	private function profile(): array {
		$profile = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		if ( ! is_array( $profile ) ) throw new Service_Conversion_Exception( 'Review the saved Design Context before converting services.' );
		return $profile;
	}
	public function available(): bool {
		if ( ! $this->allowed() ) return false;
		try { $profile=$this->profile(); return empty( $profile['services']['sourcePostType'] ) && ! empty( $profile['services']['items'] ) && ! get_option( self::OPTION, false ); }
		catch ( \Throwable $error ) { return false; }
	}

	/** Pure mapping; unsupported fields cannot silently disappear during conversion. */
	public static function prepare_plans( array $services ): array {
		if ( ! empty( $services['sourcePostType'] ) || array_diff( array_keys( $services ), [ 'enabled','sourcePostType','useForNavigation','items' ] ) ) throw new Service_Conversion_Exception( 'Your designer must review this configured Service source first.' );
		$items=$services['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) < 1 || count( $items ) > 50 ) throw new Service_Conversion_Exception( 'Review between one and 50 Service plans in this conversion.' );
		if ( strlen( wp_json_encode( $services ) ) > self::MAX_BYTES / 2 ) throw new Service_Conversion_Exception( 'These Service plans exceed the safe conversion size.' );
		$plans=[]; $seen=[]; $slugs=[]; $terms=[];
		foreach ( $items as $index=>$item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), [ 'stableId','recordId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths','taxonomyPaths','meta' ] ) || ! empty( $item['recordId'] ) || ! empty( $item['parentId'] ) || ! empty( $item['taxonomyPaths'] ) || ! empty( $item['meta'] ) ) throw new Service_Conversion_Exception( 'A Service plan has connected records or extension fields. Ask your designer to review it first.' );
			$id=$item['stableId'] ?? ''; $title=$item['title'] ?? '';
			if ( ! is_string( $id ) || ! $id || strlen( $id ) > 80 || sanitize_key( $id ) !== $id || isset( $seen[$id] ) || ! is_string( $title ) || ! trim( $title ) || strlen( $title ) > 240 || sanitize_text_field( $title ) !== $title ) throw new Service_Conversion_Exception( 'Every Service plan needs its own saved identity and a valid name.' );
			$seen[$id]=true; $slug=sanitize_title( $title );
			if ( ! $slug || isset( $slugs[$slug] ) ) throw new Service_Conversion_Exception( 'Two Service plans would share an address. Give each plan a distinct name before converting.' );
			$slugs[$slug]=true;
			$plan=array_replace( [ 'recordId'=>0,'summary'=>'','description'=>'','status'=>'draft','imageId'=>0,'parentId'=>0,'menuOrder'=>$index,'categoryPaths'=>'','taxonomyPaths'=>[],'meta'=>[] ], $item );
			foreach ( [ 'summary'=>2000,'description'=>20000,'categoryPaths'=>3000 ] as $key=>$limit ) if ( ! is_string( $plan[$key] ) || strlen( $plan[$key] ) > $limit || ( $key==='description' ? wp_kses_post( $plan[$key] ) : sanitize_textarea_field( $plan[$key] ) ) !== $plan[$key] ) throw new Service_Conversion_Exception( 'A Service plan has unsupported or oversized text. Review it before converting.' );
			foreach ( [ 'imageId','parentId','menuOrder','recordId' ] as $key ) if ( ! is_int( $plan[$key] ) || $plan[$key] < 0 ) throw new Service_Conversion_Exception( 'A Service plan has an invalid image, parent or order.' );
			if ( ! in_array( $plan['status'], [ 'draft','pending','publish' ], true ) ) throw new Service_Conversion_Exception( 'A Service plan has an unsupported visibility.' );
			$paths=[];
			foreach ( preg_split( '/[\r\n,]+/', $plan['categoryPaths'], -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $path ) {
				$parts=preg_split( '/\s*>\s*/', trim( $path ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
				if ( ! $parts || count( $parts ) > 10 ) throw new Service_Conversion_Exception( 'Review Service category paths with at most ten levels.' );
				$prefix=[];
				foreach ( $parts as $part ) { if ( ! $part || strlen( $part ) > 200 || sanitize_text_field( $part ) !== $part || ! sanitize_title( $part ) ) throw new Service_Conversion_Exception( 'A Service category name needs review.' ); $prefix[]=$part; $terms[implode( ' > ', $prefix )]=true; }
				$paths[]=implode( ' > ', $parts );
			}
			$paths=array_values( array_unique( $paths ) ); sort( $paths, SORT_STRING );
			$plan['status']='draft'; $plan['categoryPaths']=implode( "\n", $paths ); $plan['taxonomyPaths']=[ self::TAXONOMY=>$plan['categoryPaths'] ];
			$plans[]=[ 'plan'=>$plan,'slug'=>$slug,'originalStatus'=>$item['status'] ?? 'draft','paths'=>$paths ];
		}
		if ( count( $terms ) > 200 ) throw new Service_Conversion_Exception( 'This conversion is limited to 200 category paths. Ask your designer to split the plan.' );
		return $plans;
	}
	private function rows( string $sql, int $limit ): array {
		global $wpdb; $rows=$wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' || count( $rows ) > $limit ) throw new Service_Conversion_Exception( 'The native Service records could not be read completely. Refresh before trying again.' );
		return $rows;
	}
	/** Direct native read includes revisions, extension metadata and foreign term use. */
	private function native_state(): array {
		global $wpdb;
		$posts=$this->rows( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type=%s OR (post_type='revision' AND post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type=%s)) ORDER BY ID LIMIT 201", self::SOURCE,self::SOURCE ),200 );
		$ids=array_map( 'intval',array_column( $posts,'ID' ) ); $list=$ids ? implode( ',',$ids ) : '0';
		$taxes=$this->rows( $wpdb->prepare( "SELECT tt.*,t.name,t.slug,t.term_group FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy=%s ORDER BY tt.term_taxonomy_id LIMIT 201",self::TAXONOMY ),200 );
		$termList=implode( ',',array_map( 'intval',array_column( $taxes,'term_id' ) ) ) ?: '0'; $taxList=implode( ',',array_map( 'intval',array_column( $taxes,'term_taxonomy_id' ) ) ) ?: '0';
		return [ 'posts'=>$posts,'postmeta'=>$this->rows( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($list) ORDER BY meta_id LIMIT 4001",4000 ),'terms'=>$taxes,'termmeta'=>$this->rows( "SELECT * FROM {$wpdb->termmeta} WHERE term_id IN ($termList) ORDER BY meta_id LIMIT 1001",1000 ),'relationships'=>$this->rows( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($list) OR term_taxonomy_id IN ($taxList) ORDER BY object_id,term_taxonomy_id LIMIT 2001",2000 ),'comments'=>$this->rows( "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ($list) ORDER BY comment_ID LIMIT 101",100 ) ];
	}
	private function image_proof( array $plans ): array {
		$out=[];
		foreach ( $plans as $row ) {
			$id=$row['plan']['imageId']; if ( ! $id ) continue;
			$post=get_post( $id );
			if ( ! $post || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post',$id ) ) throw new Service_Conversion_Exception( 'A planned image is unavailable to your account. Review the plan image before converting.' );
			$out[$id]=[ (array) $post,get_post_meta( $id,'_wp_attachment_metadata',true ),get_post_meta( $id,'_wp_attached_file',true ) ];
		}
		return $out;
	}
	private function public_conflicts(): void {
		if ( post_type_exists( self::SOURCE ) || taxonomy_exists( self::TAXONOMY ) || post_type_exists( 'service' ) || post_type_exists( 'services' ) ) throw new Service_Conversion_Exception( 'An existing Service source needs designer review; it will not be replaced.' );
		foreach ( get_post_types( [],'objects' ) as $object ) if ( is_array( $object->rewrite ?? null ) && in_array( trim( (string) ($object->rewrite['slug'] ?? ''),'/'),[ 'service','service-category' ],true ) ) throw new Service_Conversion_Exception( 'An existing website route already uses the Service address. Ask your designer to review it.' );
		foreach ( get_taxonomies( [],'objects' ) as $object ) if ( is_array($object->rewrite ?? null) && in_array(trim((string)($object->rewrite['slug'] ?? ''),'/'),[ 'service','service-category' ],true) ) throw new Service_Conversion_Exception('An existing category route already uses the Service address. Ask your designer to review it.');
		foreach ( [ 'service','service-category' ] as $path ) if ( get_page_by_path( $path,OBJECT,'page' ) ) throw new Service_Conversion_Exception( 'A website page already uses the Service address. Ask your designer to review it.' );
	}
	private function plan_state(): array {
		$profile=$this->profile(); $services=$profile['services'] ?? [];
		if ( ! is_array( $services ) || get_option( self::OPTION,false ) ) throw new Service_Conversion_Exception( 'This conversion is already active or unavailable. Reload the Services space.' );
		$plans=self::prepare_plans( $services ); $this->public_conflicts(); $native=$this->native_state();
		if ( array_filter( $native ) ) throw new Service_Conversion_Exception( 'Native Service records or categories already exist. Your designer must review them before conversion.' );
		return [ 'services'=>$services,'plans'=>$plans,'images'=>$this->image_proof( $plans ) ];
	}
	public function preview() {
		if ( ! $this->allowed() ) return $this->error( 'Your role cannot convert Service plans.',403 );
		try {
			$state=$this->plan_state(); $items=[];
			foreach ( $state['plans'] as $row ) { $p=$row['plan']; $items[]=[ 'title'=>$p['title'],'summary'=>$p['summary'],'description'=>$p['description'],'imageId'=>$p['imageId'],'imageUrl'=>$p['imageId'] ? (string) wp_get_attachment_image_url( $p['imageId'],'medium' ) : '','menuOrder'=>$p['menuOrder'],'categories'=>$row['paths'],'originalStatus'=>$row['originalStatus'],'status'=>'draft','suggestedUrl'=>home_url( '/service/'.$row['slug'].'/' ) ]; }
			return [ 'mode'=>'convert','expected'=>self::fingerprint( $state ),'items'=>$items,'count'=>count( $items ),'navigationEnabled'=>! empty( $state['services']['useForNavigation'] ) ];
		} catch ( \Throwable $error ) { return $this->failure( $error ); }
	}
	private function forget_options(): void {
		foreach ( [ Design_Context_Profile_Service::OPTION_PROFILE,self::OPTION,'rewrite_rules','alloptions','notoptions' ] as $key ) wp_cache_delete( $key,'options' );
	}
	private function begin(): bool {
		global $wpdb;
		$names=[ $wpdb->posts,$wpdb->postmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->termmeta,$wpdb->options,$wpdb->comments,$wpdb->commentmeta ];
		$rows=$this->rows( $wpdb->prepare( 'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode( ',',array_fill(0,count($names),'%s') ).')',...$names ),count($names) );
		if ( count( $rows ) !== count( $names ) || (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ) !== '0' ) return false;
		foreach ( $rows as $row ) if ( strtoupper( (string) $row['ENGINE'] ) !== 'INNODB' ) return false;
		return $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) !== false && $wpdb->query( 'START TRANSACTION' ) !== false;
	}
	private function lock_rows(): void {
		global $wpdb;
		$this->rows( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s,%s) FOR UPDATE",Design_Context_Profile_Service::OPTION_PROFILE,self::OPTION ),2 );
		$this->rows( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s FOR UPDATE",self::SOURCE ),50 );
		$this->forget_options();
	}
	private function cleanup_caches( array $ids, array $terms ): void {
		$this->forget_options();
		foreach ( array_unique( $ids ) as $id ) { clean_post_cache( $id ); wp_cache_delete( $id,'post_meta' ); clean_object_term_cache( $id,self::SOURCE ); }
		if ( $terms ) clean_term_cache( array_unique( $terms ),self::TAXONOMY );
		foreach ( array_unique( $terms ) as $id ) wp_cache_delete( $id,'term_meta' );
		if ( isset( $GLOBALS['wp_rewrite'] ) ) $GLOBALS['wp_rewrite']->rules=get_option( 'rewrite_rules',[] );
	}
	/** Known native references outside this migration block destructive undo. */
	private function references( array $ids ): array {
		global $wpdb; $list=implode( ',',array_map( 'intval',$ids ) ) ?: '0';
		return $this->rows( $wpdb->prepare( "SELECT DISTINCT p.ID,p.post_type,p.post_parent,p.post_content FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.ID NOT IN ($list) AND p.post_type<>'revision' AND (p.post_parent IN ($list) OR m.meta_value=%s OR (m.meta_key='_menu_item_object_id' AND m.meta_value IN ($list))) ORDER BY p.ID LIMIT 201",self::SOURCE ),200 );
	}
	private function confirm_mapping( array $plans, array $ids ): void {
		$profile=new Design_Context_Profile_Service();
		foreach ( $plans as $index=>$row ) {
			$p=$row['plan']; $post=get_post( $ids[$index] );
			if ( ! $post || $post->post_type !== self::SOURCE || $post->post_status !== 'draft' || $post->post_title !== $p['title'] || $post->post_excerpt !== $p['summary'] || $post->post_content !== $p['description'] || (int) $post->post_author !== get_current_user_id() || (int) $post->post_parent !== 0 || (int) $post->menu_order !== $p['menuOrder'] || (int) get_post_thumbnail_id( $post->ID ) !== $p['imageId'] ) throw new Service_Conversion_Exception( 'The complete native Service draft could not be confirmed. No conversion was kept.' );
			$actual=$profile->service_catalog( [ 'id'=>$post->ID ] )['items'][0] ?? [];
			$paths=preg_split( '/\r?\n/',trim( $actual['taxonomyPaths'][self::TAXONOMY] ?? '' ),-1,PREG_SPLIT_NO_EMPTY ) ?: []; sort( $paths,SORT_STRING );
			if ( $paths !== $row['paths'] ) throw new Service_Conversion_Exception( 'The complete Service categories could not be confirmed. No conversion was kept.' );
		}
	}
	public function convert( string $expected ) {
		if ( ! $this->allowed() ) return $this->error( 'Your role cannot convert Service plans.',403 );
		return $this->change( false,$expected );
	}
	private function recovery(): array {
		$state=get_option( self::OPTION,false );
		if ( ! is_array( $state ) || ($state['schema'] ?? '') !== self::SCHEMA || ! is_array( $state['ids'] ?? null ) || ! array_is_list( $state['ids'] ) || count( $state['ids'] ) < 1 || count( $state['ids'] ) > 50 || ! is_string( $state['before'] ?? null ) || strlen( $state['before'] ) > self::MAX_BYTES ) throw new Service_Conversion_Exception( 'The original Service plans are unavailable. Ask your designer to review recovery.' );
		foreach ( $state['ids'] as $id ) if ( ! is_int( $id ) || $id < 1 ) throw new Service_Conversion_Exception( 'The Service recovery identity is invalid.' );
		foreach ( ['servicesHash','nativeHash'] as $key ) if ( ! is_string($state[$key] ?? null) || ! self::valid_expected($state[$key]) ) throw new Service_Conversion_Exception('The Service recovery proof is invalid.');
		if ( ! is_string($state['createdAt'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$state['createdAt']) || ! is_int($state['actorId'] ?? null) || $state['actorId'] < 1 ) throw new Service_Conversion_Exception('The Service recovery identity is invalid.');
		if ( count( array_unique($state['ids']) ) !== count($state['ids']) ) throw new Service_Conversion_Exception( 'The Service recovery identity is invalid.' );
		return $state;
	}
	private function undo_state(): array {
		$state=$this->recovery(); $profile=$this->profile(); $native=$this->native_state();
		if ( ! hash_equals( (string) ($state['servicesHash'] ?? ''),self::fingerprint( $profile['services'] ?? [] ) ) || ! hash_equals( (string) ($state['nativeHash'] ?? ''),self::fingerprint( $native ) ) || $this->references( $state['ids'] ) || $native['comments'] ) throw new Service_Conversion_Exception( 'Services, categories, versions or linked content changed after conversion. Keep the native records; your designer can review the retained original plans.' );
		foreach ( $state['ids'] as $id ) if ( get_post_status( $id ) !== 'draft' || ! current_user_can( 'delete_post',$id ) ) throw new Service_Conversion_Exception( 'Only unchanged drafts that your role can delete can be undone.' );
		return [ 'recovery'=>$state,'native'=>$native ];
	}
	public function undo_summary(): ?array {
		if ( ! $this->allowed( true ) || ! get_option( self::OPTION,false ) ) return null;
		try { $state=$this->undo_state(); return [ 'available'=>true,'count'=>count($state['recovery']['ids']),'createdAt'=>$state['recovery']['createdAt'] ]; }
		catch ( \Throwable $error ) { return [ 'available'=>false,'reason'=>$this->failure($error)->get_error_message() ]; }
	}
	public function undo_preview() {
		if ( ! $this->allowed( true ) ) return $this->error( 'Your role cannot undo this Service conversion.',403 );
		try {
			$recovery=$this->recovery(); $before=Archive_Cipher::open($recovery['before'],self::SCHEMA,wp_salt('auth'),self::MAX_BYTES); $plans=self::prepare_plans($before['services'] ?? []);
			$expected=''; $reason='';
			try { $expected=self::fingerprint($this->undo_state()); } catch ( \Throwable $error ) { $reason=$this->failure($error)->get_error_message(); }
			return [ 'mode'=>'undo','canUndo'=>$expected!=='','expected'=>$expected,'reason'=>$reason,'count'=>count($plans),'items'=>array_map(static fn($row)=>[ 'title'=>$row['plan']['title'],'summary'=>$row['plan']['summary'],'description'=>$row['plan']['description'],'categories'=>$row['paths'],'status'=>$row['originalStatus'] ],$plans) ];
		}
		catch ( \Throwable $error ) { return $this->failure( $error ); }
	}
	public function undo( string $expected ) {
		if ( ! $this->allowed( true ) ) return $this->error( 'Your role cannot undo this Service conversion.',403 );
		return $this->change( true,$expected );
	}
	private function change( bool $undo, string $expected ) {
		if ( ! self::valid_expected($expected) ) return $this->error( 'Open the current conversion review before continuing.',400 );
		global $wpdb; $lock='kiwe-service-conversion-'.substr(hash('sha256',$wpdb->prefix),0,32);
		if ( (string) $wpdb->get_var( $wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock) ) !== '1' ) return $this->error( 'Another Service conversion is running. Refresh the saved records before trying again.' );
		$transaction=false; $registered=false; $unregistered=false; $ids=[]; $terms=[];
		try {
			$state=$undo ? $this->undo_state() : $this->plan_state();
			if ( ! hash_equals(self::fingerprint($state),$expected) ) return $this->error( 'The Service plans or recovery changed. Reopen the review before continuing.' );
			$transaction=$this->begin(); if ( ! $transaction ) return $this->error( 'Safe Service conversion needs native InnoDB tables and an available transaction.' );
			$this->lock_rows(); $fresh=$undo ? $this->undo_state() : $this->plan_state();
			if ( ! hash_equals(self::fingerprint($fresh),$expected) ) throw new Service_Conversion_Exception( 'Service data changed before saving. Reopen the review.' );
			$profile=$this->profile(); $original=$profile; unset($original['services']);
			if ( $undo ) {
				$before=Archive_Cipher::open($state['recovery']['before'],self::SCHEMA,wp_salt('auth'),self::MAX_BYTES); self::prepare_plans($before['services'] ?? []);
				$ids=array_map('intval',array_column($state['native']['posts'],'ID')); $terms=array_map('intval',array_column($state['native']['terms'],'term_id'));
				foreach ( $state['recovery']['ids'] as $id ) if ( ! wp_delete_post($id,true) ) throw new Service_Conversion_Exception( 'The converted draft could not be removed safely.' );
				foreach ( array_reverse($terms) as $id ) if ( ! wp_delete_term($id,self::TAXONOMY) ) throw new Service_Conversion_Exception( 'The converted category could not be removed safely.' );
				$profile['services']=$before['services']; update_option(Design_Context_Profile_Service::OPTION_PROFILE,$profile,false); delete_option(self::OPTION);
				$this->forget_options(); if ( $this->profile() !== $profile || get_option(self::OPTION,false) || array_filter($this->native_state()) ) throw new Service_Conversion_Exception( 'The original plans could not be confirmed. Native Services were retained.' );
				unregister_post_type(self::SOURCE); unregister_taxonomy(self::TAXONOMY); $unregistered=true;
			} else {
				$before=Archive_Cipher::seal([ 'services'=>$state['services'] ],self::SCHEMA,wp_salt('auth'),[],self::MAX_BYTES);
				$registered=true; if ( ! (new Native_Service_Source_Service())->register_types() ) throw new Service_Conversion_Exception( 'The native Service source could not be registered.' );
				$profile['services']=[ 'enabled'=>true,'sourcePostType'=>self::SOURCE,'useForNavigation'=>! empty($state['services']['useForNavigation']),'items'=>[] ]; update_option(Design_Context_Profile_Service::OPTION_PROFILE,$profile,false);
				if ( ! $this->allowed() ) throw new Service_Conversion_Exception( 'Your role cannot create records in the selected native Service source. No conversion was kept.' );
				foreach ( $state['plans'] as $row ) { $saved=(new Design_Context_Profile_Service())->save_service_record(self::SOURCE,$row['plan'],get_current_user_id()); $id=(int) ($saved['recordId'] ?? 0); if ( ! $id ) throw new Service_Conversion_Exception( 'A native Service draft could not be created. No conversion was kept.' ); $ids[]=$id; }
				$this->confirm_mapping($state['plans'],$ids); $native=$this->native_state(); $terms=array_map('intval',array_column($native['terms'],'term_id'));
				if ( $native['comments'] || $this->references($ids) || array_map('intval',array_column(array_values(array_filter($native['posts'],static fn($p)=>$p['post_type']===self::SOURCE)),'ID')) !== $ids ) throw new Service_Conversion_Exception( 'Unexpected native Service activity occurred. No conversion was kept.' );
				$after=$this->profile(); $unrelated=$after; unset($unrelated['services']); if ( $unrelated !== $original || ($after['services'] ?? []) !== $profile['services'] ) throw new Service_Conversion_Exception( 'Service setup changed during conversion. No conversion was kept.' );
				$recovery=[ 'schema'=>self::SCHEMA,'createdAt'=>gmdate('c'),'actorId'=>get_current_user_id(),'ids'=>$ids,'servicesHash'=>self::fingerprint($after['services']),'nativeHash'=>self::fingerprint($native),'before'=>$before ]; update_option(self::OPTION,$recovery,false);
				$this->forget_options(); if ( get_option(self::OPTION,false) !== $recovery ) throw new Service_Conversion_Exception( 'The recovery copy could not be confirmed. No conversion was kept.' );
			}
			flush_rewrite_rules(false); // Exactly one explicit source transition; inside the native transaction.
			$this->forget_options();
			if ( $undo ) {
				if ( $this->profile() !== $profile || get_option(self::OPTION,false) || array_filter($this->native_state()) ) throw new Service_Conversion_Exception('Native data changed during recovery. The original conversion was retained.');
			} else {
				$this->confirm_mapping($state['plans'],$ids);
				if ( get_option(self::OPTION,false) !== $recovery || $this->profile() !== $profile || self::fingerprint($this->native_state()) !== $recovery['nativeHash'] || $this->references($ids) ) throw new Service_Conversion_Exception('Native data changed during conversion. No conversion was kept.');
			}
			if ( (string) $wpdb->get_var('SELECT @@session.in_transaction') !== '1' ) throw new Service_Conversion_Exception('A native extension ended the safe transaction. Your designer must review the saved records.');
			if ( $wpdb->last_error !== '' || $wpdb->query('COMMIT') === false ) throw new Service_Conversion_Exception( 'The native conversion could not be committed. Reload the saved records.' );
			$transaction=false; return [ 'changed'=>true,'mode'=>$undo ? 'undo' : 'convert','count'=>$undo ? count($state['recovery']['ids']) : count($ids) ];
		} catch ( \Throwable $error ) { return $this->failure($error); }
		finally {
			if ( $transaction ) {
				// A late insert hook may throw before the writer returns its new ID.
				try { $pending=$this->native_state(); $ids=array_merge($ids,array_map('intval',array_column($pending['posts'],'ID'))); $terms=array_merge($terms,array_map('intval',array_column($pending['terms'],'term_id'))); } catch ( \Throwable $ignored ) {}
				$wpdb->query('ROLLBACK'); if ( $registered ) { unregister_post_type(self::SOURCE); unregister_taxonomy(self::TAXONOMY); } if ( $unregistered ) (new Native_Service_Source_Service())->register_types();
			}
			$this->cleanup_caches($ids,$terms); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
		}
	}
}
