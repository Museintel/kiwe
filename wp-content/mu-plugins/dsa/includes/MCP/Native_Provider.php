<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Connection facts preserve M02 pins; wider native reads require their own current consent. */
final class Native_Provider {
 public static function register(Operation_Registry $registry): void {
  $registry->register('mcp',1,static function()use($registry): array {
   $string=static fn($length)=>['type'=>'string','maxLength'=>$length];
   return['schema'=>\DSA\Apps\MCP_Provider_Contract::SCHEMA,'api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[],'providerConsentRequired'=>false],'operations'=>[
    'mcp.connection.read'=>['scope'=>'mcp.read','label'=>'Read this connection’s website and available guidance','effect'=>'read','approval'=>'none','accessVersion'=>1,
     'inputSchema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
     'outputSchema'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
      'website'=>$string(2048),'name'=>$string(200),'coreVersion'=>$string(60),'wordpressVersion'=>$string(40),'providerApi'=>['type'=>'integer','minimum'=>1,'maximum'=>1],
      'readOnly'=>['type'=>'boolean'],'guidance'=>$string(1000)
     ],'required'=>['website','name','coreVersion','wordpressVersion','providerApi','readOnly','guidance']],
     'discoverPermission'=>static fn()=>Credentials::actor_allowed(get_current_user_id()),'permission'=>static fn(array $args)=>Credentials::actor_allowed(get_current_user_id()),
     'execute'=>static function(array $args)use($registry): array {
      global $wp_version;
      return['website'=>Urls::issuer(),'name'=>\DSA\Access\Task_Workspace_UI::text(get_bloginfo('name'),200),'coreVersion'=>(string)DSA_VERSION,'wordpressVersion'=>(string)$wp_version,'providerApi'=>1,'readOnly'=>Setup_Provider::connected_writes($registry)===[],
       'guidance'=>'Start with Kiwe. Discover approved operations first. Read native site orientation, Design Context and App recommendations, then ask only useful missing questions. For design, use mcp.design.read area guide only when advertised; pin the matching Start instructions and kit. Existing connections do not inherit added permissions. Only separately approved setup scopes can prepare and apply native changes; consequential actions also need an exact native review. Google, Meta and future Apps own their provider linking and consent; this connection grants none of those accounts or spending permissions.'];
     }]
   ]+self::start_site_link()+(defined(\DSA\Onboarding\Design_Context_Profile_Service::class.'::GUIDANCE_READ_API')&&\DSA\Onboarding\Design_Context_Profile_Service::GUIDANCE_READ_API===1?Guidance_Provider::operations($registry):[])+Setup_Provider::operations($registry)+Authoring_Provider::operations()+Growth_Provider::operations()+Design_Provider::operations()+Bricks_Import_Provider::operations($registry)+(class_exists(Bricks_Homepage_Provider::class)?Bricks_Homepage_Provider::operations():[])+(class_exists(Bricks_Document_Provider::class)?Bricks_Document_Provider::operations():[])+Maintenance_Provider::operations()+Featured_Image_Provider::operations()];
  });
 }

 /** A dedicated read-only consent scope for proving control of the website in Start. */
 private static function start_site_link(): array {
  $allowed = static function(): bool {
   $actor = get_current_user_id();
   if ( ! Credentials::actor_allowed( $actor ) ) return false;
   if ( is_multisite() || \DSA\Access\Site_Owner_Service::owner_id() > 0 ) {
    return \DSA\Access\Site_Owner_Service::can_manage_dashboard();
   }
   return current_user_can( 'manage_options' );
  };
  return [
   'mcp.site.connect.read' => [
    'scope' => 'mcp.site.connect',
    'label' => 'Verify control of this website for Kiwe Start',
    'effect' => 'read',
    'approval' => 'none',
    'accessVersion' => 1,
    'inputSchema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
    'outputSchema' => [
     'type' => 'object',
     'additionalProperties' => false,
     'properties' => [
      'website' => [ 'type' => 'string', 'maxLength' => 2048 ],
      'coreVersion' => [ 'type' => 'string', 'maxLength' => 60 ],
      'wordpressVersion' => [ 'type' => 'string', 'maxLength' => 40 ],
     ],
     'required' => [ 'website', 'coreVersion', 'wordpressVersion' ],
    ],
    'discoverPermission' => $allowed,
    'permission' => $allowed,
    'execute' => static function(): array {
     global $wp_version;
     return [ 'website' => Urls::issuer(), 'coreVersion' => (string) DSA_VERSION, 'wordpressVersion' => (string) $wp_version ];
    },
   ],
  ];
 }
}
