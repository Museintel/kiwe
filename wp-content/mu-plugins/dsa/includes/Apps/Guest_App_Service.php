<?php
namespace DSA\Apps;
if ( ! defined( 'ABSPATH' ) ) exit;
final class Guest_App_Service extends Optional_App_Service {
	public const ID='guest',NAME='Guest',CAPABILITY='kiwe_manage_guest_applications',PAGE='kiwe-guests&area=setup',DESCRIPTION='Guest applications, contributions and review. Shopper guest checkout stays in Store.';
}
