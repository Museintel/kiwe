<?php

namespace DSA\Bricks;

use DSA\Onboarding\Design_Context_Profile_Service as Profile;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Public binding vocabulary shared by the builder and the design handoff. No shadow data store. */
final class Design_Context_Binding_Service {
	public const SCHEMA = 'kiwe.dynamic-bindings.v1';
	private static ?array $resolved_profile = null;
	public static function invalidate(): void { self::$resolved_profile = null; }
	public static function profile(): array {
		return self::$resolved_profile ??= (new \DSA\Onboarding\Design_Context_Enhancement_Service())->resolved_profile();
	}

	/** A native tag is preferred whenever its actual owner already supplies the value. */
	public static function fields(): array {
		$preferred = [
			'identity.siteName'=>'site_title', 'identity.tagline'=>'site_tagline', 'identity.logo'=>'kiwe_site_logo',
			'identity.logoInverse'=>'kiwe_site_logo_inverse', 'identity.description'=>'kiwe_business_description',
			'contact.phone'=>'kiwe_store_phone', 'contact.email'=>'kiwe_store_email', 'contact.whatsapp'=>'kiwe_whatsapp',
			'contact.phoneUrl'=>'kiwe_store_phone_url', 'contact.emailUrl'=>'kiwe_store_email_url', 'contact.whatsappUrl'=>'kiwe_whatsapp_url',
			'contact.directionsUrl'=>'kiwe_directions_url',
			'about.story'=>'kiwe_business_story', 'about.mission'=>'kiwe_business_mission', 'about.vision'=>'kiwe_business_vision',
			'about.values'=>'kiwe_business_values', 'about.usp'=>'kiwe_business_usp',
			'about.founder.name'=>'kiwe_founder_name', 'about.founder.title'=>'kiwe_founder_title', 'about.founder.bio'=>'kiwe_founder_bio',
			'about.founder.image'=>'kiwe_founder_image', 'about.founder.linkedin'=>'kiwe_founder_linkedin_url',
			'about.founder.website'=>'kiwe_founder_website_url', 'about.founder.facebook'=>'kiwe_founder_facebook_url',
			'about.team.enabled'=>'kiwe_team_enabled', 'services.enabled'=>'kiwe_services_enabled',
			'brand.tone'=>'kiwe_brand_tone', 'contentPlan.showBlogRailOnHome'=>'kiwe_show_blog_rail',
			'contentPlan.highlightBestsellers'=>'kiwe_highlight_bestsellers',
			'regulatory.fssaiLicense'=>'kiwe_fssai_license', 'regulatory.gstNumber'=>'kiwe_gst_number',
			'regulatory.manufacturingAddress'=>'kiwe_manufacturing_address', 'regulatory.showFssaiOnProducts'=>'kiwe_show_fssai_on_products',
			'regulatory.showGstOnProducts'=>'kiwe_show_gst_on_products', 'regulatory.showManufacturingAddress'=>'kiwe_show_manufacturing_address',
		];
		foreach ( [ 'line1'=>'address_1','line2'=>'address_2','city'=>'city','state'=>'state','country'=>'country','postcode'=>'postcode' ] as $key=>$suffix ) $preferred['contact.address.'.$key] = 'kiwe_store_'.$suffix;
		foreach ( [ 'facebook','instagram','x','youtube','pinterest','linkedin' ] as $network ) $preferred['contact.socialLinks.'.$network] = 'kiwe_'.$network.'_url';
		foreach ( [ 'brand'=>'brand','accent'=>'accent','hero'=>'hero','neutral'=>'neutral','surface'=>'surface' ] as $role=>$tag ) $preferred['brand.colors.'.$role] = 'kiwe_'.$tag.'_color';
		$extra = [
			'identity.siteIcon'=>'image', 'identity.industry'=>'text', 'identity.industrySector'=>'text', 'identity.siteType'=>'text',
			'identity.squareLogo'=>'image', 'identity.roundLogo'=>'image',
			'localization.timezone'=>'text', 'localization.language'=>'text',
			'audience.primary'=>'text', 'audience.locations'=>'text', 'audience.needs'=>'text', 'brand.notes'=>'text',
			'contact.whatsappSameAsPhone'=>'boolean', 'services.useForNavigation'=>'boolean',
			'seo.homepageDescription'=>'text', 'seo.legalName'=>'text', 'seo.foundedYear'=>'number', 'seo.primaryGoal'=>'text',
			'seo.searchIntent'=>'text', 'seo.proofPoints'=>'text', 'seo.allowIndexing'=>'boolean',
			'commercePlan.enabled'=>'boolean', 'commercePlan.expectedProductCount'=>'number',
			'commercePlan.expectedPriceRange.min'=>'number', 'commercePlan.expectedPriceRange.max'=>'number', 'commercePlan.hasBundles'=>'boolean',
			'commercePlan.shippingModel'=>'text', 'commercePlan.typicalShippingCharge'=>'number', 'commercePlan.currency'=>'text',
			'commercePlan.currencyPosition'=>'text', 'commercePlan.weightUnit'=>'text', 'commercePlan.dimensionUnit'=>'text',
			'commercePlan.sellingLocations.mode'=>'text', 'commercePlan.sellingLocations.countries'=>'list', 'commercePlan.sellingLocations.excludedCountries'=>'list',
			'commercePlan.shippingLocations.mode'=>'text', 'commercePlan.shippingLocations.countries'=>'list',
		];
		foreach ( [ 'commerce','blog','publication','physical_store','service_area' ] as $activity ) $extra['business.activities.'.$activity] = 'boolean';
		foreach ( [ 'pinned','hearted','popular' ] as $source ) $extra['contentPlan.testimonialSources.'.$source] = 'boolean';
		$fields = [];
		foreach ( $preferred as $path=>$tag ) {
			$kind = preg_match( '/(?:logo|logoInverse|image)$/i', $path ) ? 'image' : ( str_ends_with($tag,'_url') ? 'link' : 'text' );
			$fields[$path] = [ 'tag'=>'{'.$tag.'}', 'kind'=>$kind, 'owner'=>str_starts_with( $tag, 'kiwe_' ) ? 'kiwe-native-owner' : 'wordpress', 'scope'=>'site' ];
		}
		foreach ( $extra as $path=>$kind ) $fields[$path] = [ 'tag'=>'{'.self::tag_name($path).'}', 'kind'=>$kind, 'owner'=>'design-context-native-owner', 'scope'=>'site' ];
		return $fields;
	}

