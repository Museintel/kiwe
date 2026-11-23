<?php

namespace DSA\Bricks;

use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Place one reviewed document through native WordPress and Bricks records. */
final class Document_Publication_Service {
	private const PREFIX = '_kiwe_start_document_';
	private const MARKER = '_kiwe_start_placement';

	public static function available(): bool {
		return defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) && Homepage_Publication_Service::available()
			&& is_callable( [ \Bricks\Database::class, 'get_bricks_data_key' ] );
	}

	public static function allowed(): bool {
		return self::available() && Homepage_Publication_Service::allowed() && current_user_can( 'publish_posts' );
	}

	private function fail( string $message ): never {
		throw new Compiler_Package_Error( $message );
	}

	private function hash( $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}

	private function key( array $args ): string {
		return self::PREFIX . $args['target'];
	}

	private function record( array $args ): ?array {
		if ( ! self::allowed() ) $this->fail( 'Only the WordPress site owner can place this document.' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $args['job'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/D', $args['archiveSha256'] ?? '' )
			|| ! preg_match( '/^[a-z][a-z0-9-]{0,23}$/D', $args['target'] ?? '' ) ) {
			$this->fail( 'Use the exact import and target from the approved document.' );
		}
		$stored = get_option( $this->key( $args ), '' );
		if ( $stored === '' ) return null;
		$result = is_string( $stored ) ? Secret_Store::decrypt_with_status( $stored ) : [];
		$record = ( $result['status'] ?? '' ) === 'ok' ? json_decode( $result['value'], true, 64 ) : null;
		if ( ! is_array( $record ) || ( $record['schema'] ?? 0 ) !== 1 || ! isset( $record['site'], $record['args']['target'] ) ) {
			$this->fail( 'The saved publication recovery could not be verified.' );
		}
		if ( $record['site'] !== home_url( '/' ) ) return null;
		if ( $record['args']['target'] !== $args['target'] ) $this->fail( 'This publication does not belong to the selected route.' );
		return $record;
	}

	private function save( array $record ): void {
		$json = json_encode( $record, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		if ( strlen( $json ) > 8388608 || json_decode( $json, true, 64, JSON_THROW_ON_ERROR ) !== $record ) {
			$this->fail( 'WordPress could not preserve the exact layout for recovery.' );
		}
		$cipher = Secret_Store::encrypt( $json );
		if ( $cipher === '' ) $this->fail( 'The site’s encrypted publication recovery is unavailable.' );
		update_option( $this->key( $record['args'] ), $cipher, false );
		if ( $this->record( $record['args'] ) !== $record ) $this->fail( 'WordPress could not save the publication recovery.' );
	}

	private function source( array $args, array $route, bool $retain = false ): array {
		$importer = new Compiler_Import_Service( \DSA\Plugin::instance()->settings() );
		$data = $importer->document_content( $args['job'], $args['archiveSha256'], $args['draftId'],
			Document_Routes::source_type( $args['target'] ), $args['target'], $retain );
		$loop = '';
		foreach ( $data as $element ) {
			if ( ! empty( $element['settings']['query']['is_archive_main_query'] ) ) {
				if ( $loop !== '' ) $this->fail( 'Use one main query for this listing.' );
				$loop = $element['id'];
			}
		}
		$page_layout = $route['page'] && ( in_array( $route['target'], [ 'about','contact','terms','privacy' ], true )
			|| str_starts_with( $route['target'], 'page-' ) );
		$bricks_body = $page_layout && \Bricks\Database::get_data( $route['page'], 'content' );
		$includes_body = false;
		foreach ( $data as &$element ) {
			if ( $element['name'] === 'pagination' ) {
				if ( $loop === '' ) $this->fail( 'The document pagination needs its native results loop.' );
				$element['settings']['queryId'] = $loop;
			}
			if ( $page_layout && $element['name'] === 'post-content' ) {
				$includes_body = true;
				if ( $bricks_body ) $element['settings']['dataSource'] = 'bricks';
			}
		}
		unset( $element );
		if ( $page_layout && ! $includes_body && ( $bricks_body || get_post_field( 'post_content', $route['page'], 'raw' ) !== '' ) ) $this->fail( 'Keep the existing page body in the native Post Content element.' );
		return $data;
	}

	private function read_template( int $id, string $key ): array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== BRICKS_DB_TEMPLATE_SLUG || $post->post_password !== '' || ! current_user_can( 'edit_post', $id ) ) {
			$this->fail( 'Check the native template’s current status and editing permission.' );
		}
		$layout = get_post_meta( $id, $key, false );
		if ( count( $layout ) > 1 || ( $layout && ! is_array( $layout[0] ) ) ) $this->fail( 'The native template has unexpected layout storage.' );
		return [
			'body'=>$layout, 'status'=>$post->post_status, 'title'=>$post->post_title, 'name'=>$post->post_name,
			'type'=>get_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, true ),
			'settings'=>get_post_meta( $id, BRICKS_DB_TEMPLATE_SETTINGS, true ),
			'pageSettings'=>get_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, true ),
			'presentation'=>get_post_meta( $id, Document_Routes::SECTION_META, true ),
			'marker'=>get_post_meta( $id, self::MARKER, true ),
		];
	}

	private function existing( array $route ): int {
		if ( $route['slug'] !== '' ) return 0;
		$target = $route['target'];
		$wanted = Document_Routes::condition( $target, $route['page'] );
		$matches = [];
		$archives = [ 'archive','wc_archive' ];
		$selectors = match ( $route['type'] ) {
			'content','wc_product' => [ 'content','wc_product' ],
			'archive','wc_archive' => $archives,
			default => [ $route['type'] ],
		};
		$candidates = get_posts( [
			'post_type'=>BRICKS_DB_TEMPLATE_SLUG, 'post_status'=>'publish', 'numberposts'=>-1,
			'fields'=>'ids', 'meta_key'=>BRICKS_DB_TEMPLATE_TYPE, 'meta_value'=>$selectors, 'meta_compare'=>'IN',
		] );
		foreach ( $candidates as $id ) {
			$settings = get_post_meta( $id, BRICKS_DB_TEMPLATE_SETTINGS, true );
			$current = (array) ( $settings['templateConditions'] ?? [] );
			if ( $route['section'] ) {
				$marker = get_post_meta( $id, Document_Routes::SECTION_META, true );
				if ( ( $marker['site'] ?? '' ) === home_url( '/' ) && ( $marker['target'] ?? '' ) === $target
					&& ( $marker['page'] ?? 0 ) === $route['page'] && $current === $wanted ) $matches[] = (int) $id;
				continue;
			}
			$may_be_default = in_array( $target, [ 'header','footer','search','not-found' ], true )
				&& ! \Bricks\Database::get_setting( 'defaultTemplatesDisabled', false );
			if ( $current == $wanted || ( $may_be_default && ! $current ) ) $matches[] = (int) $id;
			elseif ( $route['page'] ) {
				foreach ( $current as $condition ) {
					if ( ( $condition['main'] ?? '' ) === 'ids' && ! isset( $condition['exclude'] )
						&& in_array( $route['page'], (array) ( $condition['ids'] ?? [] ) ) ) {
						$this->fail( 'A Bricks template shares this condition with other pages. Narrow its native assignment before publishing here.' );
					}
				}
			}
		}
		if ( count( $matches ) > 1 ) $this->fail( 'More than one template owns this native condition. Resolve the overlapping assignments in Bricks.' );
		return $matches[0] ?? 0;
	}

	private function description( array $route, int $id, bool $restore ): array {
		$address = $route['url'] ?? ( $route['slug'] !== '' ? 'the page named ' . $route['slug'] : $route['label'] );
		$before = $route['label'] . ' · ' . $address . ( $id ? ' · Bricks template ' . $id : '' );
		if ( $restore ) return [ $before, 'Restore the previous native layout or return a newly created template to draft.' ];
		if ( $route['section'] ) return [ $before, 'Publish this presentation section above the existing WooCommerce content. Keep the exact design and retire its temporary import.' ];
		return [ $before, ( $id ? 'Update the layout of the matched template and preserve its native assignment.' :
			'Publish one native Bricks template at the reviewed assignment.' ) . ' Keep the exact design and retire its temporary import.'
			. ( $route['slug'] !== '' ? ' Create the WordPress page at ' . $address . '.' : '' ) ];
	}

	public function plan( array $args, bool $restore ): array {
		ksort( $args );
		$previous = $this->record( $args );
		if ( $restore ) {
			if ( ! $previous || $previous['args'] !== $args || ! in_array( $previous['phase'], [ 'published','restoring' ], true ) ) {
				$this->fail( 'Choose the current published design to restore its previous layout.' );
			}
			$this->check_recovery( $previous );
			$result = $previous;
		} elseif ( $previous && in_array( $previous['phase'], [ 'placing','restoring' ], true ) ) {
			if ( $previous['phase'] !== 'placing' || $previous['args'] !== $args ) {
				$this->fail( 'Complete the saved action for this route before beginning another publication.' );
			}
			$result = $previous;
		} elseif ( $previous && $previous['args'] === $args && $previous['phase'] === 'published' ) {
			$this->fail( 'This exact document is already published. Read its receipt or choose restoration.' );
		} elseif ( $previous && $previous['args'] === $args && $previous['phase'] === 'restored' ) {
			$previous['phase'] = 'restoring';
			$this->check_recovery( $previous );
			unset( $previous['placement'], $previous['installed'] );
			$previous['phase'] = 'placing';
			$result = $previous;
		} else {
			$owned_page = $previous && $previous['phase'] === 'published' ? ( $previous['createdPage'] ?: ( $previous['route']['ownedPage'] ?? 0 ) ) : 0;
			$reusable = [];
			if ( $previous && $previous['phase'] === 'restored' && $previous['createdPage'] && ! $args['pageId'] ) {
				$prior = $previous;
				$prior['phase'] = 'restoring';
				$this->check_recovery( $prior );
				$reusable = [ 'id'=>$prior['createdPage'], 'slug'=>$prior['route']['slug'], 'marker'=>$prior['checkpoint'] ];
			}
			$route = Document_Routes::resolve( $args['target'], $args['pageId'], $owned_page, $reusable );
			$route['ownedPage'] = $owned_page;
			if ( $reusable && $route['slug'] !== '' ) $route['reusable'] = $reusable;
			$id = $this->existing( $route );
			$key = \Bricks\Database::get_bricks_data_key( $route['type'] );
			$before = $id ? $this->read_template( $id, $key ) : null;
			$data = $this->source( $args, $route );
			$result = [
				'schema'=>1,'site'=>home_url( '/' ),'args'=>$args,'route'=>$route,'key'=>$key,
				'existing'=>$id,'before'=>$before,'after'=>[ $data ],'phase'=>'placing',
				'checkpoint'=>$this->hash( [ $args, $route, $id, $before, $data, $previous['checkpoint'] ?? '' ] ),
			];
		}
		[ $before, $after ] = $this->description( $result['route'], $result['existing'], $restore );
		return [ 'expected'=>$this->hash( [ $result['checkpoint'], $restore ] ),'record'=>$result,'beforeText'=>$before,'afterText'=>$after ];
	}

	private function post_status( int $id, string $status ): void {
		if ( get_post_status( $id ) === $status ) return;
		if ( is_wp_error( wp_update_post( [ 'ID'=>$id, 'post_status'=>$status ], true ) ) || get_post_status( $id ) !== $status ) {
			$this->fail( 'WordPress did not save the reviewed publishing status.' );
		}
	}

	private function native_record( array $record, string $type ): int {
		$route = $record['route'];
		$owner = get_current_user_id();
		$slug = $type === 'page' ? $route['slug'] : 'kiwe-start-' . substr( $record['checkpoint'], 0, 24 );
		$found = get_page_by_path( $slug, OBJECT, $type );
		if ( $found ) {
			$marker = get_post_meta( $found->ID, self::MARKER, true );
			$reuse = $type === 'page' && (int) $found->ID === ( $route['reusable']['id'] ?? 0 )
				&& $found->post_status === 'draft' && $marker === $route['reusable']['marker'];
			if ( (int) $found->post_author !== $owner || ! in_array( $found->post_status, [ 'draft','publish' ], true )
				|| ( ! $reuse && $marker !== $record['checkpoint'] ) ) {
				$this->fail( 'The reviewed address is now occupied. WordPress preserved that record.' );
			}
			if ( $type === 'page' && ( $found->post_title !== $route['label'] || $found->post_content !== ''
				|| $found->post_excerpt !== '' || \Bricks\Database::get_data( $found->ID, 'content' ) ) ) {
				$this->fail( 'The previously created page has newer content. Review the current page first.' );
			}
			if ( $reuse ) $this->meta( $found->ID, self::MARKER, $record['checkpoint'], $marker );
			return (int) $found->ID;
		}
		$id = wp_insert_post( [
			'post_type'=>$type,'post_name'=>$slug,'post_title'=>$route['label'],
			'post_status'=>'draft','post_author'=>$owner,'meta_input'=>[ self::MARKER=>$record['checkpoint'] ],
		], true );
		if ( is_wp_error( $id ) || ! $id || get_post_field( 'post_name', $id, 'raw' ) !== $slug ) {
			$this->fail( 'WordPress could not create the reviewed draft at its saved address.' );
		}
		return $id;
	}

	private function meta( int $id, string $key, $value, $previous ): void {
		$current = get_post_meta( $id, $key, true );
		if ( $current === $value ) return;
		if ( $current !== $previous ) $this->fail( 'A newer native layout or assignment was preserved. Review it in WordPress.' );
		if ( $value === '' ) delete_post_meta( $id, $key, $previous );
		else update_post_meta( $id, $key, wp_slash( $value ), $previous );
		if ( get_post_meta( $id, $key, true ) !== $value ) $this->fail( 'The native template did not retain its reviewed settings.' );
	}

	private function sync( int $id, array $record, bool $restore ): void {
		$key = $record['key'];
		$prior = $record['before']['body'][0] ?? '';
		$data = $record['after'][0];
		$this->meta( $id, $key, $restore ? $prior : $data, $restore ? $data : $prior );
		if ( \Bricks\Database::get_setting( 'cssLoading' ) === 'file' ) {
			$area = match ( $record['route']['type'] ) { 'header', 'footer' => $record['route']['type'], default => 'content' };
			\Bricks\Assets_Files::generate_post_css_file( $id, $area, $restore ? (array) $prior : $data );
		}
		clean_post_cache( $id );
	}

	private function assign_blog( int $page, int $previous ): void {
		$current = (int) get_option( 'page_for_posts' );
		if ( get_option( 'show_on_front' ) !== 'page' || ( $current !== $previous && $current !== $page ) ) {
			$this->fail( 'The WordPress Reading assignments changed. Review them before continuing.' );
		}
		update_option( 'page_for_posts', $page );
		if ( (int) get_option( 'page_for_posts' ) !== $page ) $this->fail( 'WordPress did not retain the reviewed Blog address.' );
	}

	private function published( array $record ): void {
		$route = $record['route'];
		if ( $route['slug'] === '' ) {
			$current = Document_Routes::resolve( $route['target'], $record['args']['pageId'], $route['ownedPage'] );
			if ( $current + [ 'ownedPage'=>$route['ownedPage'] ] !== $route ) $this->fail( 'The native route changed during publication.' );
		}
		$page = $route['slug'] === '' ? $route['page'] : $this->native_record( $record, 'page' );
		$id = $record['existing'] ?: $this->native_record( $record, BRICKS_DB_TEMPLATE_SLUG );
		if ( $record['existing'] ) {
			$current = $this->read_template( $id, $record['key'] );
			$allowed = $record['before'];
			$allowed['body'] = $current['body'];
			if ( $current !== $allowed ) $this->fail( 'The native template changed after review.' );
		} else {
			$this->meta( $id, BRICKS_DB_TEMPLATE_TYPE, $route['type'], '' );
			$this->meta( $id, BRICKS_DB_TEMPLATE_SETTINGS, [ 'templateConditions'=>Document_Routes::condition( $route['target'], $page ) ], '' );
			if ( $route['section'] ) {
				$this->meta( $id, Document_Routes::SECTION_META, [ 'site'=>$record['site'],'target'=>$route['target'],'page'=>$page ], '' );
			}
		}
		$this->sync( $id, $record, false );
		$this->post_status( $id, 'publish' );
		if ( $route['slug'] !== '' ) {
			$this->post_status( $page, 'publish' );
			if ( $route['target'] === 'blog' ) $this->assign_blog( $page, 0 );
			if ( $route['url'] && get_permalink( $page ) !== $route['url'] ) $this->fail( 'The published page address changed. Check the reviewed assignments.' );
			$record['route']['url'] = get_permalink( $page );
		}
		$record['placement'] = $id;
		$record['createdPage'] = $route['slug'] === '' ? 0 : $page;
		$record['installed'] = $this->read_template( $id, $record['key'] );
		$record['phase'] = 'published';
		$this->save( $record );
		$this->purge_route( $record );
		if ( ! empty( $record['importCleanupPending'] ) ) {
			$args = $record['args'];
			$importer = new Compiler_Import_Service( \DSA\Plugin::instance()->settings() );
			try {
				$importer->retire_placed_document( $args['job'], $args['archiveSha256'], $args['draftId'] );
				$record['importCleanupPending'] = false;
				$this->save( $record );
			} catch ( Compiler_Package_Error | \DSA\Backup\Archive_Error $error ) {
				// WordPress has already saved and read back the placement. Framework can finish transport cleanup.
			}
		}
	}

	private function check_recovery( array $record ): void {
		$layout = $this->read_template( $record['placement'], $record['key'] );
		$expected = $record['installed'];
		if ( $record['phase'] === 'restoring' ) {
			if ( $record['existing'] && $layout['body'] === $record['before']['body'] ) $expected['body'] = $layout['body'];
			if ( ! $record['existing'] && $layout['status'] === 'draft' ) $expected['status'] = 'draft';
		}
		if ( $layout !== $expected ) $this->fail( 'The published native template has newer edits. Review those edits before restoring.' );
		$page = $record['createdPage'];
		if ( $page ) {
			$post = get_post( $page );
			if ( ! $post || $post->post_type !== 'page' || $post->post_name !== $record['route']['slug']
				|| $post->post_title !== $record['route']['label'] || $post->post_content !== '' || $post->post_excerpt !== ''
				|| get_post_meta( $page, self::MARKER, true ) !== $record['checkpoint']
				|| \Bricks\Database::get_data( $page, 'content' ) || ! current_user_can( 'edit_post', $page )
				|| ( $post->post_status !== 'publish' && ( $record['phase'] !== 'restoring' || $post->post_status !== 'draft' ) ) ) {
				$this->fail( 'The created WordPress page has newer edits or a new assignment. Its current state was preserved.' );
			}
			foreach ( Document_Routes::page_options() as $role=>$option ) {
				if ( (int) get_option( $option ) === $page && $role !== $record['route']['target'] ) {
					$this->fail( 'The created page now serves a different native route.' );
				}
			}
		}
	}

	private function restore( array $record ): void {
		$this->check_recovery( $record );
		$record['phase'] = 'restoring';
		$this->save( $record );
		$page = $record['createdPage'];
		if ( $page && $record['route']['target'] === 'blog' ) $this->assign_blog( 0, $page );
		$id = $record['placement'];
		if ( $record['existing'] ) $this->sync( $id, $record, true );
		else $this->post_status( $id, 'draft' );
		if ( $page ) $this->post_status( $page, 'draft' );
		$record['phase'] = 'restored';
		$this->save( $record );
		$this->purge_route( $record );
	}

	private function purge_route( array $record ): void {
		$page = $record['createdPage'] ?: $record['route']['page'];
		if ( $page ) do_action( 'litespeed_purge_post', $page );
		elseif ( ! $record['route']['section'] ) do_action( 'litespeed_purge_all' );
	}

	public function status( array $args ): array {
		ksort( $args );
		$record = $this->record( $args );
		$status = 'draft_ready';
		$url = null;
		$id = 0;
		if ( $record && $record['args'] === $args ) {
			$url = $record['route']['url'];
			$id = $record['placement'] ?? 0;
			$status = match ( $record['phase'] ) {
				'published'=>'published','restoring'=>'restore_in_progress','restored'=>'restored',default=>'draft_ready',
			};
			if ( $status === 'published' ) {
				try { $this->check_recovery( $record ); }
				catch ( Compiler_Package_Error $error ) { $status = 'review_current_document'; }
			}
		}
		return [ 'schema'=>'kiwe.bricks-document-publication.v1','status'=>$status,'placementId'=>$id,'url'=>$url,
			'importCleanupPending'=> (bool) ( $record && $record['args'] === $args && ! empty( $record['importCleanupPending'] ) ) ] + $args;
	}

	public function apply( array $args, string $expected, bool $restore ): array {
		$plan = $this->plan( $args, $restore );
		if ( ! hash_equals( $plan['expected'], $expected ) ) $this->fail( 'The native assignment or layout changed after approval.' );
		$record = $plan['record'];
		if ( $restore ) $this->restore( $record );
		else {
			if ( empty( $record['sourceRetained'] ) ) {
				if ( [ $this->source( $args, $record['route'], true ) ] !== $record['after'] ) $this->fail( 'The native draft changed after approval.' );
				$record['sourceRetained'] = true;
				$record['importCleanupPending'] = true;
			}
			$this->save( $record );
			$this->published( $record );
		}
		return $this->status( $args );
	}
}
