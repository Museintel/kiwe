<?php
/** Actual WordPress fixture; the caller must isolate all writable native tables and delivery. */
if ( PHP_SAPI !== 'cli' || ! defined('KIWE_CF1_NATIVE_TEST') || KIWE_CF1_NATIVE_TEST !== true ) throw new RuntimeException('Owned isolated fixture required.');

function kiwe_cf1_search_seed(): array {
	wp_set_current_user(1);
	$tag=wp_insert_term('CF1Topic','post_tag');$extra=wp_insert_term('CF1Topic Extra','post_tag');
	if(is_wp_error($tag)||is_wp_error($extra))throw new RuntimeException('Fresh tag fixture required.');
	$ids=[];
	for($i=1;$i<=17;$i++){
		$id=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Synthetic story '.$i,'post_content'=>'Synthetic text without the assigned topic.','post_date'=>sprintf('2026-10-%02d 10:00:00',1+(int)floor($i/2)),'post_date_gmt'=>sprintf('2026-10-%02d 10:00:00',1+(int)floor($i/2))],true);
		if(is_wp_error($id))throw new RuntimeException('Native post fixture refused.');
		wp_set_object_terms($id,[$tag['term_id'],$extra['term_id']],'post_tag');$ids[]=$id;
	}
	$both=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'CF1Topic synthetic text and tag','post_content'=>'Synthetic story.','post_date'=>'2026-10-12 10:00:00']);wp_set_object_terms($both,[$tag['term_id']],'post_tag');
	$text=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'CF1Topic synthetic text only','post_content'=>'Synthetic story.','post_date'=>'2026-10-11 10:00:00']);
	$hidden=[];foreach(['draft','private','trash','future','password'] as $status){$id=wp_insert_post(['post_type'=>'post','post_status'=>$status==='password'?'publish':$status,'post_password'=>$status==='password'?'synthetic-secret':'','post_title'=>'CF1Topic hidden '.$status,'post_content'=>'Synthetic hidden text.','post_date'=>$status==='future'?'2030-01-01 00:00:00':'2026-10-20 00:00:00']);wp_set_object_terms($id,[$tag['term_id']],'post_tag');$hidden[]=$id;}
	$fixture=['tag'=>(int)$tag['term_id'],'extra'=>(int)$extra['term_id'],'tagOnly'=>$ids,'both'=>$both,'text'=>$text,'hidden'=>$hidden];update_option('kiwe_cf1_fixture',$fixture,false);return $fixture;
}

