<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Team_Workspace_Service;
use DSA\Access\User_Group_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\User_Profile_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Existing editable people's profile content, through the Users workspace owner. */
final class Account_Profile_Snapshot_Service {

	public const NAME = 'kiwe.accounts.profiles';
	public const GROUP = 'Account profiles';
	public const SCHEMA = 'kiwe.account-profiles.v1';
	public const MAX_RECORDS = 1000;
	private const NAME_FIELDS = [ 'display_name','first_name','last_name','nickname','description','user_url' ];
	private const TEAM_FIELDS = [ 'team_member','team_title','team_order' ];

	public static function handles( string $name ): bool { return self::NAME === $name; }
	public static function allowed(): bool { return Backup_Workspace_Service::allowed() && User_Group_Service::allowed(); }
	public static function groups(): array { return self::allowed() ? [ self::GROUP=>[ self::NAME ] ] : []; }
	public static function descriptions(): array { return [ self::GROUP=>'Names, biographies, public links and public-team details of existing editable people. Login identity, roles, verification, consent and credentials stay unchanged.' ]; }
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function access(): void { if ( ! self::allowed() ) self::fail( 'Your account cannot back up or restore people profiles.' ); }
	private static function keys( $value,array $keys ): bool { return is_array( $value ) && count( $value ) === count( $keys ) && ! array_diff( array_keys( $value ),$keys ); }
	private static function native( $result ): array {
		if ( is_wp_error( $result ) || ! is_array( $result ) ) self::fail( 'A referenced profile is missing, protected or no longer editable. Review its native account first.' );
		return $result;
	}
	private static function identity( int $id ): string {
		$user = get_userdata( $id );
		self::native( ( new Team_Workspace_Service() )->account_section( $id,'name' ) );
		if ( ! $user ) self::fail( 'A referenced account is no longer available.' );
		$roles = array_values( $user->roles ); sort( $roles,SORT_STRING );
		return hash( 'sha256',(string) wp_json_encode( [ $id,(string) $user->user_login,(string) $user->user_registered,(string) $user->user_email,$roles ] ) );
	}
	private static function public_fields(): array { return [ Design_Context_Profile_Service::USER_META_FOUNDER_TITLE,Design_Context_Profile_Service::USER_META_LINKEDIN,'facebook' ]; }
	private static function record( int $id,array $name ): array {
		$native = new Team_Workspace_Service(); $staff = User_Profile_Service::eligible( $id );
		return [
			'id'=>$id,'identity'=>self::identity( $id ),'name'=>$name['values'],
			'public'=>$staff ? self::native( $native->account_section( $id,'public' ) )['values'] : null,
			'team'=>$staff ? [
				'team_member'=>'1' === (string) get_user_meta( $id,Design_Context_Profile_Service::USER_META_TEAM_MEMBER,true ) ? '1' : '0',
				'team_title'=>(string) get_user_meta( $id,Design_Context_Profile_Service::USER_META_TEAM_TITLE,true ),
				'team_order'=>(string) (int) get_user_meta( $id,Design_Context_Profile_Service::USER_META_TEAM_ORDER,true ),
			] : null,
		];
	}
	/** No WP_User, email address, role definition or arbitrary metadata enters the file. */
	public static function read(): array {
		self::access();
		$ids = get_users( [ 'blog_id'=>get_current_blog_id(),'fields'=>'ID','number'=>self::MAX_RECORDS+1,'orderby'=>'ID','order'=>'ASC','count_total'=>false ] );
		if ( count( $ids ) > self::MAX_RECORDS ) self::fail( 'Account profiles exceed the 1,000-person recovery limit.' );
		if ( function_exists( 'cache_users' ) ) cache_users( $ids );
		if ( function_exists( 'update_meta_cache' ) ) update_meta_cache( 'user',$ids );
		$records = []; $native = new Team_Workspace_Service();
		foreach ( $ids as $id ) {
			$name = $native->account_section( (int) $id,'name' );
			// Protected technical-owner/network accounts stay outside this user's task.
			if ( is_wp_error( $name ) ) continue;
			$records[] = self::record( (int) $id,self::native( $name ) );
		}
		$value = [ 'schema'=>self::SCHEMA,'records'=>$records ]; self::validate( $value ); return $value;
	}
	private static function fields( $values,array $keys ): void {
		if ( ! self::keys( $values,$keys ) ) self::fail( 'The snapshot contains unsupported profile fields.' );
		foreach ( $values as $key=>$value ) {
			if ( ! is_string( $value ) || strlen( $value ) > ( 'description' === $key ? 12000 : 1000 ) ) self::fail( 'A profile value is invalid or exceeds its native field limit.' );
			$clean = 'description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( in_array( $key,[ 'user_url','facebook',Design_Context_Profile_Service::USER_META_LINKEDIN ],true ) && '' !== $value ) {
				$clean = esc_url_raw( $clean,[ 'http','https' ] );
				if ( ! $clean || ! preg_match( '~^https?://~i',$clean ) ) self::fail( 'A public profile link requires a complete HTTP or HTTPS URL.' );
			}
			if ( $clean !== $value ) self::fail( 'A profile field needs review in Users before it can be recovered without changing its native format.' );
		}
	}
	public static function validate( array $value ): void {
		self::access();
		if ( ! self::keys( $value,[ 'schema','records' ] ) || self::SCHEMA !== $value['schema'] || ! is_array( $value['records'] ) || ! array_is_list( $value['records'] ) || count( $value['records'] ) > self::MAX_RECORDS ) self::fail( 'The account profile snapshot is unsupported or too large.' );
		$previous = 0;
		foreach ( $value['records'] as $row ) {
			if ( ! self::keys( $row,[ 'id','identity','name','public','team' ] ) || ! is_int( $row['id'] ) || $row['id'] <= $previous || ! is_string( $row['identity'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$row['identity'] ) || ! hash_equals( self::identity( $row['id'] ),$row['identity'] ) ) self::fail( 'A saved account identity or role changed. Profiles never recreate accounts or grant access.' );
			$previous = $row['id']; self::fields( $row['name'],self::NAME_FIELDS );
			if ( '' === $row['name']['display_name'] || '' === $row['name']['nickname'] ) self::fail( 'The native account needs a display name and nickname before profile recovery.' );
			$staff = User_Profile_Service::eligible( $row['id'] );
			if ( ! $staff ) {
				if ( null !== $row['public'] || null !== $row['team'] ) self::fail( 'This account cannot be made a public team member by profile recovery.' );
				continue;
			}
			self::native( ( new Team_Workspace_Service() )->account_section( $row['id'],'public' ) );
			foreach ( self::public_fields() as $key ) if ( ! current_user_can( 'edit_user_meta',$row['id'],$key ) ) self::fail( 'A public profile field is no longer editable.' );
			self::fields( $row['public'],self::public_fields() );
			if ( ! self::keys( $row['team'],self::TEAM_FIELDS ) || ! in_array( $row['team']['team_member'],[ '0','1' ],true ) || ! is_string( $row['team']['team_title'] ) || sanitize_text_field( $row['team']['team_title'] ) !== $row['team']['team_title'] || false === ( $length = preg_match_all( '/./us',$row['team']['team_title'] ) ) || $length > 200 || ! is_string( $row['team']['team_order'] ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,3})$/D',$row['team']['team_order'] ) ) self::fail( 'A saved public-team choice exceeds its native field limits.' );
		}
	}
	public static function validate_options( array $options ): void {
		if ( ! isset( $options[self::NAME] ) ) return;
		$setting = $options[self::NAME];
		if ( ! self::keys( $setting,[ 'exists','value' ] ) || true !== $setting['exists'] || ! is_array( $setting['value'] ) ) self::fail( 'The complete typed profile projection is required.' );
		self::validate( $setting['value'] );
	}
	/** Restore saved people only; people added after capture and hidden owners survive. */
	public static function merge_options( array $options ): array {
		if ( ! isset( $options[self::NAME] ) ) return $options;
		self::validate_options( $options );
		$current = self::read(); $records = array_column( $current['records'],null,'id' );
		foreach ( $options[self::NAME]['value']['records'] as $row ) $records[$row['id']] = $row;
		ksort( $records,SORT_NUMERIC );
		$options[self::NAME]['value']['records'] = array_values( $records );
		self::validate_options( $options ); return $options;
	}
	public static function review( array $before,array $next ): array {
		if ( ! isset( $next[self::NAME] ) ) return [];
		$old = array_column( $before[self::NAME]['value']['records'],null,'id' ); $changes = [];
		$native = new Team_Workspace_Service();
		foreach ( $next[self::NAME]['value']['records'] as $row ) {
			$person = (string) get_userdata( $row['id'] )->display_name;
			foreach ( [ 'name','public','team' ] as $section ) {
				if ( null === $row[$section] ) continue;
				$labels = 'team' === $section ? [ 'team_member'=>'Shown on public team','team_title'=>'Team title','team_order'=>'Team order' ] : self::native( $native->account_section( $row['id'],$section ) )['fields'];
				foreach ( $row[$section] as $key=>$value ) {
					$prior = $old[$row['id']][$section][$key] ?? '';
					if ( $prior === $value ) continue;
					$describe = static fn( string $v ): string=>'team_member' === $key ? ( '1' === $v ? 'Yes' : 'No' ) : ( '' === $v ? 'Empty' : $v );
					$changes[] = [ 'label'=>$person.' · '.$labels[$key],'before'=>$describe( $prior ),'after'=>$describe( $value ) ];
				}
			}
		}
		return [ [ 'label'=>self::GROUP,'changes'=>$changes ] ];
	}
	/** Existing native writers recheck permission, stale fields, formats and readback. */
	public static function write( array $setting ): void {
		self::validate_options( [ self::NAME=>$setting ] ); $native = new Team_Workspace_Service();
		foreach ( $setting['value']['records'] as $row ) {
			foreach ( [ 'name','public' ] as $section ) {
				if ( null === $row[$section] ) continue;
				$current = self::native( $native->account_section( $row['id'],$section ) );
				if ( wp_json_encode( $current['values'] ) === wp_json_encode( $row[$section] ) ) continue;
				self::native( $native->save_account_section( $row['id'],[ 'section'=>$section,'expected'=>$current['expected'],'values'=>$row[$section] ] ) );
			}
			if ( null !== $row['team'] ) {
				$current = self::record( $row['id'],self::native( $native->account_section( $row['id'],'name' ) ) );
				if ( wp_json_encode( $current['team'] ) !== wp_json_encode( $row['team'] ) ) {
					$result = $native->save_member( $row['id'],[ ...$row['team'],'expected'=>Team_Workspace_Service::fingerprint( $row['id'] ) ] );
					if ( true !== $result ) self::fail( 'The native public-team writer could not confirm the recovery.' );
				}
			}
		}
	}
}
