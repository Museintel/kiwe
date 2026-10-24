<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Generated from tools/release/apps-ownership.json. Build profiles share one source owner. */
final class Package_Layout {
 public const BUNDLED = true; // KIWE_APPS_BUNDLED_PROFILE
 public const MINIMUM = ['seo'=>'1.0.0-rc.3','store'=>'1.0.0-rc.2'];
 public const APP_FILES = [
  'assets/css/commerce-app.css',
  'assets/css/seo-object-app.css',
  'assets/js/commerce-app.js',
  'assets/js/modules/commerce-panels.js',
  'assets/js/product-context.js',
  'assets/js/seo-object-app.js',
  'includes/Access/Checkout_Policy_Workspace_Service.php',
  'includes/Access/Commerce_App_Service.php',
  'includes/Access/Product_Attribute_Workspace_Service.php',
  'includes/Access/Product_Merchandising_Workspace_Service.php',
  'includes/Access/Product_Variation_Workspace_Service.php',
  'includes/Bricks/Commerce_Binding_Service.php',
  'includes/Commerce/Abandoned_Cart_Service.php',
  'includes/Commerce/COD_Gate_Service.php',
  'includes/Commerce/Cart_Payload_Service.php',
  'includes/Commerce/Checkout_Service.php',
  'includes/Commerce/Commerce_Context_Service.php',
  'includes/Commerce/Linked_Products_Service.php',
  'includes/Commerce/Product_Context_Service.php',
  'includes/Commerce/Purchase_Identity_Gate.php',
  'includes/Commerce/Store_Commerce_Analytics_Service.php',
  'includes/Commerce/Weight_Shipping_Service.php',
  'includes/Notifications/Order_Notification_Service.php',
  'includes/Rest/Cart_Controller.php',
  'includes/Rest/Checkout_Controller.php',
  'includes/Rest/Rewards_Controller.php',
  'includes/Rewards/Reward_Service.php',
  'includes/SEO/SEO_Defaults_Model.php',
  'includes/SEO/SEO_Defaults_Service.php',
  'includes/SEO/SEO_Discovery_Service.php',
  'includes/SEO/SEO_Engine_Service.php',
  'includes/SEO/SEO_Job_Store.php',
  'includes/SEO/SEO_Metadata_Migration_Service.php',
  'includes/SEO/SEO_Output_Service.php',
  'includes/SEO/SEO_Posts_Sitemap_Provider.php',
  'includes/SEO/SEO_Redirect_Service.php',
  'includes/SEO/SEO_Refinement_Service.php',
  'includes/SEO/SEO_Score_Service.php',
  'includes/SEO/SEO_Score_Source.php',
  'includes/SEO/SEO_Settings_Handover_Model.php',
  'includes/SEO/SEO_Settings_Handover_Service.php',
  'includes/SEO/SEO_Sitemap_Service.php',
  'includes/SEO/SEO_Structured_Import_Service.php',
  'includes/SEO/SEO_Taxonomies_Sitemap_Provider.php',
  'includes/Schema/Schema_Geo_Service.php',
 ];
}
