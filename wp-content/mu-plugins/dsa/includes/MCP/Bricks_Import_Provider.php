<?php
namespace DSA\MCP;

use DSA\Bricks\Compiler_Import_Plan;
use DSA\Bricks\Compiler_Import_Service;
use DSA\Bricks\Compiler_Package_Reader;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Draft-only access to Kiwe's native importer. */
final class Bricks_Import_Provider {
	public const SCOPE = 'mcp.bricks.import';
	public const STAGE = 'mcp.bricks.import.stage';
	public const PREVIEW = 'mcp.bricks.import.preview';
	private static ?string $attachment = null;

	public static function transfer_available(): bool {
		return defined( Compiler_Import_Service::class . '::MCP_TRANSFER_API' ) && Compiler_Import_Service::MCP_TRANSFER_API === 1;
	}
	private static function object( array $fields ): array {
		return [ 'type'=>'object','properties'=>$fields,'required'=>array_keys( $fields ),'additionalProperties'=>false ];
	}
	private static function text( int $maximum ): array { return [ 'type'=>'string','maxLength'=>$maximum ]; }
	public static function operations( Operation_Registry $registry ): array {
		if ( ! defined( Compiler_Import_Service::class . '::MCP_IMPORT_API' ) || Compiler_Import_Service::MCP_IMPORT_API !== 1 ) return [];
		$can = static fn() => Credentials::actor_allowed( get_current_user_id() ) && Compiler_Import_Plan::allowed();
		$base = [ 'scope'=>self::SCOPE,'accessVersion'=>1,'discoverPermission'=>$can,'permission'=>$can,'outputSchema'=>self::object( [ 'resultJson'=>self::text( 5600 ) ] ) ];
		$job = [ 'job'=>self::text( 32 ),'archiveSha256'=>self::text( 64 ) ];
		$revision = [ 'revision'=>[ 'type'=>'integer','minimum'=>1,'maximum'=>65536 ] ];
		$origin = [ 'beginIntent'=>self::text( 34 ) ];
		$ops = [ 'mcp.bricks.import.status'=>$base + [ 'label'=>'Read the exact privately staged Bricks design and its native review','effect'=>'read','approval'=>'none','inputSchema'=>self::object( $job ),'execute'=>static fn( $a ) => self::result( self::native( $a )['job'] ) ] ];
		if ( self::transfer_available() ) {
			$ops[self::STAGE] = $base + [
				'label'=>'Prepare an isolated Seam ZIP for private draft review',
				'effect'=>'write', 'approval'=>'none',
				'inputSchema'=>self::object( [ 'archiveSha256'=>self::text( 64 ), 'bytes'=>[ 'type'=>'integer', 'minimum'=>1 ] ] ),
				'prepare'=>static fn( $a ) => [
					'summary'=>'Check the exact Seam package for draft import',
					'before'=>$a['bytes'] . ' bytes. ZIP SHA-256: ' . $a['archiveSha256'] . '.',
					'after'=>'Creates a private review of the isolated package.',
					'nativeExpected'=>self::attachment_fingerprint( $a ), 'needsApproval'=>false,
				],
				'current'=>static fn( $a ) => self::attachment_fingerprint( $a ),
				'execute'=>static function ( $a ) {
					self::admit_attachment( $a );
					return self::result( ( new Compiler_Import_Service() )->stage( self::$attachment, $a['archiveSha256'] ) );
				},
			];
			$ops[self::PREVIEW] = $base + [
				'label'=>'Locate one private Bricks draft for visual comparison',
				'effect'=>'read', 'approval'=>'none',
				'inputSchema'=>self::object( $job + $origin + [ 'file'=>self::text( 240 ) ] ),
				'execute'=>static function ( $a ) use ( $registry ) {
					( new Intent_Service( $registry ) )->bricks_origin( $a['beginIntent'], $a['job'], $a['archiveSha256'] );
					$id = ( new Compiler_Import_Service() )->delegated_preview( $a['job'], $a['archiveSha256'], $a['file'] );
					return [ 'resultJson'=>wp_json_encode( [ 'id'=>$id, 'url'=>Bricks_Import_Transport::preview_url() ] ) ];
				},
			];
		}
		foreach ( [
			'begin'=>[ 'approve','Review and start the exact Bricks draft import','required',$job + $revision ],
			'next'=>[ 'step','Continue one native step of this approved Bricks draft import','none',$job + $revision + $origin ],
			'undo'=>[ 'undo','Review conditional recovery for this Bricks draft import','required',$job + $revision + $origin ],
		] as $name => [ $action,$label,$approval,$fields ] ) {
			$ops['mcp.bricks.import.' . $name] = $base + [
				'label'=>$label,'effect'=>'write','approval'=>$approval,'inputSchema'=>self::object( $fields ),
				'prepare'=>static fn( $a ) => self::plan( self::native( $a,$action,$registry ),$action ),
				'current'=>static fn( $a ) => self::native( $a,$action,$registry )['expected'],
				'execute'=>static function ( $a,$expected ) use ( $action,$registry ) {
					self::require_origin( $a,$action,$registry );
					$job = ( new Compiler_Import_Service() )->advance( $a['job'],$a['revision'],$action,[ 'hash'=>$a['archiveSha256'],'expected'=>$expected ] );
					return self::result( $job );
				},
			];
		}
		return $ops;
	}
	private static function attachment_fingerprint( array $a ): string {
		if ( $a['bytes'] > Compiler_Package_Reader::MAX_BYTES || ! preg_match( '/^[a-f0-9]{64}$/D', $a['archiveSha256'] ) ) throw new \RuntimeException( 'Use the SHA-256 and size of the exact Seam ZIP within the 128 MB limit.' );
		return hash( 'sha256', $a['archiveSha256'] . ':' . $a['bytes'] );
	}
	public static function with_attachment( string $file, callable $apply ): array {
		self::$attachment = $file;
		try { return $apply(); }
		finally { self::$attachment = null; }
	}
	public static function admit_attachment( array $a ): void {
		$file = self::$attachment;
		if ( $file === null || ! is_uploaded_file( $file ) || filesize( $file ) !== $a['bytes'] || ! hash_equals( $a['archiveSha256'], (string) hash_file( 'sha256', $file ) ) ) {
			throw new \RuntimeException( 'Send the exact ZIP to the Kiwe package upload for this review.' );
		}
	}
	private static function require_origin( array $a,string $action,Operation_Registry $registry ): void {
		if ( in_array( $action,[ 'step','undo' ],true ) ) ( new Intent_Service( $registry ) )->bricks_origin( $a['beginIntent'],$a['job'],$a['archiveSha256'] );
	}
	private static function native( array $a,string $action = '',?Operation_Registry $registry = null ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D',$a['job'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$a['archiveSha256'] ) ) throw new \RuntimeException( 'Use the exact native import ID and ZIP fingerprint shown in the Framework review.' );
		if ( $registry ) self::require_origin( $a,$action,$registry );
		$result = ( new Compiler_Import_Service() )->delegated_review( $a['job'],$a['archiveSha256'],$action );
		if ( $action && $result['job']['revision'] !== $a['revision'] ) throw new \RuntimeException( 'Saved progress changed. Read the current import before proposing another action.' );
		return $result;
	}
	private static function plan( array $native,string $action ): array {
		$j = $native['job'];
		$description = $j['templateTotal'] . ' drafts, ' . $j['mediaTotal'] . ' media dependencies, ' . count( $j['styles'] ) . ' project style additions, ' . $j['stylesReused'] . ' exact style reuses.';
		return [
			'summary'=>match( $action ) { 'approve'=>'Import the reviewed design as Bricks drafts', 'step'=>'Continue one approved native import step', 'undo'=>'Undo the exact Bricks draft import' },
			'before'=>'Native job ' . $j['id'] . ' revision ' . $j['revision'] . ' (' . $j['status'] . '). ZIP SHA-256: ' . $j['archiveSha256'] . '.',
			'after'=>match( $action ) { 'approve'=>$description . ' Active Theme Styles, shared tokens, existing pages and Woo routes stay in place. Nothing is published. The next call applies one bounded native step.', 'step'=>'At most one native asset, phase transition, style transaction or draft is applied; no publication.', 'undo'=>'Only unchanged drafts and eligible project style additions are recovered. Newer edits and imported media remain. Review any retained work in Framework.' },
			'nativeExpected'=>$native['expected'],'needsApproval'=>$action !== 'step',
		];
	}
	private static function result( array $j ): array {
		$url = add_query_arg( [ 'page'=>'kiwe-framework','compiler-import'=>$j['id'] ],admin_url( 'admin.php' ) ) . '#kiwe-compiler-import';
		$result = [ 'schema'=>'kiwe.mcp-bricks-import.v1','job'=>$j['id'],'revision'=>$j['revision'],'status'=>$j['status'],'archiveSha256'=>$j['archiveSha256'],'sourceHash'=>$j['sourceHash'],'nativeReviewUrl'=>$url,
			'mediaTotal'=>$j['mediaTotal'],'mediaDone'=>$j['mediaDone'],'templateTotal'=>$j['templateTotal'],'templatesDone'=>$j['templatesDone'],'styleAdditions'=>count( $j['styles'] ),'stylesReused'=>$j['stylesReused'],'blockerCount'=>count( $j['blockers'] ),'warningCount'=>count( $j['warnings'] ),'needsAttention'=>! empty( $j['error'] ) || $j['status'] === 'recovery-review','publishesWebsite'=>false ];
		// The native link shows the whole plan. Do not copy arbitrary imported HTML, strings or recovery metadata.
		$result['reviewTemplates'] = array_map( static fn( $t ) => [ 'title'=>Guidance_Service::text( $t['title'],120 ),'type'=>$t['type'],'elements'=>$t['elements'],'conditions'=>$t['conditions'] ?? 0 ],array_slice( $j['reviewTemplates'],0,8 ) );
		$result['drafts'] = array_map( static fn( $d ) => [ 'id'=>$d['id'],'title'=>Guidance_Service::text( $d['title'],120 ) ],array_slice( $j['drafts'],0,8 ) );
		return [ 'resultJson'=>wp_json_encode( $result,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ];
	}
}
