<?php
require __DIR__ . '/test-client-workspace.php';
use DSA\Access\Template_Workspace_Service as Templates;
use DSA\Access\WordPress_Role_Access_Service as Access;
$deletion = array_diff( Templates::owner_template_caps(),Templates::template_caps() );
check( count( $deletion ) === 4,'Exactly four native template deletion primitives are added for final controllers' );
foreach ( $deletion as $cap ) {
	check( in_array( $cap,Access::capabilities_for( 'administrator' ),true ),'Client Admin receives native template primitive: ' . $cap );
	foreach ( [ Templates::ROLE,'shop_manager','editor','author','contributor','customer','kiwe_user','subscriber','unassigned' ] as $role ) check( ! in_array( $cap,Access::capabilities_for( $role ),true ),'Other role remains unchanged: ' . $role . ' / ' . $cap );
}
$policy = DSA\Access\Site_Owner_Service::policy(); $policy['enabled'] = true; $policy['ownerId'] = 9001; $options[DSA\Access\Site_Owner_Service::OPTION] = $policy;
$users[9001] = new WP_User( 9001,[ DSA\Access\Site_Owner_Service::ROLE ] );
$owner_caps = $access->client_capabilities( [ 'read'=>true ],[],[],$users[9001] );
foreach ( $deletion as $cap ) check( ! empty( $owner_caps[$cap] ),'Designated Super Admin receives native template primitive: ' . $cap );
echo "PASS template ownership retains the native object permission boundary\n";
