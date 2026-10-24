<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Parses a small native SHOW CREATE grammar. Archived SQL is never submitted to a server. */
final class Portable_Table_Definition {
	private array $tokens = [];
	private int $at = 0;
	private static function fail(): never { throw new Archive_Error( 'This table definition is outside the supported recovery grammar.' ); }
	public static function identifier( string $name ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_]{1,64}$/D',$name ) ) self::fail();
		return '`'.$name.'`';
	}
	private function __construct( string $sql ) {
		if ( strlen( $sql ) > 65536 || ! preg_match( '//u',$sql ) ) self::fail();
		$at = 0; $length = strlen( $sql );
		while ( $at < $length ) {
			$c = $sql[$at];
			if ( str_contains( " \r\n\t",$c ) ) { ++$at; continue; }
			if ( '`' === $c ) {
				$end = strpos( $sql,'`',$at+1 ); if ( false === $end ) self::fail();
				$v = substr( $sql,$at+1,$end-$at-1 ); self::identifier( $v ); $this->tokens[] = [ 'id',$v ]; $at = $end+1;
			} elseif ( "'" === $c ) {
				++$at; $v = ''; $closed = false;
				while ( $at < $length ) {
					$c = $sql[$at++];
					if ( "'" === $c ) { if ( $at < $length && "'" === $sql[$at] ) { $v .= "'"; ++$at; } else { $closed = true; break; } }
					elseif ( '\\' === $c ) {
						if ( $at >= $length ) self::fail(); $e = $sql[$at++];
						// LIKE escapes retain the slash; the remaining native SHOW CREATE escapes decode once.
						$v .= match ( $e ) { '0'=>"\0",'b'=>"\x08",'n'=>"\n",'r'=>"\r",'t'=>"\t",'Z'=>"\x1a",'%','_'=>'\\'.$e,"'",'"','\\'=>$e,default=>self::fail() };
					} else $v .= $c;
				}
				if ( ! $closed ) self::fail(); $this->tokens[] = [ 'str',$v ];
			} elseif ( preg_match( '/\G-?[0-9]+(?:\.[0-9]+)?/A', $sql,$m,0,$at ) ) { $this->tokens[] = [ 'num',$m[0] ]; $at += strlen( $m[0] ); }
			elseif ( preg_match( '/\G[a-zA-Z_][a-zA-Z0-9_]*/A',$sql,$m,0,$at ) ) { $this->tokens[] = [ 'word',strtoupper( $m[0] ) ]; $at += strlen( $m[0] ); }
			elseif ( str_contains( '(),=',$c ) ) { $this->tokens[] = [ $c,$c ]; ++$at; }
			else self::fail(); // Comments, statements, expressions, executable modifiers and qualified names refuse.
			if ( count( $this->tokens ) > 12000 ) self::fail();
		}
	}
	private function peek( string $value ): bool { return ( $this->tokens[$this->at][1] ?? null ) === $value; }
	private function take( string $value ): bool { if ( ! $this->peek( $value ) ) return false; ++$this->at; return true; }
	private function expect( string $value ): void { if ( ! $this->take( $value ) ) self::fail(); }
	private function value( string $kind ): string { $t = $this->tokens[$this->at++] ?? null; if ( ! $t || $t[0] !== $kind ) self::fail(); return $t[1]; }
	private function number( int $minimum, int $maximum ): int {
		$v = $this->value( 'num' ); if ( ! preg_match( '/^[0-9]{1,6}$/D',$v ) || (int)$v < $minimum || (int)$v > $maximum ) self::fail(); return (int)$v;
	}
	private function word_name(): string { $v = strtolower( $this->value( 'word' ) ); self::identifier( $v ); return $v; }
	private function type(): array {
		$name = strtolower( $this->value( 'word' ) ); $args = [];
		$integers = [ 'tinyint','smallint','mediumint','int','integer','bigint' ];
		if ( in_array( $name,$integers,true ) ) { if ( $this->take( '(' ) ) { $args[] = $this->number( 1,255 ); $this->expect( ')' ); } }
		elseif ( in_array( $name,[ 'char','varchar','binary','varbinary','bit' ],true ) ) { $this->expect( '(' ); $args[] = $this->number( 1,'bit' === $name ? 64 : 65535 ); $this->expect( ')' ); }
		elseif ( in_array( $name,[ 'decimal','numeric','float','double' ],true ) ) {
			if ( $this->take( '(' ) ) { $args[] = $this->number( 1,65 ); if ( $this->take( ',' ) ) $args[] = $this->number( 0,30 ); $this->expect( ')' ); if ( count( $args ) === 2 && $args[1] > $args[0] ) self::fail(); }
		} elseif ( in_array( $name,[ 'datetime','timestamp','time' ],true ) ) { if ( $this->take( '(' ) ) { $args[] = $this->number( 0,6 ); $this->expect( ')' ); } }
		elseif ( 'year' === $name ) { if ( $this->take( '(' ) ) { $args[] = $this->number( 4,4 ); $this->expect( ')' ); } }
		elseif ( in_array( $name,[ 'enum','set' ],true ) ) { $this->expect( '(' ); do { $args[] = $this->value( 'str' ); if ( count( $args ) > 256 ) self::fail(); } while ( $this->take( ',' ) ); $this->expect( ')' ); }
		elseif ( ! in_array( $name,[ 'tinytext','text','mediumtext','longtext','tinyblob','blob','mediumblob','longblob','date' ],true ) ) self::fail();
		$unsigned = $this->take( 'UNSIGNED' ); $zerofill = $this->take( 'ZEROFILL' );
		if ( ( $unsigned || $zerofill ) && ! in_array( $name,array_merge( $integers,[ 'decimal','numeric','float','double' ] ),true ) ) self::fail();
		return [ 'name'=>$name,'args'=>$args,'unsigned'=>$unsigned,'zerofill'=>$zerofill ];
	}
	public static function column_type( string $type ): array {
		$p = new self( $type ); $value = $p->type(); if ( $p->at !== count( $p->tokens ) ) self::fail(); return $value;
	}
	private function current_time(): array {
		$this->expect( 'CURRENT_TIMESTAMP' ); $precision = 0;
		if ( $this->take( '(' ) ) { if ( ! $this->peek( ')' ) ) $precision = $this->number( 0,6 ); $this->expect( ')' ); }
		return [ 'current_timestamp',$precision ];
	}
	private function column(): array {
		$col = [ 'name'=>$this->value( 'id' ),'type'=>$this->type(),'nullable'=>true,'default'=>null,'charset'=>null,'collation'=>null,'autoIncrement'=>false,'onUpdate'=>null,'comment'=>null ]; $seen = [];
		while ( ! $this->peek( ',' ) && ! $this->peek( ')' ) ) {
			$key = null;
			if ( $this->take( 'NOT' ) ) { $this->expect( 'NULL' ); $key = 'nullable'; $col[$key] = false; }
			elseif ( $this->take( 'NULL' ) ) { $key = 'nullable'; }
			elseif ( $this->take( 'DEFAULT' ) ) {
				$key = 'default';
				if ( $this->take( 'NULL' ) ) $col[$key] = [ 'null' ];
				elseif ( $this->peek( 'CURRENT_TIMESTAMP' ) ) $col[$key] = $this->current_time();
				else { $t = $this->tokens[$this->at] ?? null; if ( ! $t || ! in_array( $t[0],[ 'str','num' ],true ) ) self::fail(); $col[$key] = [ $t[0],$this->value( $t[0] ) ]; }
			} elseif ( $this->take( 'CHARACTER' ) ) { $this->expect( 'SET' ); $key = 'charset'; $col[$key] = $this->word_name(); }
			elseif ( $this->take( 'COLLATE' ) ) { $key = 'collation'; $col[$key] = $this->word_name(); }
			elseif ( $this->take( 'AUTO_INCREMENT' ) ) { $key = 'autoIncrement'; $col[$key] = true; }
			elseif ( $this->take( 'ON' ) ) { $this->expect( 'UPDATE' ); $key = 'onUpdate'; $col[$key] = $this->current_time(); }
			elseif ( $this->take( 'COMMENT' ) ) { $key = 'comment'; $col[$key] = $this->value( 'str' ); }
			else self::fail();
			if ( isset( $seen[$key] ) ) self::fail(); $seen[$key] = true;
		}
		if ( ( $col['onUpdate'] || ( $col['default'][0] ?? '' ) === 'current_timestamp' ) && ! in_array( $col['type']['name'],[ 'timestamp','datetime' ],true ) ) self::fail();
		return $col;
	}
	private function index(): array {
		$kind = 'key'; $name = null;
		if ( $this->take( 'PRIMARY' ) ) { $kind = 'primary'; $this->expect( 'KEY' ); }
		else { if ( $this->take( 'UNIQUE' ) ) $kind = 'unique'; elseif ( $this->take( 'FULLTEXT' ) ) $kind = 'fulltext'; $this->expect( 'KEY' ); $name = $this->value( 'id' ); }
		$this->expect( '(' ); $columns = [];
		do { $column = [ 'name'=>$this->value( 'id' ),'length'=>null ]; if ( $this->take( '(' ) ) { $column['length'] = $this->number( 1,65535 ); $this->expect( ')' ); } $columns[] = $column; if ( count( $columns ) > 64 ) self::fail(); } while ( $this->take( ',' ) );
		$this->expect( ')' ); $using = null;
		if ( $this->take( 'USING' ) ) { $this->expect( 'BTREE' ); $using = 'btree'; }
		return [ 'kind'=>$kind,'name'=>$name,'columns'=>$columns,'using'=>$using ];
	}
	public static function parse( string $sql, string $expected_name ): array {
		$p = new self( $sql ); $p->expect( 'CREATE' ); $p->expect( 'TABLE' ); if ( $p->value( 'id' ) !== $expected_name ) self::fail(); $p->expect( '(' );
		$columns = []; $indexes = []; $names = []; $index_names = [];
		do {
			if ( ( $p->tokens[$p->at][0] ?? '' ) === 'id' ) { $col = $p->column(); if ( isset( $names[$col['name']] ) || count( $columns ) >= 64 || $indexes ) self::fail(); $names[$col['name']] = true; $columns[] = $col; }
			else { $index = $p->index(); $key = $index['name'] ?? 'PRIMARY'; if ( isset( $index_names[$key] ) || count( $indexes ) >= 64 ) self::fail(); $index_names[$key] = true; $indexes[] = $index; }
		} while ( $p->take( ',' ) ); $p->expect( ')' );
		$ast = [ 'columns'=>$columns,'indexes'=>$indexes,'engine'=>null,'charset'=>null,'collation'=>null,'autoIncrement'=>null,'rowFormat'=>null,'keyBlockSize'=>null,'comment'=>null ]; $seen = [];
		while ( $p->at < count( $p->tokens ) ) {
			$key = null;
			if ( $p->take( 'ENGINE' ) ) { $key = 'engine'; $p->expect( '=' ); $p->expect( 'INNODB' ); $ast[$key] = 'InnoDB'; }
			elseif ( $p->take( 'DEFAULT' ) ) { $key = 'charset'; $p->expect( 'CHARSET' ); $p->expect( '=' ); $ast[$key] = $p->word_name(); }
			elseif ( $p->take( 'COLLATE' ) ) { $key = 'collation'; $p->expect( '=' ); $ast[$key] = $p->word_name(); }
			elseif ( $p->take( 'AUTO_INCREMENT' ) ) { $key = 'autoIncrement'; $p->expect( '=' ); $v = $p->value( 'num' ); if ( ! preg_match( '/^[0-9]{1,20}$/D',$v ) ) self::fail(); $ast[$key] = $v; }
			elseif ( $p->take( 'ROW_FORMAT' ) ) { $key = 'rowFormat'; $p->expect( '=' ); $v = $p->value( 'word' ); if ( ! in_array( $v,[ 'DYNAMIC','COMPACT','COMPRESSED','REDUNDANT' ],true ) ) self::fail(); $ast[$key] = $v; }
			elseif ( $p->take( 'KEY_BLOCK_SIZE' ) ) { $key = 'keyBlockSize'; $p->expect( '=' ); $ast[$key] = $p->number( 1,16 ); }
			elseif ( $p->take( 'COMMENT' ) ) { $key = 'comment'; $p->expect( '=' ); $ast[$key] = $p->value( 'str' ); }
			else self::fail();
			if ( isset( $seen[$key] ) ) self::fail(); $seen[$key] = true;
		}
		if ( ! $columns || 'InnoDB' !== $ast['engine'] || ! $ast['charset'] || ! isset( $index_names['PRIMARY'] ) ) self::fail();
		foreach ( $indexes as $index ) foreach ( $index['columns'] as $c ) if ( ! isset( $names[$c['name']] ) || ( 'primary' === $index['kind'] && null !== $c['length'] ) ) self::fail();
		return $ast;
	}
	private static function quote( string $value ): string {
		return "'".strtr( $value,[ '\\'=>'\\\\',"'"=>"\\'","\0"=>'\\0',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t',"\x08"=>'\\b',"\x1a"=>'\\Z' ] )."'";
	}
	private static function type_sql( array $type ): string {
		$args = array_map( static fn( $v )=>is_int( $v ) ? (string)$v : self::quote( $v ),$type['args'] );
		return $type['name'].( $args ? '('.implode( ',',$args ).')' : '' ).( $type['unsigned'] ? ' unsigned' : '' ).( $type['zerofill'] ? ' zerofill' : '' );
	}
	/** Only parser-produced values enter this renderer. */
	public static function compile( string $source_sql, string $source_name, string $destination_name ): string {
		$ast = self::parse( $source_sql,$source_name ); $parts = [];
		foreach ( $ast['columns'] as $c ) {
			$sql = self::identifier( $c['name'] ).' '.self::type_sql( $c['type'] );
			if ( $c['charset'] ) $sql .= ' CHARACTER SET '.$c['charset']; if ( $c['collation'] ) $sql .= ' COLLATE '.$c['collation'];
			$sql .= $c['nullable'] ? ' NULL' : ' NOT NULL';
			if ( null !== $c['default'] ) { $v = $c['default']; $sql .= ' DEFAULT '.match ( $v[0] ) { 'null'=>'NULL','str'=>self::quote( $v[1] ),'num'=>$v[1],'current_timestamp'=>'CURRENT_TIMESTAMP('.$v[1].')',default=>self::fail() }; }
			if ( $c['autoIncrement'] ) $sql .= ' AUTO_INCREMENT'; if ( $c['onUpdate'] ) $sql .= ' ON UPDATE CURRENT_TIMESTAMP('.$c['onUpdate'][1].')'; if ( null !== $c['comment'] ) $sql .= ' COMMENT '.self::quote( $c['comment']); $parts[] = $sql;
		}
		foreach ( $ast['indexes'] as $index ) {
			$sql = match ( $index['kind'] ) { 'primary'=>'PRIMARY KEY','unique'=>'UNIQUE KEY '.self::identifier( $index['name'] ),'fulltext'=>'FULLTEXT KEY '.self::identifier( $index['name'] ),'key'=>'KEY '.self::identifier( $index['name'] ),default=>self::fail() };
			$sql .= ' ('.implode( ',',array_map( static fn( $c )=>self::identifier( $c['name'] ).( null !== $c['length'] ? '('.$c['length'].')' : '' ),$index['columns'] ) ).')'; if ( $index['using'] ) $sql .= ' USING BTREE'; $parts[] = $sql;
		}
		$sql = 'CREATE TABLE '.self::identifier( $destination_name ).' ('.implode( ',',$parts ).') ENGINE=InnoDB DEFAULT CHARSET='.$ast['charset'];
		foreach ( [ 'collation'=>'COLLATE','autoIncrement'=>'AUTO_INCREMENT','rowFormat'=>'ROW_FORMAT','keyBlockSize'=>'KEY_BLOCK_SIZE' ] as $key=>$token ) if ( null !== $ast[$key] ) $sql .= ' '.$token.'='.$ast[$key];
		if ( null !== $ast['comment'] ) $sql .= ' COMMENT='.self::quote( $ast['comment'] ); return $sql;
	}
	/** Semantic schema comparison omits server-generated spelling and the advancing sequence. */
	public static function fingerprint( string $sql, string $name ): string {
		$ast = self::parse( $sql,$name ); unset( $ast['autoIncrement'] );
		$charset = static fn( $v )=>'utf8' === $v ? 'utf8mb3' : $v;
		$collation = static fn( $v )=>is_string( $v ) && str_starts_with( $v,'utf8_' ) ? 'utf8mb3_'.substr( $v,5 ) : $v;
		$ast['charset'] = $charset( $ast['charset'] ); $ast['collation'] = $collation( $ast['collation'] );
		foreach ( $ast['columns'] as &$col ) {
			$t = &$col['type']; if ( 'integer' === $t['name'] ) $t['name'] = 'int'; if ( 'numeric' === $t['name'] ) $t['name'] = 'decimal';
			if ( in_array( $t['name'],[ 'tinyint','smallint','mediumint','int','bigint','year' ],true ) ) $t['args'] = [];
			if ( in_array( $t['name'],[ 'timestamp','datetime','time' ],true ) && [ 0 ] === $t['args'] ) $t['args'] = [];
			$col['charset'] = $charset( $col['charset'] ?? $ast['charset'] ); $col['collation'] = $collation( $col['collation'] ?? $ast['collation'] );
			if ( $col['nullable'] && null === $col['default'] ) $col['default'] = [ 'null' ];
			if ( ( $col['default'][0] ?? '' ) === 'num' ) $col['default'][0] = 'str';
			unset( $t );
		} unset( $col );
		foreach ( $ast['indexes'] as &$index ) if ( 'btree' === $index['using'] ) $index['using'] = null; unset( $index );
		return hash( 'sha256',json_encode( $ast,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	}
}
