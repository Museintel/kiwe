<?php
/** Codec/permission regression; real CPT registration and native writes have a separate lab fixture. */
namespace DSA\Onboarding {
	final class Design_Context_Profile_Service {
		public const OPTION_PROFILE = 'archive-qa-profile';
		public const SERVICE_CATEGORY_PLAN_META = '_qa_service_category_plan';
		public static array $fields = [ 'duration'=>'integer','price'=>'number','intro'=>'string','featured'=>'boolean' ];
		public static array $taxonomies = [ 'qa_service_cat'=>[ 'hierarchical'=>true ],'qa_service_tag'=>[ 'hierarchical'=>false ] ];
		public function service_post_types(): array { return array_fill_keys( $GLOBALS['archive_qa_service_types'] ?? [], [] ); }
		public function service_taxonomies( string $type ): array { return self::$taxonomies; }
		public function service_meta_fields( string $type ): array { return array_map( static fn( $type )=>[ 'type'=>$type ], self::$fields ); }
	}
}
namespace {
	define( 'KIWE_CONTENT_JOB_TEST_BOOTSTRAP', true );
	require __DIR__ . '/test-content-archive-boundaries.php';
	use DSA\Backup\Content_Archive_Service as Archives;
	use DSA\Backup\Archive_Cipher as Cipher;
	use DSA\Onboarding\Design_Context_Profile_Service as Profile;
	function get_option( $key, $default = false ) { return 'archive-qa-profile' === $key ? [ 'services'=>[ 'sourcePostType'=>$GLOBALS['archive_qa_source'] ?? '' ] ] : $default; }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	$archive_qa_service_types = [ 'qa_service','qa_other' ]; $archive_qa_service_taxes = [ 'qa_service_cat','qa_service_tag' ]; $archive_qa_source = 'qa_service';
	$error = ''; $cleanup = false; $phrase = 'synthetic-service-recovery-123'; $service = new Archives();
	try {
		check( Archives::can_export_collection( 'services' ) && Archives::can_import( 'service' ), 'connected Service collection can be downloaded and restored' );
		foreach ( [ 1=>7,2=>8,3=>7,4=>7 ] as $id=>$author ) {
			$posts[$id] = new WP_Post( [ 'ID'=>$id,'post_type'=>'qa_service','post_title'=>'Synthetic Service '.$id,'post_content'=>'<p><strong>Native Service '.$id.'</strong></p>','post_excerpt'=>'Service excerpt','post_author'=>$author,'post_date'=>$id===4?'2088-01-03 00:00:00':'2088-01-02 12:00:00','post_date_gmt'=>'2088-01-02 12:00:00','post_status'=>'publish','post_parent'=>$id===1?3:0,'post_name'=>'service-'.$id,'menu_order'=>$id ] );
			$postmeta[$id] = [ 'duration'=>'60','price'=>'12.5','intro'=>"Public Service detail\nSecond line",'featured'=>'0','_kiwe_seo_title'=>'Synthetic Service SEO','private_token'=>'must stay private' ];
			$assignments[$id] = [ 'qa_service_cat'=>[ 40,41 ],'qa_service_tag'=>[ 42 ] ];
		}
		foreach ( [ 40=>[ 'qa_service_cat',0 ],41=>[ 'qa_service_cat',40 ],42=>[ 'qa_service_tag',0 ] ] as $id=>[ $tax,$parent ] ) $terms[$tax.':'.$id] = (object) [ 'term_id'=>$id,'taxonomy'=>$tax,'name'=>'Synthetic term '.$id,'slug'=>'service-term-'.$id,'description'=>'Service taxonomy detail','parent'=>$parent,'count'=>4 ];
		$termmeta[40] = [ '_kiwe_seo_title'=>'Service category SEO' ];
		$posts[5] = new WP_Post( [ ...(array)$posts[3], 'ID'=>5,'post_type'=>'qa_other' ] );
		check( ! Archives::can_export( 'service', 5 ) && ! Archives::type( 'service_category:category' ) && ! Archives::type( 'service_category:qa_service_cat/extra' ), 'Service connection excludes unrelated sources and taxonomy suffixes' );
		$plan = $service->plan( 'services', 0, [ 'from'=>'2088-01-02','to'=>'2088-01-02' ] );
		check( $plan['ids']===[1,2,3], 'Service selection keeps both date boundaries and excludes adjacent days' );
		$payload = $service->capture( $plan, $plan['ids'] );
		check( count($payload['terms'])===3 && $payload['records'][0]['terms']['qa_service_cat']===[40,41], 'Service capture includes native category hierarchy and tag assignments' );
		check( $payload['records'][0]['serviceMeta']===[ 'duration'=>60,'featured'=>false,'intro'=>"Public Service detail\nSecond line",'price'=>12.5 ] && ! str_contains(json_encode($payload),'must stay private'), 'Service codec preserves typed public scalars and excludes unregistered private fields' );
		$seal = static fn($data)=>Cipher::seal($data, Archives::SCHEMA, $phrase, [], Archives::MAX_BYTES);
		$categoryScope=Archives::category_scope('qa_service_cat',true);$bundle=$service->export($categoryScope,40,$phrase,['from'=>'2088-01-02','to'=>'2088-01-02']);$bundlePayload=Cipher::open(file_get_contents($service->download_path($bundle['id'])),Archives::SCHEMA,$phrase,Archives::MAX_BYTES);
		$bytes = $seal($payload); $review = $service->preview( $bytes, $phrase, 'service' );
		check( $review['count']===3 && $review['mode']==='new-drafts' && count($posts)===5, 'all Service records are reviewed before any new draft write' );
		$imported = $service->apply( $review['token'], $phrase ); $first = $imported['ids'][0];
		check( $imported['count']===3 && $posts[$first]->post_type==='qa_service' && $posts[$first]->post_status==='draft', 'Service imports use the connected native type and stay draft' );
		check( $posts[$first]->post_content===$posts[1]->post_content && $posts[$first]->post_author===7 && $posts[$first]->menu_order===1, 'Service import preserves formatted text original author and display order' );
		check( $posts[$first]->post_parent===$imported['ids'][2], 'Service hierarchy follows new draft IDs' );
		check( $assignments[$first]['qa_service_cat']===[40,41] && $assignments[$first]['qa_service_tag']===[42] && $postmeta[$first]['_kiwe_seo_title']==='Synthetic Service SEO', 'Service category tag and SEO assignments read back' );
		check( $postmeta[$first]['duration']===60 && $postmeta[$first]['price']===12.5 && $postmeta[$first]['featured']==='0' && $postmeta[$first]['intro']===$postmeta[1]['intro'], 'typed Service values use native metadata writes without losing false or multiline text' );
		check( $service->apply($review['token'],$phrase)===$imported && $posts[1]->post_status==='publish', 'Service completion is idempotent and published originals are preserved' );
		$export = $service->export( 'service', 1, $phrase );
		$archive_qa_denied_meta = [ 'duration' ];
		check( !Archives::can_export('service',1) && rejects(fn()=>$service->download_path($export['id'])), 'current native field authority is rechecked before Service download' );
		$archive_qa_denied_meta = [];
		$kind='service_staff';
		check( Archives::can_export('service',1) && !Archives::can_export('service',2) && !Archives::can_export('service_category:qa_service_cat',40), 'staff can export their own Service without foreign or category escalation' );
		$own = $service->plan('services',0,['from'=>'2088-01-02','to'=>'2088-01-02']);
		check( in_array(1,$own['ids'],true) && in_array(3,$own['ids'],true) && array_reduce($own['ids'],static fn($ok,$id)=>$ok&&$posts[$id]->post_author===7,true) && !Archives::can_import('post') && Archives::can_import('service'), 'Service-only staff collection uses native source primitives and own authorship' );
		check( rejects(fn()=>$service->preview($bytes,$phrase,'service')), 'staff cannot import another Service author through a collection' );
		$kind='visitor'; check(!Archives::can_import('service')&&!Archives::can_export_collection('services'), 'subscriber and anonymous Service archive access is denied'); $kind='client';
		$archive_qa_source='qa_other';
		check( rejects(fn()=>$service->capture($plan,$plan['ids'])) && rejects(fn()=>$service->preview($bytes,$phrase,'service')) && rejects(fn()=>$service->download_path($export['id'])), 'changed Service connection invalidates capture import and download' );
		$archive_qa_source='qa_service';
		$review=$service->preview($bytes,$phrase,'service'); Profile::$fields['duration']='string';
		check( rejects(fn()=>$service->apply($review['token'],$phrase)) && rejects(fn()=>$service->preview($bytes,$phrase,'service')), 'changed registered field type invalidates reviewed and fresh imports' ); Profile::$fields['duration']='integer';
		$archive_qa_source=''; check(!Archives::type('service')&&!Archives::can_export_collection('services'), 'unconnected Service plans are never silently converted by backups'); $archive_qa_source='qa_service';
		$bad=$payload; $bad['records'][0]['serviceMeta']['private_token']='forbidden';check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'unknown Service metadata is rejected rather than restored');
		$bad=$payload; $bad['records'][0]['serviceMeta']['duration']='60';check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'forged scalar representations are rejected before native writes');
		$bad=$payload; $bad['records'][0]['serviceMeta']['intro']='<script>bad</script>';check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'Service plain-text fields cannot bypass their existing sanitization boundary');
		$bad=$payload; $bad['records'][0]['parent']=5;check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'wrong-source parent is rejected');
		$bad=$payload; $bad['records'][0]['parent']=1;check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'self-parent Service is rejected');
		$bad=$payload; $bad['serviceDefinition']['hierarchical']=false;check(rejects(fn()=>$service->preview($seal($bad),$phrase,'service')), 'forged native Service definition is rejected');
		check($bundlePayload['scope']==='service_category:qa_service_cat' && count($bundlePayload['records'])===3, 'Service category archive retains the complete selected native collection');
		check(rejects(fn()=>$service->preview($seal($bundlePayload),$phrase,'service_category:qa_service_tag'))&&rejects(fn()=>$service->preview($bytes,$phrase,$categoryScope)), 'Service category import cannot cross taxonomy or ordinary collection boundaries');
		unset($terms['qa_service_cat:40'],$terms['qa_service_cat:41'],$terms['qa_service_tag:42']);$review=$service->preview($seal($bundlePayload),$phrase,$categoryScope);$bundleImport=$service->apply($review['token'],$phrase);$rootTerm=$bundleImport['categoryId'];
		check($rootTerm!==40 && $termmeta[$rootTerm]['_kiwe_seo_title']==='Service category SEO' && count($bundleImport['ids'])===3, 'missing Service category is recreated with native SEO and complete content');
		check($terms['qa_service_cat:'.$assignments[$bundleImport['ids'][0]]['qa_service_cat'][1]]->parent===$rootTerm, 'restored Service subcategory follows the new category parent');
		$before=count($posts);$review=$service->preview($bytes,$phrase,'service');$archive_qa_denied_meta=['duration'];check(rejects(fn()=>$service->apply($review['token'],$phrase))&&count($posts)===$before, 'denied Service field write removes only the new partial drafts');$archive_qa_denied_meta=[];
		Profile::$taxonomies=[];$postmeta[3][Profile::SERVICE_CATEGORY_PLAN_META]='Consulting > Advisory';$single=$service->plan('service',3);$singlePayload=$service->capture($single,[3]);
		check($singlePayload['records'][0]['serviceMeta'][Profile::SERVICE_CATEGORY_PLAN_META]==='Consulting > Advisory', 'connected source without taxonomies preserves its native category-plan field');
	} catch (\Throwable $failure) { $error=$failure->getMessage(); }
	finally {
		$remove=static function($path)use(&$remove){if(is_dir($path)&&!is_link($path)){foreach(scandir($path)as$name)if(!in_array($name,['.','..'],true))$remove($path.DIRECTORY_SEPARATOR.$name);rmdir($path);}elseif(file_exists($path)||is_link($path))unlink($path);};
		if(dirname($root)!==rtrim(sys_get_temp_dir(),'/\\')||!preg_match('/^kiwe-content-qa-[a-f0-9]{12}$/D',basename($root)))throw new \RuntimeException('Unexpected fixture cleanup path');
		$remove($root);$cleanup=!file_exists($root);
	}
	$passed=!$error&&$cleanup&&!in_array(false,$checks,true);echo json_encode(['schema'=>'kiwe-service-archive-boundaries.v1','passed'=>$passed,'cleanup'=>$cleanup,'checks'=>$checks,'error'=>$error],JSON_PRETTY_PRINT)."\n";exit($passed?0:1);
}
