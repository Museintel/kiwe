<?php
namespace DSA\Bricks;

use DSA\Onboarding\Context_Selection_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Images are rendered with the native filter, including its AJAX result. No extra request or observer. */
final class Filter_Image_Service {
	public function register(): void {
		add_filter( 'bricks/filter_element/controls', [ $this, 'controls' ], 20, 2 );
		add_filter( 'bricks/frontend/render_element', [ $this, 'render' ], 25, 2 );
		add_action( 'wp_enqueue_scripts', static function(): void {
			wp_add_inline_style( 'bricks-frontend', '.brxe-filter-radio[data-catimg-enable="1"] .brx-option-text,.brxe-filter-checkbox[data-catimg-enable="1"] .brx-option-text{display:inline-flex;align-items:center}.brx-term-thumb{object-fit:cover;flex-shrink:0;width:32px;height:32px}' );
		}, 25 );
	}

	private function supported( $element ): bool {
		return is_object( $element ) && in_array( $element->name ?? '', [ 'filter-radio', 'filter-checkbox' ], true );
	}

	public function controls( array $controls, $element ): array {
		if ( ! $this->supported( $element ) ) return $controls;
		$required = [ 'catImgEnable', '!=', '' ];
		$controls['catImgSep'] = [ 'type'=>'separator', 'label'=>__( 'Category image', 'dsa' ) ];
		$controls['catImgEnable'] = [ 'type'=>'checkbox', 'label'=>__( 'Show category images', 'dsa' ) ];
		$controls['catImgSourceType'] = [ 'type'=>'select', 'label'=>__( 'Image source', 'dsa' ), 'options'=>[ 'woo_thumbnail'=>__( 'Category thumbnail', 'dsa' ), 'meta_key'=>__( 'Term image field', 'dsa' ), 'dynamic_tag'=>__( 'Dynamic image', 'dsa' ) ], 'placeholder'=>__( 'Category thumbnail', 'dsa' ), 'required'=>$required ];
		$controls['catImgMetaKey'] = [ 'type'=>'text', 'label'=>__( 'Term image field', 'dsa' ), 'hasDynamicData'=>false, 'required'=>[ $required, ['catImgSourceType','=','meta_key'] ] ];
		$controls['catImgDynamicTag'] = [ 'type'=>'text', 'label'=>__( 'Dynamic image', 'dsa' ), 'hasDynamicData'=>true, 'required'=>[ $required, ['catImgSourceType','=','dynamic_tag'] ] ];
		$controls['catImgPosition'] = [ 'type'=>'select', 'label'=>__( 'Image position', 'dsa' ), 'options'=>['row'=>__('Left','dsa'),'row-reverse'=>__('Right','dsa'),'column'=>__('Top','dsa'),'column-reverse'=>__('Bottom','dsa')], 'placeholder'=>__('Left','dsa'), 'required'=>$required, 'css'=>[['selector'=>'& .brx-option-text','property'=>'flex-direction']] ];
		$controls['catImgGap'] = [ 'type'=>'number', 'units'=>true, 'label'=>__('Gap','dsa'), 'required'=>$required, 'css'=>[['selector'=>'& .brx-option-text','property'=>'gap']] ];
		$controls['catImgSize'] = [ 'type'=>'number', 'units'=>true, 'label'=>__('Image size','dsa'), 'required'=>$required, 'css'=>array_map(static fn($p)=>['selector'=>'& .brx-term-thumb','property'=>$p],['width','height','min-width']) ];
		foreach ( [ 'Padding'=>['spacing','padding'], 'Background'=>['color','background-color'], 'Border'=>['border','border'] ] as $name=>$style ) {
			$controls['catImg'.$name] = [ 'type'=>$style[0], 'label'=>sprintf(__('Image %s','dsa'),strtolower($name)), 'required'=>$required, 'css'=>[['selector'=>'& .brx-term-thumb','property'=>$style[1]]] ];
		}
		return $controls;
	}