function kiwe_cf1_search_run(bool $store): array {
	global $wpdb;
	$proof=['passed'=>false,'checks'=>[],'metrics'=>[],'store'=>$store];
	$check=static function($ok,$label)use(&$proof){$proof['checks'][$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
	$settings=new DSA\Settings();$settings->update(['search'=>['families'=>['posts'=>true,'products'=>$store,'authors'=>false],'custom_taxonomies'=>[],'alphabet_enabled'=>false]]);
	$factory=static fn()=>new DSA\Commerce\Cart_Payload_Service(new DSA\Settings(),static fn()=>throw new RuntimeException('Unused linked-products factory'),static fn()=>throw new RuntimeException('Unused analytics factory'));
	$search=new DSA\Search\Search_Service($settings,$factory);$search->register();wp_set_current_user(0);
	$f=get_option('kiwe_cf1_fixture');$expected=[...$f['tagOnly'],$f['both'],$f['text']];
	$cold=$wpdb->num_queries;$start=hrtime(true);$first=$search->results('CF1Topic',4,'posts');$proof['metrics']['coldMs']=(hrtime(true)-$start)/1e6;$proof['metrics']['coldQueries']=$wpdb->num_queries-$cold;
	$check(is_array($first)&&count($first['posts'])===4&&$first['pagination']['posts']!=='','Tag-only matches join text matches and expose finite continuation');
	$seen=[];$cursor='';$pages=0;
	do{$data=$search->results('CF1Topic',4,'posts','','latest',$cursor);$check(is_array($data)&&count($data['posts'])<=4,'Finite native page '.++$pages);$seen=[...$seen,...array_column($data['posts'],'id')];$cursor=$data['pagination']['posts']??'';if($pages>20)throw new RuntimeException('Unbounded continuation');}while($cursor!=='');
	$sorted=$seen;sort($sorted);sort($expected);$check($sorted===$expected&&count(array_unique($seen))===count($seen),'All tagged and text matches reachable once, including tied dates');
	$check(!array_intersect($seen,$f['hidden']),'Draft private future trash and password-protected matches excluded');
	$dates=array_map(static fn($id)=>[get_post($id)->post_date,$id],$seen);$order=$dates;usort($order,static fn($a,$b)=>strcmp($b[0],$a[0])?:($b[1]<=>$a[1]));$check($dates===$order,'Latest retains native descending date and ID order');
	$warm=$wpdb->num_queries;$start=hrtime(true);$again=$search->results('CF1Topic',4,'posts');$proof['metrics']['warmMs']=(hrtime(true)-$start)/1e6;$proof['metrics']['warmQueries']=$wpdb->num_queries-$warm;$check($again['cached']===true,'Existing result cache is reused');
	$check((new WP_Query(['post_type'=>'post','post_status'=>'publish','has_password'=>false,'s'=>'CF1Topic','fields'=>'ids','posts_per_page'=>100]))->posts!==$seen,'Ordinary WordPress search remains unmodified');
	$check(is_wp_error($search->results('CF1Topic',4,'posts','','latest',$first['pagination']['posts'].'x')),'Tampered continuation is refused');
	$check(is_wp_error($search->results('Different query',4,'posts','','latest',$first['pagination']['posts'])),'Continuation is bound to the typed query');
	$check(is_wp_error($search->results('CF1Topic',5,'posts','','latest',$first['pagination']['posts'])),'Continuation cannot change page size');
	wp_set_current_user(1);$check(is_wp_error($search->results('CF1Topic',4,'posts','','latest',$first['pagination']['posts'])),'Public continuation cannot cross into another viewer');wp_set_current_user(0);
	$check(!$search->results("x' OR 1=1 --",4,'posts')['posts']&&$wpdb->last_error==='','Quoted SQL-like input cannot broaden membership or break native SQL');
	$relationship=wp_insert_term('CF1Relationship','post_tag');$initial=$search->results('CF1Relationship',4,'posts');$check(!$initial['posts'],'Absent relationship is initially empty');
	wp_set_object_terms($f['tagOnly'][0],[(int)$relationship['term_id']],'post_tag',true);$next=$search->results('CF1Relationship',4,'posts');$check(array_column($next['posts'],'id')===[$f['tagOnly'][0]],'Native assignment invalidates Kiwe and WP query caches without a post save');
	wp_remove_object_terms($f['tagOnly'][0],[(int)$relationship['term_id']],'post_tag');$check(!$search->results('CF1Relationship',4,'posts')['posts'],'Native relationship removal invalidates cached membership');
	$check(is_wp_error($search->results('CF1Topic',4,'posts','','latest',$first['pagination']['posts'])),'Content/term changes invalidate old continuation before a cached page is returned');
	$restriction=static function($sql,$query)use($wpdb){return $query->get('post_type')==='post'?$sql." AND {$wpdb->posts}.ID=0 ":$sql;};add_filter('posts_search',$restriction,10,2);DSA\Search\Search_Service::invalidate_settings();$restricted=$search->results('CF1Topic',4,'posts');remove_filter('posts_search',$restriction,10);DSA\Search\Search_Service::invalidate_settings();$check(!$restricted['posts'],'Later native/plugin search restrictions also constrain tag-only matches');
	foreach(['ಬೆಂಗಳೂರು','CF1 Long Topic','CF1 %_ literal']as$name){$term=wp_insert_term($name,'post_tag');wp_set_object_terms($f['tagOnly'][0],[(int)$term['term_id']],'post_tag',true);$q=$name==='CF1 Long Topic'?'cf1-long-topic':$name;$result=$search->results($q,4,'posts');$check(in_array($f['tagOnly'][0],array_column($result['posts'],'id'),true),'Unicode phrase literal or slug match: '.$q);}
	$check(in_array($f['both'],array_column($search->results('"CF1Topic"',4,'posts')['posts'],'id'),true),'Quoted phrase preserves native text matching and includes tags');
	$check(!array_intersect($f['tagOnly'],array_column($search->results('CF1Topic -hidden',4,'posts')['posts'],'id')),'Native exclusion syntax is not broadened by tag expansion');
	wp_set_current_user(1);register_taxonomy('cf1_public_topic','post',['public'=>true]);register_taxonomy('cf1_private_topic','post',['public'=>false]);
	$term=wp_insert_term('CF1CustomPublic','cf1_public_topic');wp_set_object_terms($f['tagOnly'][0],[(int)$term['term_id']],'cf1_public_topic');$private=wp_insert_term('CF1PrivateOnly','cf1_private_topic');wp_set_object_terms($f['tagOnly'][0],[(int)$private['term_id']],'cf1_private_topic');
	$settings->update(['search'=>['custom_taxonomies'=>['cf1_public_topic','cf1_private_topic']]]);DSA\Search\Search_Service::invalidate_settings();$search=new DSA\Search\Search_Service($settings,$factory);wp_set_current_user(0);
	$check(array_column($search->results('CF1CustomPublic',4,'posts')['posts'],'id')===[$f['tagOnly'][0]],'Explicit applicable public taxonomy participates');$check(!$search->results('CF1PrivateOnly',4,'posts')['posts'],'Private taxonomy is not a public keyword source');
	wp_set_current_user(1);$popular=wp_insert_term('CF1PopularWindow','post_tag');$popularIds=[];for($i=0;$i<101;$i++){$id=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Synthetic popular '.$i,'post_content'=>'Synthetic text.','post_date'=>sprintf('2026-09-%02d %02d:00:00',1+(int)floor($i/24),$i%24)]);wp_set_object_terms($id,[(int)$popular['term_id']],'post_tag');$popularIds[]=$id;}
	$scores=static function($unused,$ids){$out=[];foreach($ids as$id)$out[$id]=$id%7;return$out;};add_filter('dsa_search_popularity_scores',$scores,10,2);wp_set_current_user(0);$seen=[];$cursor='';$pages=0;
	do{$data=$search->results('CF1PopularWindow',12,'posts','','popular',$cursor);$check(is_array($data)&&count($data['posts'])<=12,'Bounded Popular page '.++$pages);$seen=[...$seen,...array_column($data['posts'],'id')];$cursor=$data['pagination']['posts']??'';if($pages>20)throw new RuntimeException('Unbounded popular continuation');}while($cursor!=='');$sorted=$seen;sort($sorted);sort($popularIds);$check($sorted===$popularIds&&count(array_unique($seen))===101,'Popular continuation spans recent candidate windows without dropping or repeating tagged posts');remove_filter('dsa_search_popularity_scores',$scores,10);
	$check(DSA\Apps\Store_App_Service::enabled()===$store,'Actual Store applicability matches the native case');
	if($store){wp_set_current_user(1);$tag=wp_insert_term('CF1ProductTopic','product_tag');$products=[];for($i=0;$i<9;$i++){$p=new WC_Product_Simple();$p->set_name('Synthetic product '.$i);$p->set_status('publish');$p->set_regular_price('10');$p->set_catalog_visibility($i===8?'hidden':'visible');$id=$p->save();wp_set_object_terms($id,[(int)$tag['term_id']],'product_tag');if($i!==8)$products[]=$id;}wp_set_current_user(0);$cursor='';$seen=[];$pages=0;do{$data=$search->results('CF1ProductTopic',3,'products','','latest',$cursor);$check(is_array($data)&&count($data['products'])<=3,'Finite Store product page '.++$pages);$seen=[...$seen,...array_column($data['products'],'id')];$cursor=$data['pagination']['products']??'';if($pages>10)throw new RuntimeException('Unbounded products');}while($cursor!=='');sort($seen);sort($products);$check($seen===$products,'Product tags participate while native hidden products remain excluded');}
	else{$data=$search->results('CF1Topic',4,'all');$check(!$data['hasCommerce']&&!$data['families']['products']&&!$data['products'],'No Store results or cart factory when Store is absent/disabled');$check(!class_exists('DSA\\Commerce\\Cart_Payload_Service',false),'Noncommerce search does not parse the Store cart engine');}
	$proof['passed']=true;return$proof;
}
