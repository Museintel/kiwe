<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Admission_Policy.php';
require_once dirname(__DIR__) . '/src/Orientation.php';
use Kiwe\MCP\Contracts\Admission_Policy;
use Kiwe\MCP\Contracts\Orientation;

$checks = [];
$check = static function(bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    $checks[$name] = true;
};
$catalogue = json_decode((string) file_get_contents(dirname(__DIR__) . '/operation-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
$core = dirname(__DIR__, 3) . '/wp-content/mu-plugins/dsa/';
foreach ($catalogue['operations'] as $id => $operation) {
    $check(preg_match('/^[a-z][a-z0-9.-]+$/D', $id) === 1
        && preg_match('#^includes/[A-Za-z0-9_/]+\.php$#D', $operation['owner']) === 1
        && is_file($core . $operation['owner']), 'Existing native source anchor: ' . $id);
}
$policy = new Admission_Policy(); $now = 10000;
$grant = ['authenticated'=>true, 'active'=>true, 'actorId'=>7, 'installation'=>'installation-a', 'issuer'=>'https://example.test',
    'resource'=>'https://example.test/wp-json/kiwe-mcp/v1/mcp', 'clientId'=>'test-client', 'id'=>'test-grant-id-1234',
    'issuedAt'=>9900, 'expiresAt'=>10600, 'scopes'=>array_values(array_unique(array_column($catalogue['operations'], 'scope')))];
$site = ['mcpEnabled'=>true, 'quarantined'=>false, 'actorId'=>7, 'installation'=>$grant['installation'], 'issuer'=>$grant['issuer'], 'resource'=>$grant['resource'],
    'apps'=>['store'=>true, 'seo'=>true, 'ai'=>false], 'dependencies'=>['woocommerce'=>true, 'bricks'=>true]];
$yes = static fn(...$args): bool => true;
$no = static fn(...$args): bool => false;
$crash = static function(...$args): bool { throw new RuntimeException('synthetic unavailable owner'); };
$intent = static fn(string $operation): array => ['operation'=>$operation, 'actorId'=>7, 'installation'=>$grant['installation'], 'resource'=>$grant['resource'],
    'clientId'=>$grant['clientId'], 'grantId'=>$grant['id'], 'digest'=>str_repeat('a',64), 'expiresAt'=>10100];
$decide = static fn(string $id='context.read', ?array $g=null, ?array $s=null, ?callable $native=null, ?callable $ready=null, ?array $i=null, ?callable $approval=null): array
    => $policy->decide($id, $g ?? $grant, $s ?? $site, $now, $native ?? $yes, $ready ?? $yes, $i, $approval);
$reason = static fn(array $decision, string $code): bool => $decision['admitted'] === false && $decision['reason'] === $code && $decision['executed'] === false;

$valid = $decide();
$check($valid['admitted'] && !$valid['executed'] && $valid['nativeRecheckRequired'], 'Admission never claims native execution');
$check($reason($decide('context.read', ready:$no),'operation_unavailable'), 'A planned catalogue entry is not an implemented handler');
$check($reason($decide('arbitrary.sql'),'unknown_operation'), 'Unregistered arbitrary operations are denied');
foreach (['authenticated','active'] as $key) $check($reason($decide(g:array_replace($grant,[$key=>false])),'authentication_required'), 'Missing/revoked authorization: '.$key);
$check($reason($decide(g:[]),'authentication_required'), 'Empty context is not authentication');
$check($reason($decide(g:array_replace($grant,['actorId'=>8])),'actor_mismatch'), 'A grant cannot act as another account');
$check($reason($decide(g:array_replace($grant,['actorId'=>'7'])),'actor_mismatch'), 'Actor type coercion is refused');
foreach (['installation','issuer','resource'] as $key) $check($reason($decide(g:array_replace($grant,[$key=>'different'])),'site_mismatch'), 'Cross-site/issuer binding: '.$key);
$check($reason($decide(g:array_replace($grant,['clientId'=>''])),'invalid_grant'), 'An absent client identity is refused');
foreach ([['expiresAt'=>10000],['expiresAt'=>9999],['issuedAt'=>10001],['issuedAt'=>1,'expiresAt'=>10001],['expiresAt'=>'10600']] as $patch)
    $check($reason($decide(g:array_replace($grant,$patch)),'grant_expired'), 'Strict expiring grant '.json_encode($patch));
foreach ([[],['*'],['context.read','unknown.scope'],['context.write']] as $scopes)
    $check($reason($decide(g:array_replace($grant,['scopes'=>$scopes])),'scope_denied'), 'Finite least-privilege scopes '.json_encode($scopes));
$check($reason($decide(s:array_replace($site,['mcpEnabled'=>false])),'app_unavailable'), 'An off MCP App cannot admit operations');
$check($reason($decide(s:array_replace($site,['quarantined'=>true])),'app_unavailable'), 'Cloned/quarantined context cannot admit operations');
$check($reason($decide(native:$no),'native_permission_denied'), 'Current native denial overrides an active grant');
$check($reason($decide(native:static fn()=>1),'native_permission_denied'), 'Truthy native callback values do not grant permission');
$check($reason($decide(ready:static fn()=>1),'operation_unavailable'), 'Truthy handler readiness does not expose operations');
$check($reason($decide(native:$crash),'admission_unavailable'), 'Native permission exceptions fail closed');
$check($reason($decide(ready:$crash),'admission_unavailable'), 'Handler exceptions fail closed');
$check($reason($decide('store.read',s:array_replace($site,['apps'=>['store'=>false]])),'dependency_unavailable'), 'Inactive Store hides Store operations even with Woo present');
$check($reason($decide('store.read',s:array_replace($site,['dependencies'=>['woocommerce'=>false]])),'dependency_unavailable'), 'Missing Woo denies Store operations even with a saved On choice');
$no_seo = array_replace($site,['apps'=>['store'=>false,'seo'=>false,'ai'=>false]]);
$check($decide(s:$no_seo)['admitted'], 'Design Context remains available without Store/SEO/AI');
$check($decide('context.seo-goal',s:$no_seo,i:$intent('context.seo-goal'))['admitted'], 'Core SEO goal is independent of the SEO App');
$check($reason($decide('seo.apply',s:$no_seo,i:$intent('seo.apply'),approval:$yes),'dependency_unavailable'), 'External SEO ownership is not silently replaced');
$check($reason($decide('context.update'),'review_required'), 'Writes require a prepared bounded intent');
$check($decide('context.update',i:$intent('context.update'))['admitted'], 'A routine authorized edit does not require another approval lookup');
foreach (['actorId','installation','resource','clientId','grantId'] as $key) {
    $i = $intent('context.update'); $i[$key] = $key==='actorId' ? 8 : 'other';
    $check($reason($decide('context.update',i:$i),'intent_mismatch'), 'Intent binding: '.$key);
}
foreach ([['operation'=>'store.settings'],['digest'=>'bad'],['expiresAt'=>10000],['expiresAt'=>11000]] as $patch)
    $check($reason($decide('context.update',i:array_replace($intent('context.update'),$patch)),'review_required'), 'Invalid/expired prepared intent '.json_encode($patch));
$publish = $intent('content.publish'); $publish['approved'] = true;
$check($reason($decide('content.publish',i:$publish),'approval_required'), 'A client-supplied approved flag has no authority');
$check($reason($decide('content.publish',i:$publish,approval:$no),'approval_required'), 'Independent declined approval remains denied');
$check($reason($decide('content.publish',i:$publish,approval:static fn()=>1),'approval_required'), 'Approval proof must be literal true');
$check($reason($decide('content.publish',i:$publish,approval:$crash),'admission_unavailable'), 'Approval-store outage cannot fall back to client approval');
$check($decide('content.publish',i:$publish,approval:$yes)['admitted'], 'Exact server approval admits only the next native execution check');
$role_allowed = true;
$live_permission = static function() use (&$role_allowed): bool { return $role_allowed; };
$first = $decide(native:$live_permission); $role_allowed = false;
$check($first['admitted'] && $reason($decide(native:$live_permission),'native_permission_denied'), 'Role removal is rechecked without replacing the grant');
$check($reason($decide('design.import',s:array_replace($site,['dependencies'=>['bricks'=>false]]),i:$intent('design.import'),approval:$yes),'dependency_unavailable'), 'Bricks import cannot be advertised without Bricks');

$facts = ['inventoryComplete'=>true,'freshInstallConfirmed'=>true,'businessContextPresent'=>false,'storeAvailable'=>false,
    'inventory'=>['authoredContent'=>0,'products'=>0,'nativeOrders'=>0,'customRecords'=>0,'builderDocuments'=>0,'media'=>0,'navigation'=>0]];
$journey = ['next'=>'story','steps'=>[
    ['id'=>'story','label'=>'Story & audience','required'=>true,'included'=>true,'complete'=>false,'deferred'=>true],
    ['id'=>'products','label'=>'Products','required'=>true,'included'=>true,'complete'=>false],
    ['id'=>'store','label'=>'Store setup','required'=>true,'included'=>true,'complete'=>false],
    ['id'=>'regulatory','label'=>'Business registrations','required'=>false,'included'=>false,'complete'=>false],
    ['id'=>'search','label'=>'Search & sharing','required'=>true,'included'=>true,'complete'=>false],
], 'private'=>'synthetic-secret-not-for-output'];
$fresh = Orientation::compose($facts,$journey);
$check($fresh['state']==='fresh','Complete positive fresh-install evidence permits the fresh welcome');
$check(Orientation::compose(array_replace($facts,['freshInstallConfirmed'=>false]),$journey)['state']==='empty','An empty site alone does not prove a fresh installation');
$check(Orientation::compose(array_replace($facts,['inventoryComplete'=>false]),$journey)['state']==='unknown','A partial inventory cannot prove freshness');
foreach (['authoredContent','products','nativeOrders','customRecords','builderDocuments','media','navigation'] as $key) {
    $changed=$facts; $changed['inventory'][$key]=1;
    $check(Orientation::compose($changed,$journey)['state']==='existing','Existing state wins over a fresh claim: '.$key);
}
$changed=$facts;unset($changed['inventory']['customRecords']);
$check(Orientation::compose($changed,$journey)['state']==='unknown','An omitted content family cannot be assumed empty');
$changed=$facts;$changed['inventory']['products']=-1;
$check(Orientation::compose($changed,$journey)['state']==='unknown','A failed inventory count cannot be assumed empty');
$check(Orientation::compose(array_replace($facts,['businessContextPresent'=>true]),$journey)['state']==='existing','Saved business context is preserved even without published content');
$check(array_column($fresh['steps'],'id')===['story','search'],'Non-Store orientation excludes all three Store-only journey steps');
$check($fresh['steps'][0]['required'] && $fresh['steps'][0]['deferred'] && !$fresh['steps'][0]['complete'],'Deferred required information remains incomplete');
$check($fresh['nextStep']==='story','Reuse the existing native journey next step');
$j=$journey;$j['next']='unrecognized';
$check(Orientation::compose($facts,$j)['nextStep']===null,'Unknown native next-step identifiers do not become navigation');
$j=$journey;$j['next']='store';
$check(Orientation::compose($facts,$j)['nextStep']===null,'A Store-off next step is not returned');
$check(!Orientation::compose($facts,null)['journeyAvailable'],'A missing authorized journey is reported, not fabricated');
$check(count(Orientation::compose(array_replace($facts,['storeAvailable'=>true]),$journey)['steps'])===5,'Active Store keeps its applicable native steps');
$check(!str_contains(json_encode($fresh),'synthetic-secret-not-for-output') && !isset($fresh['inventory']),'Projection excludes raw inventory and unrelated private input');
$check(!$fresh['automaticConversationGuaranteed'] && $fresh['fallbackPrompt']==='Start with Kiwe','Client-start limitations have an honest one-step fallback');
$check(!$fresh['mutatesSite'] && !$fresh['credentialsIncluded'] && !$fresh['customerRecordsIncluded'],'Orientation declares a passive privacy-minimized projection');

echo json_encode(['passed'=>true,'scope'=>'M01 pure source admission/orientation; no OAuth/native/HTTP/client acceptance','checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