	public static function tag_name( string $path ): string {
		return 'kiwe_context_' . strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', str_replace( '.', '_', $path ) ) );
	}

	public static function definitions(): array {
		$out = [];
		foreach ( self::fields() as $path=>$field ) if ( 'design-context-native-owner' === $field['owner'] ) $out[trim($field['tag'],'{}')] = __( 'Design Context', 'dsa' ) . ' · ' . $path;
		return $out;
	}

	/** Resolve one declared scalar only; never enumerate content or arbitrary profile/option keys. */
	public static function render( string $name, string $context = 'text' ) {
		$field = null; $path = '';
		foreach ( self::fields() as $key=>$definition ) if ( '{'.$name.'}' === $definition['tag'] && 'design-context-native-owner' === $definition['owner'] ) { $field = $definition; $path = $key; break; }
		if ( ! $field ) return null;
		if ( in_array($context,['image','media'],true) && 'image' !== $field['kind'] ) return [];
		if ( 'link' === $context && ! in_array($field['kind'],['image','link'],true) ) return '';
		if ( 'identity.siteIcon' === $path ) $value = (int) get_option('site_icon',0);
		elseif ( 'localization.language' === $path ) $value = get_bloginfo('language');
		elseif ( 'localization.timezone' === $path ) $value = wp_timezone_string();
		elseif ( 'seo.allowIndexing' === $path ) $value = '0' !== (string) get_option('blog_public','1');
		else {
			$profile = self::profile();
			if ( str_starts_with($path,'business.activities.') ) $value = self::activities($profile)[substr($path,20)] ?? false;
			else {
				$parts = explode('.',str_replace('commercePlan.','commerce.',$path));
				$commerce_paths = ['sellingLocations.mode'=>'sellingLocationMode','sellingLocations.countries'=>'sellingCountries','sellingLocations.excludedCountries'=>'excludedSellingCountries','shippingLocations.mode'=>'shippingLocationMode','shippingLocations.countries'=>'shippingCountries'];
				if (str_starts_with($path,'commercePlan.') && isset($commerce_paths[substr($path,13)])) $parts = ['commerce',$commerce_paths[substr($path,13)]];
				if ( in_array($path,['identity.squareLogo','identity.roundLogo'],true) ) $parts[1] .= 'Id';
				$value = $profile;
				foreach ( $parts as $part ) { $value = is_array($value) ? ($value[$part] ?? '') : ''; }
				if ( 'commercePlan.enabled' === $path ) $value = \DSA\Apps\Store_App_Service::enabled() && ! empty($value);
				$native = ['currency'=>'woocommerce_currency','currencyPosition'=>'woocommerce_currency_pos','weightUnit'=>'woocommerce_weight_unit','dimensionUnit'=>'woocommerce_dimension_unit'];
				if ( str_starts_with($path,'commercePlan.') && isset($native[substr($path,13)]) && function_exists('WC') ) $value = get_option($native[substr($path,13)],'');
			}
		}
		if ( 'image' === $field['kind'] ) {
			$id = absint($value); $image = $id && wp_attachment_is_image($id) && \DSA\Onboarding\Context_Selection_Service::public_media(get_post($id));
			return in_array($context,['image','media'],true) ? ($image ? [$id] : []) : ($image ? esc_url_raw((string) wp_get_attachment_url($id)) : '');
		}
		if ( 'boolean' === $field['kind'] ) return ! empty($value) ? '1' : '';
		if ( 'number' === $field['kind'] ) return is_numeric($value) ? (string) max(0,(float)$value) : '';
		if ( 'list' === $field['kind'] ) return implode(', ',array_map('sanitize_text_field',array_slice(is_array($value)?array_filter($value,'is_scalar'):[],0,250)));
		return is_scalar($value) ? sanitize_textarea_field((string)$value) : '';
	}

