<?php
namespace DSA\Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit authoring only. Reads bounded ZIP members; never extracts or fetches URLs. */
final class Compiler_Package_Reader {
	public const MAX_BYTES = 134217728;
	public const MAX_ASSET = 16777216;
	private \ZipArchive $zip;
	private array $index = [];
	private array $integrity = [];
	private string $prefix = '';
	/** $reviewed_inventory is accepted only from the caller's encrypted private job checkpoint. */
	public function __construct( string $path, ?array $reviewed_inventory = null ) {
		if ( ! class_exists( '\ZipArchive' ) || is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES ) throw new Compiler_Package_Error( 'Choose a compiler dependency ZIP of up to 128 MB.' );
		$this->zip = new \ZipArchive();
		if ( true !== $this->zip->open( $path, \ZipArchive::RDONLY ) ) throw new Compiler_Package_Error( 'The compiler package could not be opened.' );
		try {
			if ( $this->zip->numFiles < 6 || $this->zip->numFiles > 602 ) throw new Compiler_Package_Error( 'The compiler package has too many or too few files.' );
			$total = 0; $folded = [];
			for ( $i = 0; $i < $this->zip->numFiles; $i++ ) {
				$stat = $this->zip->statIndex( $i ); $name = is_array( $stat ) ? (string) $stat['name'] : '';
				if ( ! self::safe_path( $name ) || isset( $folded[strtolower( $name )] ) || ( $stat['size'] ?? -1 ) < 0 || $stat['size'] > 67108864 || ! empty( $stat['encryption_method'] ) ) throw new Compiler_Package_Error( 'A compiler package member is unsafe, duplicated, encrypted or too large.' );
				$total += $stat['size']; if ( $total > self::MAX_BYTES ) throw new Compiler_Package_Error( 'The expanded compiler package exceeds 128 MB.' );
				$opsys = 0; $attr = 0;
				if ( $this->zip->getExternalAttributesIndex( $i, $opsys, $attr ) && 3 === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) throw new Compiler_Package_Error( 'Linked files are not accepted in compiler packages.' );
				$this->index[$name] = $stat; $folded[strtolower( $name )] = true;
			}
			$manifests = array_values( array_filter( array_keys( $this->index ), static fn( $name ) => in_array( $name, [ 'package-integrity.json','framework/package-integrity.json' ], true ) ) );
			if ( 1 !== count( $manifests ) ) throw new Compiler_Package_Error( 'Export a new Framework package with its dependency inventory.' );
			$this->prefix = dirname( $manifests[0] ) === '.' ? '' : 'framework/';
			$this->integrity = $reviewed_inventory ?? $this->json( $manifests[0], 1048576 );
			$this->verify_inventory( $manifests[0],null === $reviewed_inventory );
		} catch ( \Throwable $error ) { $this->zip->close(); throw $error; }
	}
	public function __destruct() { if ( isset( $this->zip ) ) { try { $this->zip->close(); } catch ( \Throwable $error ) {} } }
	public static function safe_path( string $name ): bool {
		return strlen( $name ) <= 240 && 1 === preg_match( '~^(?:[a-z0-9][a-z0-9._-]*/)*[a-z0-9][a-z0-9._-]*$~iD', $name ) && ! array_intersect( explode( '/', $name ), [ '.','..' ] );
	}
	public function bytes( string $name, int $limit = 67108864 ): string {
		if ( ! isset( $this->index[$name] ) || $this->index[$name]['size'] > $limit ) throw new Compiler_Package_Error( 'A required compiler file is missing or too large.' );
		$stream = $this->zip->getStream( $name ); if ( ! is_resource( $stream ) ) throw new Compiler_Package_Error( 'A compiler file could not be read.' );
		try { $bytes = stream_get_contents( $stream, $limit + 1 ); } finally { fclose( $stream ); }
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $this->index[$name]['size'] || strlen( $bytes ) > $limit || strtolower( hash( 'crc32b', $bytes ) ) !== sprintf( '%08x', $this->index[$name]['crc'] ) ) throw new Compiler_Package_Error( 'A compiler file failed its size or CRC check.' );
		foreach ( $this->integrity['files'] ?? [] as $file ) if ( ( $file['path'] ?? '' ) === $name && ! hash_equals( $file['sha256'],'sha256:' . hash( 'sha256',$bytes ) ) ) throw new Compiler_Package_Error( 'A private compiler file changed after review.' );
		return $bytes;
	}
	public function json( string $name, int $limit = 67108864 ): array {
		try { $value = json_decode( $this->bytes( $name, $limit ), true, 64, JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $error ) { throw new Compiler_Package_Error( 'A compiler JSON file is invalid or nested too deeply.' ); }
		if ( ! is_array( $value ) || array_is_list( $value ) ) throw new Compiler_Package_Error( 'A compiler file must contain a JSON object.' );
		return $value;
	}
	public function inventory(): array { return $this->integrity; }
	private function verify_inventory( string $manifest,bool $full ): void {
		$i = $this->integrity;
		if ( ( $i['schema'] ?? '' ) !== 'seam.package-integrity.v1' || ( $i['algorithm'] ?? '' ) !== 'sha256' || ( $i['mutatesWordPress'] ?? null ) !== false || ! is_string( $i['profile'] ?? null ) || ! self::safe_path( $i['profile'] ) || ! is_array( $i['templates'] ?? null ) || ! array_is_list( $i['templates'] ) || count( $i['templates'] ) < 1 || count( $i['templates'] ) > 150 || ! is_array( $i['files'] ?? null ) || count( $i['files'] ) > 600 || ! is_array( $i['nativeMembers'] ?? null ) ) throw new Compiler_Package_Error( 'The compiler inventory format is unsupported.' );
		$files = [];
		foreach ( $i['files'] as $file ) {
			$name = $file['path'] ?? null;
			if ( ! is_string( $name ) || ! self::safe_path( $name ) || isset( $files[$name] ) || $name === $manifest || ! is_int( $file['bytes'] ?? null ) || ! is_string( $file['sha256'] ?? null ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $file['sha256'] ) ) throw new Compiler_Package_Error( 'The compiler file inventory is invalid.' );
			if ( ! isset( $this->index[$name] ) || $this->index[$name]['size'] !== $file['bytes'] ) throw new Compiler_Package_Error( 'A package file is missing or changed after review.' );
			if ( $full ) $this->bytes( $name );
			$files[$name] = $file;
		}
		// The portable manifest is display-only. It never selects files or grants authority.
		$extras = array_values( array_diff( array_keys( $this->index ), array_keys( $files ), [ $manifest ] ) );
		if ( count( $extras ) > 1 || ( $extras && ( ! preg_match( '~^manifest-[a-z0-9._-]+-seam-framework\.json$~iD', $extras[0] ) || ( $this->json( $extras[0],1048576 )['schema'] ?? '' ) !== 'seam.framework-package-manifest.v1' ) ) ) throw new Compiler_Package_Error( 'The ZIP contains files outside its dependency inventory.' );
		$native = []; $rows = []; $native_bytes = 0;
		foreach ( $i['nativeMembers'] as $file ) {
			$name = $file['path'] ?? null;
			if ( ! is_string( $name ) || isset( $native[$name] ) || ! isset( $files[$name] ) || $files[$name] !== $file ) throw new Compiler_Package_Error( 'The native compiler member inventory disagrees with the package.' );
			$native[$name] = true; $native_bytes += $file['bytes']; $rows[] = $name . "\0" . $file['sha256'] . "\n";
		}
		$expected = array_merge( [ $i['profile'] ], $i['templates'] );
		foreach ( $expected as $name ) if ( ! is_string( $name ) || ! self::safe_path( $name ) || ! str_ends_with( $name, '.json' ) ) throw new Compiler_Package_Error( 'A native compiler filename is invalid.' );
		if ( count( array_unique( $expected ) ) !== count( $expected ) || count( $expected ) !== count( $native ) || array_diff( $expected, array_keys( $native ) ) || $native_bytes > 67108864 ) throw new Compiler_Package_Error( 'The native compiler file selection is invalid.' );
		sort( $rows, SORT_STRING );
		if ( ! is_string( $i['sourceHash'] ?? null ) || ! hash_equals( $i['sourceHash'], 'sha256:' . hash( 'sha256', implode( '', $rows ) ) ) ) throw new Compiler_Package_Error( 'The compiler source inventory failed its independent hash check.' );
	}
	/** Fully recalculate media/reference claims. Hashes are never provenance or code approval. */
	public function inspect(): array {
		$i = $this->integrity; $prefix = $this->prefix;
		$manifest = $this->json( $prefix . 'assets/manifest.json', 4194304 );
		$plan = $this->json( $prefix . 'assets/import-plan.json', 4194304 );
		$styles = $this->json( $prefix . 'style-dependencies.json', 8388608 );
		foreach ( [ $manifest,$plan,$styles ] as $value ) if ( ( $value['sourceHash'] ?? '' ) !== $i['sourceHash'] ) throw new Compiler_Package_Error( 'Compiler dependency files refer to different source bytes.' );
		if ( ( $manifest['schema'] ?? '' ) !== 'seam.asset-manifest.v1' || ( $plan['schema'] ?? '' ) !== 'seam.asset-import-plan.v1' || ( $styles['schema'] ?? '' ) !== 'seam.style-dependencies.v1' || ( $plan['mutatesWordPress'] ?? null ) !== false || ( $plan['mode'] ?? '' ) !== 'dry-run' || ( $styles['mutatesWordPress'] ?? null ) !== false || ( $styles['nativeImportReady'] ?? null ) !== false || ! is_array( $manifest['assets'] ?? null ) || count( $manifest['assets'] ) > 200 || ! is_array( $styles['assetBindings'] ?? null ) || count( $styles['assetBindings'] ) > 20000 || ! is_array( $plan['operations'] ?? null ) ) throw new Compiler_Package_Error( 'The compiler dependency review format is unsupported.' );
		$documents = [ $i['profile'] => $this->json( $i['profile'] ) ];
		foreach ( $i['templates'] as $name ) $documents[$name] = $this->json( $name );
		$assets = []; $total = 0; $warnings = []; $blocks = [];
		foreach ( $manifest['assets'] as $asset ) {
			$id = $asset['id'] ?? null;
			if ( ! is_string( $id ) || isset( $assets[$id] ) || ! is_array( $asset['usedBy'] ?? null ) ) throw new Compiler_Package_Error( 'A compiler media identity is invalid or duplicated.' );
			$ops = array_values( array_filter( $plan['operations'], static fn( $op ) => is_array( $op ) && ( $op['assetId'] ?? '' ) === $id ) );
			if ( count( $ops ) !== 1 ) throw new Compiler_Package_Error( 'A media import decision is missing or duplicated.' );
			$op = $ops[0]; $asset['file'] = null;
			if ( ( $asset['policy'] ?? '' ) === 'external' ) {
				if ( ! is_string( $asset['source'] ?? null ) || ! preg_match( '~^(?:https?:)?//[^\s<>]+$~iD', $asset['source'] ) || ! array_key_exists( 'contentHash',$asset ) || null !== $asset['contentHash'] || ! array_key_exists( 'bytes',$asset ) || null !== $asset['bytes'] || ! array_key_exists( 'target',$op ) || null !== $op['target'] ) throw new Compiler_Package_Error( 'An external reference claims unverified local bytes.' );
				$warnings[] = 'External resource retained without downloading: ' . substr( $asset['source'], 0, 240 );
			} else {
				$file = $op['target'] ?? null;
				if ( ! is_string( $file ) || ! preg_match( '~^' . preg_quote( $prefix, '~' ) . 'assets/([a-f0-9]{64})\.(png|jpg|gif|webp|avif|mp4|webm|wav|mp3|svg|bin)$~D', $file, $match ) ) throw new Compiler_Package_Error( 'A packaged media path is invalid.' );
				$bytes = $this->bytes( $file, self::MAX_ASSET ); $total += strlen( $bytes );
				if ( $total > 67108864 || $asset['contentHash'] !== 'sha256:' . hash( 'sha256', $bytes ) || $asset['contentHash'] !== 'sha256:' . $match[1] || $asset['bytes'] !== strlen( $bytes ) ) throw new Compiler_Package_Error( 'Packaged media failed its independent byte/hash check.' );
				$asset['file'] = $file;
				if ( ( $asset['policy'] ?? '' ) !== 'import' ) $blocks[] = 'A supplied asset needs native review or replacement before import: ' . $file;
			}
			$assets[$id] = $asset;
		}
		if ( count( $plan['operations'] ) !== count( $assets ) ) throw new Compiler_Package_Error( 'The media operation list contains unknown assets.' );
		$consumers = []; $bound = [];
		foreach ( $styles['assetBindings'] as $binding ) {
			$file = $binding['artifact'] ?? ''; $pointer = $binding['pointer'] ?? ''; $asset = $assets[$binding['assetId'] ?? ''] ?? null;
			if ( ! is_string( $file ) || ! isset( $documents[$file] ) || ! is_string( $pointer ) || ! preg_match( '~^/(?:settings|content|header|footer|pageSettings|templateSettings)/~', $pointer ) || ! is_array( $asset ) || ! $asset['file'] ) throw new Compiler_Package_Error( 'A packaged media reference points outside native controls.' );
			$value = self::pointer( $documents[$file], $pointer );
			if ( ! is_string( $value ) ) throw new Compiler_Package_Error( 'A packaged media consumer is no longer a string.' );
			$refs = self::embedded_references( $value ); $matched = false;
			foreach ( $refs as $reference ) if ( ( $binding['referenceHash'] ?? '' ) === 'sha256:' . hash( 'sha256', $reference ) ) {
				$decoded = self::decode_reference( $reference );
				if ( $asset['contentHash'] !== 'sha256:' . hash( 'sha256', $decoded['bytes'] ) || $asset['mime'] !== $decoded['mime'] ) throw new Compiler_Package_Error( 'A media reference does not contain its declared asset bytes/type.' );
				$matched = true; $bound[$file . '#' . $pointer . '#' . $binding['referenceHash']] = true;
			}
			if ( ! $matched ) throw new Compiler_Package_Error( 'A media reference changed after compilation.' );
			$consumers[$asset['id']][] = $file . '#' . $pointer;
		}
		// Independently locate supplied data URIs; a forged sidecar cannot omit a consumer.
		$nodes = 0; $discovered = [];
		foreach ( $documents as $file => $document ) {
			$keys = $file === $i['profile'] ? [ 'settings' ] : [ 'content','header','footer','pageSettings','templateSettings' ];
			foreach ( $keys as $key ) if ( isset( $document[$key] ) ) self::walk( $document[$key], '/' . $key, static function( string $value, string $pointer ) use ( $file,$bound,&$discovered ): void {
				foreach ( self::embedded_references( $value ) as $reference ) if ( ! isset( $bound[$file . '#' . $pointer . '#sha256:' . hash( 'sha256', $reference )] ) ) throw new Compiler_Package_Error( 'An embedded resource is missing from the compiler dependency map.' );
				$references = [];
				// Native image.filename is a picker label; only its URL owns the resource.
				if ( ! preg_match( '~/filename$~iD',$pointer ) && preg_match( '~(?:image|background|video|audio|font|src|poster)~i',$pointer ) && preg_match( '~^(?:\.?\.?/)?[^\s{}:]+\.(?:png|jpe?g|gif|webp|avif|svg|mp4|webm|mp3|wav|woff2?)(?:[?#][^\s]*)?$~iD',$value ) ) $references[] = $value;
				preg_match_all( '~(?:url\(\s*[\x22\x27]?|(?:src|poster)=[\x22\x27])([^\s\x22\x27<>)]*)~i',$value,$matches ); $references = array_merge( $references,$matches[1] );
				foreach ( $references as $reference ) if ( $reference && ! preg_match( '~^(?:data:|https?://|//|/|#|\{)~i',$reference ) ) $discovered[] = [ 'code'=>'unresolved-native-resource','source'=>$file . '#' . $pointer,'message'=>'A relative media/font resource has no supplied target file: ' . substr( $reference,0,180 ) ];
			}, $nodes );
		}
		foreach ( $assets as $asset ) if ( $asset['file'] && empty( $consumers[$asset['id']] ) ) throw new Compiler_Package_Error( 'Packaged media has no verified native consumer.' );
		$issues = array_merge( $styles['issues'] ?? [],$discovered );
		foreach ( $issues as $issue ) $blocks[] = is_array( $issue ) ? substr( (string) ( $issue['message'] ?? 'An unresolved dependency needs review.' ), 0, 300 ) : 'An unresolved dependency needs review.';
		return [ 'sourceHash'=>$i['sourceHash'], 'profileFile'=>$i['profile'], 'templateFiles'=>$i['templates'], 'documents'=>$documents, 'assets'=>$assets, 'bindings'=>$styles['assetBindings'], 'issues'=>$issues, 'warnings'=>array_values( array_unique( $warnings ) ), 'blockers'=>array_values( array_unique( $blocks ) ), 'nativeImportReady'=>false ];
	}
	public static function embedded_references( string $value ): array {
		$refs = []; if ( preg_match( '/^data:[^,]+,/i', $value ) ) $refs[] = $value;
		preg_match_all( '~data:[a-z0-9.+-]+/[a-z0-9.+-]+(?:;charset=[a-z0-9-]+)?;base64,[a-z0-9+/]*={0,2}~i', $value, $matches ); $refs = array_merge( $refs,$matches[0] );
		preg_match_all( '~url\(\s*([\x22\x27])(data:[\s\S]*?)\1\s*\)~i', $value,$matches ); return array_values( array_unique( array_merge( $refs,$matches[2] ) ) );
	}
	public static function decode_reference( string $value ): array {
		if ( strlen( $value ) > self::MAX_ASSET * 1.5 || ! preg_match( '~^data:([^;,]*)(;[^,]*)?,([\s\S]*)$~iD', $value,$m ) ) throw new Compiler_Package_Error( 'An embedded resource is invalid or too large.' );
		$bytes = stripos( $m[2], ';base64' ) !== false ? base64_decode( $m[3],true ) : rawurldecode( $m[3] );
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_ASSET ) throw new Compiler_Package_Error( 'An embedded resource could not be decoded.' );
		return [ 'mime'=>strtolower( $m[1] ), 'bytes'=>$bytes ];
	}
	public static function pointer( array $document, string $pointer ) {
		$value = $document;
		foreach ( explode( '/', substr( $pointer,1 ) ) as $part ) { $part = str_replace( [ '~1','~0' ], [ '/','~' ],$part ); if ( ! is_array( $value ) || ! array_key_exists( $part,$value ) ) throw new Compiler_Package_Error( 'A native dependency pointer is invalid.' ); $value = $value[$part]; }
		return $value;
	}
	public static function walk( $value, string $pointer, callable $visit, int &$nodes, int $depth = 0 ): void {
		if ( ++$nodes > 500000 || $depth > 40 ) throw new Compiler_Package_Error( 'The compiler native dependency map is too large or deeply nested.' );
		if ( is_string( $value ) ) $visit( $value,$pointer );
		elseif ( is_array( $value ) ) foreach ( $value as $key=>$item ) self::walk( $item,$pointer . '/' . str_replace( [ '~','/' ],[ '~0','~1' ],(string) $key ),$visit,$nodes,$depth + 1 );
	}
}
