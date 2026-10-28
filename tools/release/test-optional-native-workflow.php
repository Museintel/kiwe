<?php
declare(strict_types=1);
// Exercise the actual routing and policy services; WordPress I/O is synthetic.
ob_start(); require __DIR__ . '/test-workspace-dashboard-policy.php'; $prior = ob_get_clean();
if ( ! str_contains( $prior, '"passed":true' ) ) throw new RuntimeException( 'Dashboard authority prerequisite' );
$checks = 0;
$assert = static function ( $ok, string $why ) use ( &$checks ): void { ++$checks; if ( ! $ok ) throw new RuntimeException( $why ); };
$multisite = false; $network = []; $fail_store = false;
$options[DSA\Access\Site_Owner_Service::OPTION] = [ 'enabled'=>true, 'ownerId'=>2, 'rankMath'=>'inline', 'dashboardMandatory'=>false ];
$users[8] = new WP_User( 8, [ 'editor' ] );
$workspace = new DSA\Access\Workspace_Admin_Service();
$originalPolicy = DSA\Access\Site_Owner_Service::policy();
foreach ( [ 1, 2, 4, 6, 7, 8 ] as $id ) {
	$actor = $id; $user = $users[$id];
	$before = $access->client_capabilities( [ 'read'=>true, 'edit_posts'=>true, 'install_plugins'=>true ], [], [], $user );
	$assert( $workspace->login_redirect( admin_url('index.php'), '', $user ) === admin_url('index.php'), 'Optional login keeps native dashboard for '.$id );
	$assert( $workspace->login_redirect( admin_url('post-new.php'), admin_url('post-new.php'), $user ) === admin_url('post-new.php'), 'Explicit native post destination survives for '.$id );
	foreach ( [ 'index.php', 'edit.php', 'post-new.php', 'post.php', 'upload.php', 'media-new.php', 'edit-tags.php', 'edit-comments.php', 'admin-ajax.php' ] as $script ) {
		$GLOBALS['pagenow'] = $script; $_GET = $_POST = $_REQUEST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
		$assert( $workspace->redirect_dashboard() === null && $access->guard_editorial_admin_route() === null, 'Optional native route is not replaced: '.$id.' '.$script );
	}
	$assert( $access->limit_editorial_menu() === null, 'Optional native menu keeps its WordPress entries for '.$id );
	$bar = new class { public array $removed=[]; function remove_node($id){ $this->removed[]=$id; } };
	$access->toolbar($bar); $assert( !$bar->removed, 'Optional native toolbar keeps native actions for '.$id );
	$assert( $before === $access->client_capabilities( [ 'read'=>true, 'edit_posts'=>true, 'install_plugins'=>true ], [], [], $user ), 'Routing does not grant capabilities for '.$id );
}
$actor=1;
$assert( !current_user_can('install_plugins') && !current_user_can('manage_options'), 'Optional client still has no technical privileges' );
$assert( $owner->update_dashboard(['expected'=>hash('sha256',json_encode($originalPolicy)),'mandatory'=>true]) instanceof WP_Error, 'Optional client cannot change mandatory policy' );
$request = new class { function get_route(){return '/rankmath/v1/updateMeta';} function get_method(){return 'POST';} function get_param($name){return ['objectID'=>10,'objectType'=>'post'][$name]??null;} };
$assert( $access->guard_rest(null,null,$request) === null, 'Native Rank Math inline post fields remain admitted' );
$actor=4;
$assert( $access->content_boundary(['edit_posts'],'edit_post',4,[12]) === ['do_not_allow'], 'Optional routing preserves protected template boundaries' );
$assert( DSA\Access\Site_Owner_Service::policy() === $originalPolicy, 'Native workflow does not rewrite ownership or Rank Math policy' );
echo json_encode(['passed'=>true,'checks'=>$checks,'nativeSqlExecuted'=>false,'scope'=>'Optional native login, routes, menus and toolbar with unchanged capability/owner boundaries']).PHP_EOL;