	/** Explicit independent activities preserve older single-type sites until the owner edits them. */
	public static function activities( array $profile ): array {
		$type = $profile['identity']['siteType'] ?? '';
		$defaults = [ 'commerce'=>'ecommerce'===$type, 'blog'=>! empty($profile['contentPlan']['showBlogRailOnHome']), 'publication'=>'publication'===$type, 'physical_store'=>false, 'service_area'=>false ];
		$stored = is_array($profile['business']['activities'] ?? null) ? $profile['business']['activities'] : [];
		foreach ( $defaults as $key=>&$value ) if (array_key_exists($key,$stored)) $value = ! empty($stored[$key]);
		unset($value); return $defaults;
	}

	public static function contract( array $public_context = [] ): array {
		$commerce = class_exists(\DSA\Apps\Store_App_Service::class) && \DSA\Apps\Store_App_Service::enabled() && class_exists(Commerce_Binding_Service::class) ? (new Commerce_Binding_Service(new \DSA\Commerce\Linked_Products_Service(new \DSA\Settings()),new \DSA\Commerce\Store_Analytics_Service(new \DSA\Settings())))->catalogue() : ['fields'=>[],'collections'=>[]];
		$groups = [];
		if (class_exists(\DSA\Access\Tag_Group_Service::class)) foreach ((new \DSA\Access\Tag_Group_Service())->dynamic_groups() as $id=>$group) foreach (['current'=>'','name'=>'_name','all'=>'_all'] as $key=>$suffix) $groups['tagGroups.group_'.$id.'.'.$key] = ['tag'=>'{kiwe_tag_group_'.$id.$suffix.'}','kind'=>'text','owner'=>'native-tag-group','scope'=>'public-terms','groupId'=>(int)$id];
		$testimonials=class_exists(Context_Collection_Service::class)?Context_Collection_Service::fields():[];
		return [ 'schema'=>self::SCHEMA, 'fields'=>self::fields()+$groups+$commerce['fields']+$testimonials,
			'markers'=>[ 'anchor'=>'data-seam-anchor', 'field'=>'data-seam-field', 'fields'=>'data-seam-fields', 'slots'=>['text','image','link','alt','aria-label','title'], 'authority'=>'seam/seam-map.json', 'preview'=>'Keep actual preview text/src/href. Markers are inert; the closed map owns exact native tags and slots.' ],
			'collections'=>[
				'contentPlan.existingPages'=>['query'=>'post','postType'=>'page','fields'=>['title'=>'{post_title}','url'=>'{post_url}','image'=>'{featured_image}']],
				'contentPlan.plannedPages'=>['policy'=>'planning-only','reason'=>'Unpublished plans are not public records; use the native editor after creation.'],
				'services'=>['query'=>'configured-native-post-type','fields'=>['title'=>'{post_title}','summary'=>'{post_excerpt}','description'=>'{post_content}','image'=>'{featured_image}','url'=>'{post_url}']],
				'about.team.members'=>['query'=>'selected-native-users','fields'=>['id'=>'{kiwe_team_member_id}','name'=>'{kiwe_team_name}','title'=>'{kiwe_team_title}','bio'=>'{kiwe_team_bio}','image'=>'{kiwe_team_image}','linkedin'=>'{kiwe_team_linkedin_url}']],
				'resources'=>['query'=>'public-selected-media','fields'=>['id'=>'{post_id}','title'=>'{post_title}','url'=>'{post_url}']],
				'testimonials'=>['query'=>['objectType'=>'kiwe_testimonials'],'selectionRequired'=>false,'sourcePolicy'=>'contentPlan.testimonialSources','limit'=>100,'fieldsPrefix'=>'testimonials.item','availability'=>'testimonials.available','emptyPolicy'=>'no-results','authority'=>'native public approved feedback, eligible ancestry and configured pinned/team-hearted/popular sources'],
				'brand.colors'=>['query'=>'semantic-role','fields'=>array_filter(self::fields(),static fn($key)=>str_starts_with($key,'brand.colors.'),ARRAY_FILTER_USE_KEY)],
			]+$commerce['collections'],
			'coverage'=>self::coverage($public_context),
			'extensionRule'=>'Every new public Design Context field must declare a scalar binding, native collection binding or explicit metadata/planning policy before release. Never invent tags or expose protected custom metadata.',
		];
	}

	public static function coverage( array $context ): array {
		$fields = self::fields(); $missing = [];
		$metadata = ['schema','complete','scores','privacy','authority','enhancement','services.sourcePostType','services.taxonomies','services.customFields','services.hierarchical','services.items','about.team.members','contentPlan.existingPages','contentPlan.plannedPages','resources','testimonials','brand.colors','about.founder.userId','about.founder.imageId','about.founder.linkedToWordPressUser','identity.logoId','identity.logoInverseId','identity.siteIconId','identity.squareLogoId','identity.roundLogoId'];
		$walk = static function($value,string $path) use (&$walk,&$missing,$fields,$metadata): void {
			if ( isset($fields[$path]) || in_array($path,$metadata,true) ) return;
			if ( is_array($value) ) { foreach($value as $key=>$child) $walk($child,$path ? $path.'.'.$key : (string)$key); }
			elseif ( '' !== $path ) $missing[] = $path;
		};
		$walk($context,''); sort($missing);
		return ['complete'=>!$missing,'uncovered'=>$missing];
	}
}