	private function image( $raw, int $depth = 0 ): string {
		if ( $depth > 3 ) return '';
		if ( is_numeric( $raw ) ) {
			$post = get_post( (int) $raw );
			return $post && Context_Selection_Service::public_media( $post ) && wp_attachment_is_image( $post->ID ) ? (string) wp_get_attachment_image_url( $post->ID, 'thumbnail' ) : '';
		}
		if ( $raw instanceof \WP_Post ) return $this->image( $raw->ID, $depth + 1 );
		if ( is_array( $raw ) ) {
			foreach ( ['ID','id','attachment_id'] as $key ) if ( ! empty( $raw[$key] ) ) return $this->image( $raw[$key], $depth+1 );
			foreach ( ['url','src','guid','file'] as $key ) if ( ! empty( $raw[$key] ) ) return $this->image( $raw[$key], $depth+1 );
			return '';
		}
		if ( ! is_string($raw) || ! preg_match('~^https?://~i',$raw) ) return '';
		$url = esc_url_raw($raw,['https','http']);
		// Use native visibility for local images, including custom image-field URLs.
		if ( $url && wp_parse_url($url,PHP_URL_HOST) === wp_parse_url(home_url(),PHP_URL_HOST) ) {
			$id = attachment_url_to_postid($url);
			return $id ? $this->image($id,$depth+1) : '';
		}
		return $url;
	}

	public function render( string $html, $element ): string {
		if ( ! $this->supported($element) || empty($element->settings['catImgEnable']) || strlen($html)>262144 ) return $html;
		$settings = $element->settings;
		$taxonomy = sanitize_key($settings['filterTaxonomy']??'');
		if ( ! $taxonomy || ! taxonomy_exists($taxonomy) || ! is_taxonomy_viewable($taxonomy) ) return $html;
		// Root marker preserves previously generated native styles; labels/inputs/counts stay native.
		$html = preg_replace('/^(\s*<ul\b)(?![^>]*\bdata-catimg-enable=)/i','$1 data-catimg-enable="1"',$html,1);
		$resolved=[]; $count=0;
		return preg_replace_callback('~<label\b[^>]*>[\s\S]*?</label>~i',function($match)use($settings,$taxonomy,&$resolved,&$count){
			$label=$match[0];
			if(++$count>200 || str_contains($label,'brx-term-thumb') || !preg_match('~<input\b[^>]*\bvalue=["\x27]([^"\x27]*)["\x27]~i',$label,$value))return $label;
			$value=html_entity_decode($value[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
			if($value==='')return $label;
			if(!array_key_exists($value,$resolved)){
				$term=ctype_digit($value)?get_term((int)$value,$taxonomy):get_term_by('slug',$value,$taxonomy);
				$url='';
				if($term && !is_wp_error($term)){
					$source=$settings['catImgSourceType']??'woo_thumbnail';
					if($source==='meta_key'&&!empty($settings['catImgMetaKey']))$url=$this->image(get_term_meta($term->term_id,sanitize_key($settings['catImgMetaKey']),true));
					if($source==='dynamic_tag'&&!empty($settings['catImgDynamicTag'])&&function_exists('bricks_render_dynamic_data'))$url=$this->image(bricks_render_dynamic_data((string)$settings['catImgDynamicTag'],(int)$term->term_id,'image'));
					if(!$url)$url=$this->image(get_term_meta($term->term_id,'thumbnail_id',true));
				}
				$resolved[$value]=$url;
			}
			if(!$resolved[$value])return $label;
			$image='<img class="brx-term-thumb" src="'.esc_url($resolved[$value]).'" alt="" loading="lazy" decoding="async">';
			return preg_replace_callback('~<span\b[^>]*class=["\x27][^"\x27]*\bbrx-option-text\b[^"\x27]*["\x27][^>]*>~i',static fn($span)=>$span[0].$image,$label,1);
		},$html)??$html;
	}
}
