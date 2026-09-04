<?php

namespace DSA\Onboarding;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Onboarding_Service {
	private const INVITATIONS_OPTION = 'kiwe_onboarding_invitations_v1';
	private const PROMPTED_META = '_kiwe_onboarding_prompted_v1';

	public function __construct( private Design_Context_Profile_Service $profiles, private Channel_Service $channels, private Design_Context_Refinement_Service $refinements ) {}

	public function register(): void {
		( new User_Profile_Service() )->register();
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_init', [ $this, 'maybe_open_fresh_install' ], 30 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_kiwe_save_onboarding', [ $this, 'handle_save' ] );
		add_action( 'admin_post_kiwe_create_onboarding_invite', [ $this, 'handle_invite' ] );
		$this->refinements->register();
	}

	/** Stable step IDs for validation links, contiguous visible numbering. */
	private function steps(): array {
		$steps = [ 0=>'Identity', 1=>'Story', 2=>'Contact', 3=>'Brand', 4=>'Website plan', 6=>'Services' ];
		if ( $this->profiles->commerce_available() ) $steps[5] = 'Store';
		return $steps;
	}

	public static function section_slugs(): array {
		$slugs = [ 'kiwe-identity', 'kiwe-story', 'kiwe-contact', 'kiwe-brand', 'kiwe-website-plan', 'kiwe-services' ];
		if ( ( new Design_Context_Profile_Service() )->commerce_available() ) $slugs[] = 'kiwe-store';
		return $slugs;
	}

	private function section(): string {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( in_array( $page, self::section_slugs(), true ) ) return substr( $page, 5 );
		$legacy = [ 0=>'identity', 1=>'story', 2=>'contact', 3=>'brand', 4=>'website-plan', 6=>'services', 5=>'store' ];
		$section = $legacy[ absint( $_GET['step'] ?? 0 ) ] ?? 'identity';
		return in_array( 'kiwe-' . $section, self::section_slugs(), true ) ? $section : 'identity';
	}

	public function menu(): void {
		$icons = [ 'dashicons-id', 'dashicons-format-aside', 'dashicons-location', 'dashicons-art', 'dashicons-layout', 'dashicons-portfolio', 'dashicons-store' ];
		foreach ( array_values( $this->steps() ) as $i=>$label ) {
			add_menu_page( $label, $label, 'kiwe_manage_context', self::section_slugs()[ $i ], [ $this, 'render' ], $icons[ $i ], 26 + $i );
		}
		// Keep account-bound old handoff links valid without a duplicate menu.
		add_submenu_page( null, 'Website details', 'Website details', 'kiwe_manage_context', 'kiwe-onboarding', [ $this, 'render' ] );
	}

	public function assets( string $hook ): void {
		if ( ! in_array( sanitize_key( $_GET['page'] ?? '' ), array_merge( self::section_slugs(), [ 'kiwe-onboarding' ] ), true ) || ! current_user_can( 'kiwe_manage_context' ) ) return;
		wp_enqueue_media();
		wp_enqueue_style( 'kiwe-onboarding', DSA_URL . 'assets/css/onboarding.css', [], DSA_VERSION );
		wp_enqueue_script( 'kiwe-onboarding', DSA_URL . 'assets/js/onboarding.js', [], DSA_VERSION, true );
		$saved = ! empty( $_GET['saved'] );
		$step = absint( $_GET['step'] ?? 0 );
		wp_localize_script( 'kiwe-onboarding', 'KIWE_ONBOARDING', [
			'chooseImage' => __( 'Choose image', 'dsa' ),
			'useImage'    => __( 'Use this image', 'dsa' ),
			'saving'      => __( 'Saving owner context…', 'dsa' ),
			'saved'       => $saved,
			'startStep'   => $step,
			'singleSection' => true,
			'industrySector' => $this->profiles->current()['identity']['industrySector'] ?? '',
		] );
	}

	public function maybe_open_fresh_install(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || $this->profiles->is_complete() || wp_doing_ajax() || wp_doing_cron() ) return;
		if ( 'sitegraph_only_v1' !== get_option( 'dsa_install_profile', '' ) || get_user_meta( get_current_user_id(), self::PROMPTED_META, true ) ) return;
		global $pagenow;
		if ( in_array( $pagenow, [ 'update.php', 'plugins.php', 'plugin-install.php' ], true ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( 'kiwe-onboarding' === $page ) return;
		update_user_meta( get_current_user_id(), self::PROMPTED_META, gmdate( 'c' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=kiwe-onboarding&welcome=1' ) );
		exit;
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || $this->profiles->is_complete() ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( 'kiwe-onboarding' === $page ) return;
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Kiwe owner setup is not complete.', 'dsa' ) . '</strong> ' . esc_html__( 'Complete the guided SEO and SEAM Design Context so SiteGraph can give designers accurate business context.', 'dsa' ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=kiwe-onboarding' ) ) . '">' . esc_html__( 'Continue setup', 'dsa' ) . '</a></p></div>';
	}

	public function render(): void {
		if ( ! current_user_can( 'kiwe_manage_context' ) ) wp_die( esc_html__( 'You are not allowed to manage website details.', 'dsa' ) );
		$section = $this->section();
		$labels = array_combine( self::section_slugs(), array_values( $this->steps() ) );
		$invitation = $this->request_invitation();
		if ( isset( $_GET['kiwe_invite'] ) && ! $invitation ) wp_die( esc_html__( 'This onboarding invitation is invalid, expired, revoked, or belongs to another administrator.', 'dsa' ) );
		$p = $this->profiles->current();
		$scores = $this->profiles->scores( $p );
		$seo_report = (array) get_option( Design_Context_Profile_Service::OPTION_SEO_REPORT, [ 'score'=>0, 'components'=>[] ] );
		$flash = get_transient( 'kiwe_onboarding_flash_' . get_current_user_id() );
		if ( $flash ) delete_transient( 'kiwe_onboarding_flash_' . get_current_user_id() );
		$invite_link = get_transient( 'kiwe_onboarding_link_' . get_current_user_id() );
		if ( $invite_link ) delete_transient( 'kiwe_onboarding_link_' . get_current_user_id() );
		?>
		<div class="wrap kiwe-onboarding" data-kiwe-onboarding>
			<header class="kiwe-onboarding__hero">
				<div><span class="kiwe-onboarding__eyebrow"><?php esc_html_e( 'Kiwe · SEAM Design Context', 'dsa' ); ?></span><h1><?php echo esc_html( $labels[ 'kiwe-' . $section ] ); ?></h1><p><?php esc_html_e( 'Manage business identity, contact details, brand direction and website plans in one place. Existing WordPress data is already filled in; store settings appear when a commerce integration is active.', 'dsa' ); ?></p></div>
				<div class="kiwe-onboarding__scores"><div><strong><?php echo esc_html( (string) $scores['designContextStrength'] ); ?>%</strong><span><?php esc_html_e( 'Design context', 'dsa' ); ?></span></div></div>
			</header>
			<?php if ( is_array( $flash ) ) : ?><div class="notice notice-<?php echo esc_attr( $flash['type'] ?? 'info' ); ?> is-dismissible"><p><?php echo esc_html( $flash['message'] ?? '' ); ?></p></div><?php endif; ?>
			<?php if ( is_string( $invite_link ) && $invite_link ) : ?><div class="kiwe-onboarding__share"><strong><?php esc_html_e( 'Owner link created', 'dsa' ); ?></strong><input type="text" readonly value="<?php echo esc_attr( $invite_link ); ?>" data-kiwe-copy-source><button type="button" class="button" data-kiwe-copy><?php esc_html_e( 'Copy link', 'dsa' ); ?></button><small><?php esc_html_e( 'This link expires in seven days, requires the selected administrator to sign in, and cannot authorize another account.', 'dsa' ); ?></small></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-kiwe-onboarding-form>
				<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>"><input type="hidden" name="action" value="kiwe_save_onboarding"><?php wp_nonce_field( 'kiwe_save_onboarding' ); ?>
				<?php if ( $invitation ) : ?><input type="hidden" name="kiwe_invite" value="<?php echo esc_attr( $invitation['id'] ); ?>"><input type="hidden" name="kiwe_token" value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( $_GET['kiwe_token'] ?? '' ) ) ); ?>"><?php endif; ?>

				<?php if ( 'identity' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="0">
					<div class="kiwe-onboarding__intro"><span>01</span><div><h2><?php esc_html_e( 'Your identity', 'dsa' ); ?></h2><p><?php esc_html_e( 'These values become native WordPress identity and reusable Bricks/Kiwe dynamic data.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Website name', 'dsa' ); ?> *</span><input required name="context[identity][siteName]" value="<?php echo esc_attr( $p['identity']['siteName'] ); ?>"></label><label><span><?php esc_html_e( 'Short tagline', 'dsa' ); ?></span><input name="context[identity][tagline]" value="<?php echo esc_attr( $p['identity']['tagline'] ); ?>" maxlength="160"></label><label><span><?php esc_html_e( 'Type of website', 'dsa' ); ?></span><select name="context[identity][siteType]" data-kiwe-site-type><?php foreach ( [ 'business'=>'Business', 'ecommerce'=>'Online store', 'service'=>'Services', 'publication'=>'News or publication', 'portfolio'=>'Portfolio', 'nonprofit'=>'Nonprofit', 'community'=>'Community', 'education'=>'Education', 'other'=>'Other' ] as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['identity']['siteType'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Industry or category', 'dsa' ); ?></span><input name="context[identity][industry]" value="<?php echo esc_attr( $p['identity']['industry'] ); ?>" placeholder="Traditional sweets, healthcare, education…"></label></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Industry group', 'dsa' ); ?></span><select name="context[identity][industrySector]" data-kiwe-industry-sector><?php foreach ( [ ''=>'Choose if known', 'food_beverage'=>'Food and beverage', 'retail'=>'Retail', 'manufacturing'=>'Manufacturing', 'healthcare'=>'Healthcare and wellness', 'education'=>'Education', 'hospitality'=>'Hospitality', 'professional_services'=>'Professional services', 'technology'=>'Technology', 'media'=>'Media and publishing', 'nonprofit'=>'Nonprofit', 'real_estate'=>'Real estate', 'other'=>'Other' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['identity']['industrySector'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><p class="description"><?php esc_html_e( 'This controls relevant owner questions such as food licensing; it does not impose a visual style.', 'dsa' ); ?></p></div>
					<div class="kiwe-media-grid"><?php $this->media_field( 'context[identity][logoId]', (int) $p['identity']['logoId'], __( 'Main logo', 'dsa' ), true, 'logo' ); ?><?php $this->media_field( 'context[identity][logoInverseId]', (int) $p['identity']['logoInverseId'], __( 'Logo for dark backgrounds', 'dsa' ), false, 'logo-inverse' ); ?><?php $this->media_field( 'context[identity][siteIconId]', (int) $p['identity']['siteIconId'], __( 'Square site icon', 'dsa' ), true, 'icon' ); ?></div>
				</section>
				<?php endif; ?>

				<?php if ( 'story' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="1">
					<div class="kiwe-onboarding__intro"><span>02</span><div><h2><?php esc_html_e( 'Story and audience', 'dsa' ); ?></h2><p><?php esc_html_e( 'These optional answers are design and copy context only. They do not become SEO metadata; the separate search description below owns that job.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields"><label><span><?php esc_html_e( 'What does the business or website do?', 'dsa' ); ?></span><textarea name="context[identity][description]" rows="4" placeholder="Explain the offer, origin and what makes it different."><?php echo esc_textarea( $p['identity']['description'] ); ?></textarea></label><label><span><?php esc_html_e( 'Who is it mainly for?', 'dsa' ); ?></span><input name="context[audience][primary]" value="<?php echo esc_attr( $p['audience']['primary'] ); ?>" placeholder="Families buying gifts, local patients, young professionals…"></label><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Where are they?', 'dsa' ); ?></span><input name="context[audience][locations]" value="<?php echo esc_attr( $p['audience']['locations'] ); ?>" placeholder="India, Jammu, worldwide…"></label><label><span><?php esc_html_e( 'What do they need most?', 'dsa' ); ?></span><input name="context[audience][needs]" value="<?php echo esc_attr( $p['audience']['needs'] ); ?>" placeholder="Trust, fast ordering, clear information…"></label></div><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Official or legal business name', 'dsa' ); ?></span><input name="context[seo][legalName]" value="<?php echo esc_attr( $p['seo']['legalName'] ); ?>" placeholder="Only if different from the website name"></label><label><span><?php esc_html_e( 'Year the business started', 'dsa' ); ?></span><input type="number" min="1000" max="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" name="context[seo][foundedYear]" value="<?php echo esc_attr( (string) $p['seo']['foundedYear'] ); ?>" placeholder="1992"></label><label><span><?php esc_html_e( 'Most important website result', 'dsa' ); ?></span><select name="context[seo][primaryGoal]"><?php foreach ( [ ''=>'Not sure', 'buy'=>'Buy online', 'contact'=>'Contact the business', 'book'=>'Book an appointment or service', 'visit'=>'Visit a location', 'subscribe'=>'Subscribe or join', 'donate'=>'Donate', 'read'=>'Read or learn' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['seo']['primaryGoal'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'What might a customer search for?', 'dsa' ); ?></span><input name="context[seo][searchIntent]" value="<?php echo esc_attr( $p['seo']['searchIntent'] ); ?>" placeholder="Traditional Dharwad pedha delivery in India"></label></div><label><span><?php esc_html_e( 'Factual proof customers should know', 'dsa' ); ?></span><textarea name="context[seo][proofPoints]" rows="3" placeholder="Awards, certifications, years in business, service areas or other facts that can be verified."><?php echo esc_textarea( $p['seo']['proofPoints'] ); ?></textarea></label><p class="description"><?php esc_html_e( 'These facts improve the AI design brief and SEO readiness. Kiwe never publishes claims or keyword text that the owner did not provide or later approve.', 'dsa' ); ?></p><label><span><?php esc_html_e( 'Homepage search description', 'dsa' ); ?></span><textarea name="context[seo][homepageDescription]" rows="3" maxlength="320" placeholder="A concise description that can be used as the homepage meta description."><?php echo esc_textarea( $p['seo']['homepageDescription'] ); ?></textarea></label><label class="kiwe-check"><input type="checkbox" name="context[seo][allowIndexing]" value="1" <?php checked( ! empty( $p['seo']['allowIndexing'] ) ); ?>><span><?php esc_html_e( 'Allow search engines to index the website', 'dsa' ); ?></span></label></div>
					<h3><?php esc_html_e( 'About the business', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'These are durable owner facts for About pages and future designs. Leave founder fields empty when the brand story is not founder-led.', 'dsa' ); ?></p>
					<div class="kiwe-fields"><label><span><?php esc_html_e( 'Business or brand story', 'dsa' ); ?></span><textarea name="context[about][story]" rows="4"><?php echo esc_textarea( $p['about']['story'] ); ?></textarea></label><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Mission', 'dsa' ); ?></span><textarea name="context[about][mission]" rows="3"><?php echo esc_textarea( $p['about']['mission'] ); ?></textarea></label><label><span><?php esc_html_e( 'Vision', 'dsa' ); ?></span><textarea name="context[about][vision]" rows="3"><?php echo esc_textarea( $p['about']['vision'] ); ?></textarea></label><label><span><?php esc_html_e( 'Values', 'dsa' ); ?></span><textarea name="context[about][values]" rows="3" placeholder="One per line or a short statement"><?php echo esc_textarea( $p['about']['values'] ); ?></textarea></label><label><span><?php esc_html_e( 'Unique selling proposition', 'dsa' ); ?></span><textarea name="context[about][usp]" rows="3"><?php echo esc_textarea( $p['about']['usp'] ); ?></textarea></label></div></div>
					<h3><?php esc_html_e( 'Founder or principal', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'Link an existing WordPress user when possible. Their WordPress display name and bio remain canonical; Kiwe adds only the public title, LinkedIn and local portrait that WordPress does not own.', 'dsa' ); ?></p><div class="kiwe-person-card" data-kiwe-person><?php $this->user_select( 'context[about][founder][userId]', absint( $p['about']['founder']['userId'] ?? 0 ) ); ?><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Founder or principal name', 'dsa' ); ?></span><input data-kiwe-person-name name="context[about][founder][name]" value="<?php echo esc_attr( $p['about']['founder']['name'] ); ?>"></label><label><span><?php esc_html_e( 'Founder role or title', 'dsa' ); ?></span><input data-kiwe-person-title name="context[about][founder][title]" value="<?php echo esc_attr( $p['about']['founder']['title'] ); ?>"></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Founder bio', 'dsa' ); ?></span><textarea data-kiwe-person-bio name="context[about][founder][bio]" rows="3"><?php echo esc_textarea( $p['about']['founder']['bio'] ); ?></textarea></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Founder LinkedIn', 'dsa' ); ?></span><input data-kiwe-person-linkedin type="url" name="context[about][founder][linkedin]" value="<?php echo esc_url( $p['about']['founder']['linkedin'] ?? '' ); ?>" placeholder="https://www.linkedin.com/in/…"></label></div><div class="kiwe-media-grid"><?php $this->media_field( 'context[about][founder][imageId]', (int) $p['about']['founder']['imageId'], __( 'Founder or principal image', 'dsa' ), false, 'founder' ); ?></div></div>
					<h3><?php esc_html_e( 'Public team', 'dsa' ); ?></h3><p><?php esc_html_e( 'Manage your team in WordPress Users: select eligible staff and set their public title. Names, biographies, portraits and approved social links stay on their user profiles and update your dynamic website automatically.', 'dsa' ); ?> <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Manage team in Users', 'dsa' ); ?></a></p>
				</section>
				<?php endif; ?>

				<?php if ( 'contact' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="2">
					<div class="kiwe-onboarding__intro"><span>03</span><div><h2><?php esc_html_e( 'Contact and location', 'dsa' ); ?></h2><p><?php esc_html_e( 'Public contact details can appear in headers, footers, About and Contact pages through dynamic tags.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Public phone number', 'dsa' ); ?> *</span><input required type="tel" name="context[contact][phone]" value="<?php echo esc_attr( $p['contact']['phone'] ); ?>" data-kiwe-public-phone></label><label><span><?php esc_html_e( 'Public email address', 'dsa' ); ?> *</span><input required type="email" name="context[contact][email]" value="<?php echo esc_attr( $p['contact']['email'] ); ?>"></label><label class="kiwe-check kiwe-field-span"><input type="checkbox" name="context[contact][whatsappSameAsPhone]" value="1" <?php checked( ! empty( $p['contact']['whatsappSameAsPhone'] ) ); ?> data-kiwe-whatsapp-same><span><?php esc_html_e( 'Use the public phone number for WhatsApp', 'dsa' ); ?></span></label><label data-kiwe-whatsapp-field><span><?php esc_html_e( 'Different WhatsApp number', 'dsa' ); ?></span><input type="tel" name="context[contact][whatsapp]" value="<?php echo esc_attr( $p['contact']['whatsapp'] ); ?>" data-kiwe-whatsapp></label><label><span><?php esc_html_e( 'Timezone', 'dsa' ); ?></span><input name="context[localization][timezone]" value="<?php echo esc_attr( $p['localization']['timezone'] ); ?>" data-kiwe-timezone></label></div>
					<h3><?php esc_html_e( 'Business or store address', 'dsa' ); ?></h3><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Address line 1', 'dsa' ); ?></span><input name="context[contact][address][line1]" value="<?php echo esc_attr( $p['contact']['address']['line1'] ); ?>"></label><label><span><?php esc_html_e( 'Address line 2', 'dsa' ); ?></span><input name="context[contact][address][line2]" value="<?php echo esc_attr( $p['contact']['address']['line2'] ); ?>"></label><label><span><?php esc_html_e( 'City', 'dsa' ); ?></span><input name="context[contact][address][city]" value="<?php echo esc_attr( $p['contact']['address']['city'] ); ?>"></label><label><span><?php esc_html_e( 'State or region', 'dsa' ); ?></span><input name="context[contact][address][state]" value="<?php echo esc_attr( $p['contact']['address']['state'] ); ?>"></label><label><span><?php esc_html_e( 'Postal code', 'dsa' ); ?></span><input name="context[contact][address][postcode]" value="<?php echo esc_attr( $p['contact']['address']['postcode'] ); ?>"></label><label><span><?php esc_html_e( 'Country', 'dsa' ); ?></span><?php $this->country_select( $p['contact']['address']['country'] ); ?></label></div>
					<h3><?php esc_html_e( 'Public social profiles', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'Optional. These update Kiwe Links and become SiteGraph/Bricks design context.', 'dsa' ); ?></p><div class="kiwe-fields kiwe-fields--three"><?php foreach ( $this->social_link_labels() as $network=>$label ) : ?><label><span><?php echo esc_html( $label ); ?></span><input type="url" name="context[contact][socialLinks][<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_url( $p['contact']['socialLinks'][ $network ] ?? '' ); ?>" placeholder="https://"></label><?php endforeach; ?></div>
				</section>
				<?php endif; ?>

				<?php if ( 'brand' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="3">
					<p><?php esc_html_e( 'WordPress Media Library is the design resource source—no separate upload list. SiteGraph reads images, video and documents in bounded, searchable pages. Private records stay out of public exports.', 'dsa' ); ?> <a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Open Media Library', 'dsa' ); ?></a></p>
					<div class="kiwe-onboarding__intro"><span>04</span><div><h2><?php esc_html_e( 'Brand feeling', 'dsa' ); ?></h2><p><?php esc_html_e( 'Optional owner preferences, aligned to real SEAM color tokens. SEAM later derives readable text, borders, states, raised surfaces and dark-mode pairs; the owner is not asked to engineer them.', 'dsa' ); ?></p></div></div>
					<h3><?php esc_html_e( 'Overall mood', 'dsa' ); ?></h3><div class="kiwe-choice-grid"><?php foreach ( [ 'pastel'=>'Soft pastel', 'vibrant'=>'Bright & energetic', 'muted'=>'Calm & muted', 'natural'=>'Earthy & natural', 'dark'=>'Rich & dark', 'light'=>'Fresh & light', 'luxury'=>'Elegant & premium', 'playful'=>'Playful', 'minimal'=>'Minimal' ] as $value => $label ) : ?><label><input type="radio" name="context[brand][tone]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $p['brand']['tone'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div><button type="button" class="button-link kiwe-clear-choice" data-kiwe-clear-tone><?php esc_html_e( 'Clear mood preference', 'dsa' ); ?></button>
					<div class="kiwe-color-roles"><?php foreach ( [ 'brand'=>'Primary brand · color-brand', 'accent'=>'Secondary/accent · color-accent', 'hero'=>'Decorative hero · color-hero', 'neutral'=>'Neutral UI · color-neutral', 'surface'=>'Page surface · color-surface' ] as $role => $label ) $this->color_role( $role, $label, $p['brand']['colors'] ); ?></div>
					<label class="kiwe-full"><span><?php esc_html_e( 'Anything the design must keep, avoid or include?', 'dsa' ); ?></span><textarea name="context[brand][notes]" rows="3" placeholder="Visual likes or dislikes, must-keep details, required homepage ideas, interactions or references."><?php echo esc_textarea( $p['brand']['notes'] ); ?></textarea></label>
				</section>
				<?php endif; ?>

				<?php if ( 'website-plan' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="4">
					<div class="kiwe-onboarding__intro"><span>05</span><div><h2><?php esc_html_e( 'Website plan', 'dsa' ); ?></h2><p><?php esc_html_e( 'Pages and their search indexing are managed in WordPress Pages. Design Context reads those same records automatically.', 'dsa' ); ?></p></div></div>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Open WordPress Pages', 'dsa' ); ?></a></p>
					<div class="kiwe-choice-stack"><label class="kiwe-check"><input type="checkbox" name="context[contentPlan][showBlogRailOnHome]" value="1" <?php checked( ! empty( $p['contentPlan']['showBlogRailOnHome'] ) ); ?>><span><?php esc_html_e( 'Show recent articles or a blog rail on the homepage', 'dsa' ); ?></span></label><?php if ( $this->profiles->commerce_available() ) : ?><label class="kiwe-check"><input type="checkbox" name="context[contentPlan][highlightBestsellers]" value="1" <?php checked( ! empty( $p['contentPlan']['highlightBestsellers'] ) ); ?>><span><?php esc_html_e( 'Highlight best-selling products somewhere appropriate', 'dsa' ); ?></span></label><?php endif; ?></div><p class="description"><?php esc_html_e( 'These are content priorities only. The designer or AI decides where and how they fit the approved design.', 'dsa' ); ?></p>
				</section>
				<?php endif; ?>
				<?php if ( 'services' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="6">
					<div class="kiwe-onboarding__intro"><span></span><div><h2><?php esc_html_e( 'Services', 'dsa' ); ?></h2><p><?php esc_html_e( 'Enable this section only if your business offers services. Turning it off preserves existing records and your saved plan.', 'dsa' ); ?></p></div></div>
					<input type="hidden" name="context[services][enabled]" value="0">
					<label class="kiwe-switch"><input type="checkbox" name="context[services][enabled]" value="1" data-kiwe-services-toggle <?php checked( ! empty( $p['services']['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable services', 'dsa' ); ?></span></label>
					<fieldset data-kiwe-services-fields <?php echo empty( $p['services']['enabled'] ) ? 'hidden disabled' : ''; ?>>
					<p class="description"><?php esc_html_e( 'Services remain native WordPress custom-post records. Choose the post type created by the developer, or leave it unbound and record a pending service plan for later handoff.', 'dsa' ); ?></p><?php $service_sources = $this->profiles->service_post_types(); $service_source = sanitize_key( (string) ( $p['services']['sourcePostType'] ?? '' ) ); ?><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Service content source', 'dsa' ); ?></span><select name="context[services][sourcePostType]" data-kiwe-service-source><option value=""><?php esc_html_e( 'Not configured yet · keep as a plan', 'dsa' ); ?></option><?php foreach ( $service_sources as $post_type=>$definition ) : ?><option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $service_source, $post_type ); ?>><?php echo esc_html( $definition['label'] . ' · ' . $post_type ); ?></option><?php endforeach; ?></select></label><label class="kiwe-check"><input type="checkbox" name="context[services][useForNavigation]" value="1" <?php checked( ! empty( $p['services']['useForNavigation'] ) ); ?>><span><?php esc_html_e( 'Make the service hierarchy available for navigation or a mega menu', 'dsa' ); ?></span></label></div><p class="description" data-kiwe-service-source-note><?php echo $service_source ? esc_html__( 'Saving updates this custom post type directly. Existing records are never deleted from this form.', 'dsa' ) : esc_html__( 'No custom post type is being created. These entries remain owner-approved design context until a developer binds a source.', 'dsa' ); ?></p><?php $service_items = $p['services']['items'] ?: [ [] ]; ?><div class="kiwe-service-list" data-kiwe-services><?php foreach ( $service_items as $i=>$service ) $this->service_row( (int) $i, (array) $service, $p ); ?></div><template data-kiwe-service-template><?php $this->service_row( '__INDEX__', [], $p ); ?></template><button type="button" class="button" data-kiwe-add-service><?php esc_html_e( 'Add another service', 'dsa' ); ?></button>
					</fieldset>
				</section>
				<?php endif; ?>

				<?php if ( $this->profiles->commerce_available() ) : ?>
				<?php if ( 'store' === $section ) : ?>
				<section class="kiwe-onboarding__panel" data-kiwe-step="5" data-kiwe-commerce-panel>
					<div class="kiwe-onboarding__intro"><span>06</span><div><h2><?php esc_html_e( 'Store plan', 'dsa' ); ?></h2><p><?php esc_html_e( 'WooCommerce remains the authority for products, prices, tax calculation and shipping zones. This step configures safe store basics and records the owner’s plan.', 'dsa' ); ?></p></div></div>
					<label class="kiwe-switch"><input type="checkbox" name="context[commerce][enabled]" value="1" <?php checked( ! empty( $p['commerce']['enabled'] ) ); ?> data-kiwe-commerce-toggle><span><?php esc_html_e( 'This website sells products', 'dsa' ); ?></span></label>
					<div class="kiwe-fields kiwe-fields--two" data-kiwe-commerce-fields><label><span><?php esc_html_e( 'Expected number of products', 'dsa' ); ?></span><input type="number" min="0" name="context[commerce][expectedProductCount]" value="<?php echo esc_attr( (string) $p['commerce']['expectedProductCount'] ); ?>"></label><label><span><?php esc_html_e( 'Currency', 'dsa' ); ?></span><input maxlength="3" name="context[commerce][currency]" value="<?php echo esc_attr( $p['commerce']['currency'] ); ?>"></label><label><span><?php esc_html_e( 'Currency position', 'dsa' ); ?></span><select name="context[commerce][currencyPosition]"><?php foreach ( [ 'left'=>'Before price', 'right'=>'After price', 'left_space'=>'Before price with space', 'right_space'=>'After price with space' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['currencyPosition'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Price decimal places', 'dsa' ); ?></span><input type="number" min="0" max="6" name="context[commerce][priceDecimals]" value="<?php echo esc_attr( (string) $p['commerce']['priceDecimals'] ); ?>"></label><label><span><?php esc_html_e( 'Weight unit', 'dsa' ); ?></span><select name="context[commerce][weightUnit]"><?php foreach ( [ 'kg'=>'Kilograms', 'g'=>'Grams', 'lbs'=>'Pounds', 'oz'=>'Ounces' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['weightUnit'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Dimension unit', 'dsa' ); ?></span><select name="context[commerce][dimensionUnit]"><?php foreach ( [ 'm'=>'Metres', 'cm'=>'Centimetres', 'mm'=>'Millimetres', 'in'=>'Inches', 'yd'=>'Yards' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['dimensionUnit'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Lowest expected price', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][expectedPriceRange][min]" value="<?php echo esc_attr( (string) $p['commerce']['expectedPriceRange']['min'] ); ?>"></label><label><span><?php esc_html_e( 'Highest expected price', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][expectedPriceRange][max]" value="<?php echo esc_attr( (string) $p['commerce']['expectedPriceRange']['max'] ); ?>"></label><label><span><?php esc_html_e( 'Shipping model for design context', 'dsa' ); ?></span><select name="context[commerce][shippingModel]"><?php foreach ( [ ''=>'Not decided', 'free'=>'Free shipping', 'flat'=>'Flat charge', 'calculated'=>'Calculated by location', 'pickup'=>'Pickup', 'mixed'=>'A mix of methods' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['shippingModel'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Typical shipping charge for previews', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][typicalShippingCharge]" value="<?php echo esc_attr( (string) $p['commerce']['typicalShippingCharge'] ); ?>"></label><label><span><?php esc_html_e( 'Sell to', 'dsa' ); ?></span><select name="context[commerce][sellingLocationMode]" data-kiwe-country-mode="selling"><?php foreach ( [ 'all'=>'All countries', 'all_except'=>'All countries except selected', 'specific'=>'Only selected countries' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['sellingLocationMode'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Ship to', 'dsa' ); ?></span><select name="context[commerce][shippingLocationMode]" data-kiwe-country-mode="shipping"><?php foreach ( [ ''=>'All countries you sell to', 'all'=>'All countries', 'specific'=>'Only selected countries', 'disabled'=>'Shipping disabled' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['shippingLocationMode'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label class="kiwe-field-span" data-kiwe-country-list="selling-specific"><span><?php esc_html_e( 'Countries you sell to', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][sellingCountries][]', $p['commerce']['sellingCountries'] ); ?></label><label class="kiwe-field-span" data-kiwe-country-list="selling-excluded"><span><?php esc_html_e( 'Countries excluded from selling', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][excludedSellingCountries][]', $p['commerce']['excludedSellingCountries'] ); ?></label><label class="kiwe-field-span" data-kiwe-country-list="shipping-specific"><span><?php esc_html_e( 'Countries you ship to', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][shippingCountries][]', $p['commerce']['shippingCountries'] ); ?></label></div>
					<div class="kiwe-choice-stack"><label class="kiwe-check"><input type="checkbox" name="context[commerce][hasBundles]" value="1" <?php checked( ! empty( $p['commerce']['hasBundles'] ) ); ?>><span><?php esc_html_e( 'The store has or will have bundled/grouped products', 'dsa' ); ?></span></label><label class="kiwe-check"><input type="checkbox" name="context[commerce][taxEnabled]" value="1" <?php checked( ! empty( $p['commerce']['taxEnabled'] ) ); ?>><span><?php esc_html_e( 'Enable WooCommerce tax calculation', 'dsa' ); ?></span></label><label class="kiwe-check"><input type="checkbox" name="context[commerce][pricesIncludeTax]" value="1" <?php checked( ! empty( $p['commerce']['pricesIncludeTax'] ) ); ?>><span><?php esc_html_e( 'Entered product prices include tax', 'dsa' ); ?></span></label></div><p class="description"><?php esc_html_e( 'Kiwe does not invent tax rates or overwrite shipping zones. A developer or store manager must configure jurisdiction-specific rates and methods in WooCommerce.', 'dsa' ); ?></p>
					<div data-kiwe-regulatory-fields><h3><?php esc_html_e( 'Business and product disclosures', 'dsa' ); ?></h3><div class="kiwe-fields kiwe-fields--two"><label data-kiwe-food-field><span><?php esc_html_e( 'FSSAI licence number', 'dsa' ); ?></span><input name="context[regulatory][fssaiLicense]" value="<?php echo esc_attr( $p['regulatory']['fssaiLicense'] ); ?>"></label><label class="kiwe-check" data-kiwe-food-field><input type="checkbox" name="context[regulatory][showFssaiOnProducts]" value="1" <?php checked( ! empty( $p['regulatory']['showFssaiOnProducts'] ) ); ?>><span><?php esc_html_e( 'Show FSSAI licence on every product page', 'dsa' ); ?></span></label><label><span><?php esc_html_e( 'GST number', 'dsa' ); ?></span><input name="context[regulatory][gstNumber]" value="<?php echo esc_attr( $p['regulatory']['gstNumber'] ); ?>"></label><label class="kiwe-check"><input type="checkbox" name="context[regulatory][showGstOnProducts]" value="1" <?php checked( ! empty( $p['regulatory']['showGstOnProducts'] ) ); ?>><span><?php esc_html_e( 'Show GST number on product pages', 'dsa' ); ?></span></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Manufacturing address', 'dsa' ); ?></span><textarea name="context[regulatory][manufacturingAddress]" rows="3"><?php echo esc_textarea( $p['regulatory']['manufacturingAddress'] ); ?></textarea></label><label class="kiwe-check kiwe-field-span"><input type="checkbox" name="context[regulatory][showManufacturingAddress]" value="1" <?php checked( ! empty( $p['regulatory']['showManufacturingAddress'] ) ); ?>><span><?php esc_html_e( 'Show the manufacturing address publicly on product pages', 'dsa' ); ?></span></label></div><p class="description"><?php esc_html_e( 'Each WooCommerce product also gets a Nutrition information image field in Product data. SiteGraph supplies the product record and that image to design tools.', 'dsa' ); ?></p></div>
				</section>
				<?php endif; ?>

				<?php endif; ?>

				<footer class="kiwe-onboarding__actions"><span data-kiwe-step-status></span><button type="submit" class="button button-primary button-large" data-kiwe-save><?php echo esc_html( 'Save ' . strtolower( $labels[ 'kiwe-' . $section ] ) ); ?></button></footer>
			</form>

			<?php if ( ! $invitation && current_user_can( 'manage_options' ) ) { $this->refinements->render_panel(); $this->invite_panel(); } ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		check_admin_referer( 'kiwe_save_onboarding' );
		if ( ! current_user_can( 'kiwe_manage_context' ) ) wp_die( esc_html__( 'You are not allowed to manage website details.', 'dsa' ) );
		$section = sanitize_key( wp_unslash( $_POST['section'] ?? '' ) );
		if ( ! in_array( 'kiwe-' . $section, self::section_slugs(), true ) ) wp_die( 'Choose a valid website section.', '', [ 'response'=>400 ] );
		$return = admin_url( 'admin.php?page=kiwe-' . $section );
		$invite = $this->posted_invitation();
		if ( isset( $_POST['kiwe_invite'] ) && ! $invite ) wp_die( esc_html__( 'The invitation could not be verified.', 'dsa' ) );
		$raw = isset( $_POST['context'] ) && is_array( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : [];
		$result = $this->profiles->save_section( $section, $raw, get_current_user_id(), $invite['id'] ?? '' );
		if ( is_wp_error( $result ) ) {
			set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>'error', 'message'=>$result->get_error_message() ], 300 );
			$data = $result->get_error_data();
			$step = $this->step_for_error_fields( is_array( $data['fields'] ?? null ) ? $data['fields'] : [] );
			wp_safe_redirect( add_query_arg( 'incomplete', 1, $return ) ); exit;
		}
		if ( $invite && $this->profiles->is_complete() ) $this->complete_invitation( $invite['id'] );
		set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>'success', 'message'=>__( 'Owner context saved. SiteGraph now has the updated SEO and design evidence.', 'dsa' ) ], 300 );
		wp_safe_redirect( add_query_arg( 'saved', 1, $return ) ); exit;
	}

	public function handle_invite(): void {
		check_admin_referer( 'kiwe_create_onboarding_invite' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to create invitations.', 'dsa' ) );
		$user_id = absint( $_POST['user_id'] ?? 0 ); $user = get_user_by( 'id', $user_id );
		if ( ! $user || ! user_can( $user, 'kiwe_manage_context' ) ) {
			set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>'error', 'message'=>__( 'Choose an administrator account.', 'dsa' ) ], 300 );
			wp_safe_redirect( admin_url( 'admin.php?page=kiwe-onboarding' ) ); exit;
		}
		$token = bin2hex( random_bytes( 32 ) ); $id = wp_generate_uuid4();
		$items = $this->invitations();
		$items[ $id ] = [ 'id'=>$id, 'tokenHash'=>wp_hash_password( $token ), 'userId'=>$user_id, 'createdBy'=>get_current_user_id(), 'createdAt'=>time(), 'expiresAt'=>time()+7*DAY_IN_SECONDS, 'completed'=>false, 'revoked'=>false ];
		update_option( self::INVITATIONS_OPTION, $items, false );
		$link = add_query_arg( [ 'page'=>'kiwe-onboarding', 'kiwe_invite'=>$id, 'kiwe_token'=>$token ], admin_url( 'admin.php' ) );
		$channel = in_array( $_POST['channel'] ?? '', [ 'email', 'sms', 'whatsapp', 'copy' ], true ) ? (string) $_POST['channel'] : 'copy';
		$message = sprintf( __( 'You have been invited to complete the owner setup for %1$s. Sign in with your administrator account and use this secure link within seven days: %2$s', 'dsa' ), get_bloginfo( 'name' ), $link );
		$sent = false;
		if ( 'email' === $channel ) $sent = wp_mail( $user->user_email, sprintf( __( 'Complete %s website setup', 'dsa' ), get_bloginfo( 'name' ) ), $message );
		if ( in_array( $channel, [ 'sms', 'whatsapp' ], true ) ) {
			$recipient = sanitize_text_field( (string) wp_unslash( $_POST['recipient'] ?? '' ) );
			$result = $this->channels->send( $channel, $recipient, __( 'Kiwe owner onboarding', 'dsa' ), $message, [ 'purpose'=>'notification_campaign', 'transactional'=>true ] );
			$sent = ! is_wp_error( $result );
		}
		set_transient( 'kiwe_onboarding_link_' . get_current_user_id(), $link, 600 );
		set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>$sent || 'copy' === $channel ? 'success' : 'warning', 'message'=>$sent ? __( 'The invitation was sent and a copyable link is shown below.', 'dsa' ) : __( 'The secure link was created. Copy it manually; the selected delivery channel did not confirm delivery.', 'dsa' ) ], 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=kiwe-onboarding&invite-created=1' ) ); exit;
	}

	private function invite_panel(): void {
		$admins = get_users( [ 'role__in'=>[ 'administrator' ], 'orderby'=>'display_name' ] );
		?>
		<section class="kiwe-onboarding__invite"><div><span class="kiwe-onboarding__eyebrow"><?php esc_html_e( 'Developer handoff', 'dsa' ); ?></span><h2><?php esc_html_e( 'Let the site owner complete it', 'dsa' ); ?></h2><p><?php esc_html_e( 'Create an account-bound link. It requires the selected administrator to sign in, expires in seven days and can be delivered by WordPress email, an already configured Kiwe SMS/WhatsApp channel, or copied manually.', 'dsa' ); ?></p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_create_onboarding_invite"><?php wp_nonce_field( 'kiwe_create_onboarding_invite' ); ?><label><span><?php esc_html_e( 'Site owner administrator', 'dsa' ); ?></span><select name="user_id"><?php foreach ( $admins as $admin ) : ?><option value="<?php echo esc_attr( (string) $admin->ID ); ?>"><?php echo esc_html( $admin->display_name . ' · ' . $admin->user_email ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Delivery', 'dsa' ); ?></span><select name="channel"><option value="email"><?php esc_html_e( 'WordPress email', 'dsa' ); ?></option><option value="copy"><?php esc_html_e( 'Copy link manually', 'dsa' ); ?></option><option value="whatsapp"><?php esc_html_e( 'Configured WhatsApp channel', 'dsa' ); ?></option><option value="sms"><?php esc_html_e( 'Configured SMS channel', 'dsa' ); ?></option></select></label><label><span><?php esc_html_e( 'Phone recipient (SMS/WhatsApp only)', 'dsa' ); ?></span><input name="recipient" type="tel"></label><button class="button button-primary" type="submit"><?php esc_html_e( 'Create owner link', 'dsa' ); ?></button></form></section>
		<?php
	}

	private function media_field( string $name, int $id, string $label, bool $required, string $kind ): void {
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		echo '<label class="kiwe-media-field" data-kiwe-media-field><span>' . esc_html( $label ) . ( $required ? ' *' : '' ) . '</span><input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $id ) . '" data-kiwe-media-id' . ( $required ? ' required' : '' ) . '><span class="kiwe-media-field__preview" data-kiwe-media-preview>' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '<em>' . esc_html__( 'No image selected', 'dsa' ) . '</em>' ) . '</span><button type="button" class="button" data-kiwe-media-select data-kind="' . esc_attr( $kind ) . '">' . esc_html__( 'Choose image', 'dsa' ) . '</button></label>';
	}


	private function user_select( string $name, int $selected ): void {
		$users = get_users( [ 'orderby'=>'display_name', 'order'=>'ASC' ] );
		$founder_select = str_contains( $name, '[founder]' );
		echo '<label class="kiwe-user-link"><span>' . esc_html__( 'Link to an existing WordPress user (optional)', 'dsa' ) . '</span><select name="' . esc_attr( $name ) . '" data-kiwe-person-user><option value="0">' . esc_html__( 'Not linked to an account yet', 'dsa' ) . '</option>';
		foreach ( $users as $user ) {
			$payload = [
				'name'=>sanitize_text_field( (string) $user->display_name ),
				'bio'=>sanitize_textarea_field( (string) $user->description ),
				'title'=>sanitize_text_field( (string) get_user_meta( $user->ID, $founder_select ? Design_Context_Profile_Service::USER_META_FOUNDER_TITLE : Design_Context_Profile_Service::USER_META_TEAM_TITLE, true ) ),
				'linkedin'=>esc_url_raw( (string) get_user_meta( $user->ID, Design_Context_Profile_Service::USER_META_LINKEDIN, true ) ),
				'imageId'=>absint( get_user_meta( $user->ID, Design_Context_Profile_Service::USER_META_AVATAR_ID, true ) ),
			];
			$payload['imageUrl'] = $payload['imageId'] ? esc_url_raw( (string) wp_get_attachment_image_url( $payload['imageId'], 'medium' ) ) : '';
			echo '<option value="' . esc_attr( (string) $user->ID ) . '" data-kiwe-user-profile="' . esc_attr( (string) wp_json_encode( $payload ) ) . '"' . selected( $selected, $user->ID, false ) . '>' . esc_html( $user->display_name . ' · #' . $user->ID ) . '</option>';
		}
		echo '</select></label>';
	}

	private function service_row( $index, array $service, array $profile ): void {
		$base = 'context[services][items][' . (string) $index . ']';
		$record_id = absint( $service['recordId'] ?? 0 );
		$source = sanitize_key( (string) ( $profile['services']['sourcePostType'] ?? '' ) );
		$sources = $this->profiles->service_post_types();
		$taxonomies = $source ? $this->profiles->service_taxonomies( $source ) : [];
		$meta_fields = $source ? $this->profiles->service_meta_fields( $source ) : [];
		?>
		<div class="kiwe-service-card" data-kiwe-service-row>
			<input type="hidden" name="<?php echo esc_attr( $base . '[stableId]' ); ?>" value="<?php echo esc_attr( (string) ( $service['stableId'] ?? '' ) ); ?>"><input type="hidden" name="<?php echo esc_attr( $base . '[recordId]' ); ?>" value="<?php echo esc_attr( (string) $record_id ); ?>">
			<div class="kiwe-service-card__head"><strong><?php echo $record_id ? esc_html( sprintf( __( 'Native service #%d', 'dsa' ), $record_id ) ) : esc_html__( 'Pending/new service', 'dsa' ); ?></strong><?php if ( ! $record_id ) : ?><button type="button" class="button-link-delete" data-kiwe-remove-service><?php esc_html_e( 'Remove', 'dsa' ); ?></button><?php endif; ?></div>
			<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Service name', 'dsa' ); ?></span><input name="<?php echo esc_attr( $base . '[title]' ); ?>" value="<?php echo esc_attr( (string) ( $service['title'] ?? '' ) ); ?>"></label><label><span><?php esc_html_e( 'Short summary', 'dsa' ); ?></span><textarea name="<?php echo esc_attr( $base . '[summary]' ); ?>" rows="2"><?php echo esc_textarea( (string) ( $service['summary'] ?? '' ) ); ?></textarea></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Full service description', 'dsa' ); ?></span><textarea name="<?php echo esc_attr( $base . '[description]' ); ?>" rows="5"><?php echo esc_textarea( (string) ( $service['description'] ?? '' ) ); ?></textarea></label><label><span><?php esc_html_e( 'Visibility', 'dsa' ); ?></span><select name="<?php echo esc_attr( $base . '[status]' ); ?>"><option value="draft" <?php selected( (string) ( $service['status'] ?? 'draft' ), 'draft' ); ?>><?php esc_html_e( 'Draft · review first', 'dsa' ); ?></option><option value="publish" <?php selected( (string) ( $service['status'] ?? '' ), 'publish' ); ?>><?php esc_html_e( 'Published', 'dsa' ); ?></option></select></label><label><span><?php esc_html_e( 'Display order', 'dsa' ); ?></span><input type="number" name="<?php echo esc_attr( $base . '[menuOrder]' ); ?>" value="<?php echo esc_attr( (string) ( $service['menuOrder'] ?? $index ) ); ?>"></label><?php if ( $source && ! empty( $sources[ $source ]['hierarchical'] ) ) : ?><label><span><?php esc_html_e( 'Parent service', 'dsa' ); ?></span><select name="<?php echo esc_attr( $base . '[parentId]' ); ?>"><option value="0"><?php esc_html_e( 'No parent', 'dsa' ); ?></option><?php foreach ( (array) ( $profile['services']['items'] ?? [] ) as $parent ) : $parent_id=absint( $parent['recordId'] ?? 0 ); if ( ! $parent_id || $parent_id === $record_id ) continue; ?><option value="<?php echo esc_attr( (string) $parent_id ); ?>" <?php selected( absint( $service['parentId'] ?? 0 ), $parent_id ); ?>><?php echo esc_html( (string) ( $parent['title'] ?? '#' . $parent_id ) ); ?></option><?php endforeach; ?></select></label><?php else : ?><input type="hidden" name="<?php echo esc_attr( $base . '[parentId]' ); ?>" value="0"><?php endif; ?></div>
			<?php if ( $taxonomies ) : ?><div class="kiwe-fields kiwe-fields--two kiwe-service-taxonomies"><?php foreach ( $taxonomies as $taxonomy=>$definition ) : ?><label><span><?php echo esc_html( $definition['label'] ); ?></span><textarea name="<?php echo esc_attr( $base . '[taxonomyPaths][' . $taxonomy . ']' ); ?>" rows="2" placeholder="<?php echo esc_attr( ! empty( $definition['hierarchical'] ) ? 'Parent > Child, Another category' : 'Category, Another category' ); ?>"><?php echo esc_textarea( (string) ( $service['taxonomyPaths'][ $taxonomy ] ?? '' ) ); ?></textarea></label><?php endforeach; ?></div><p class="description"><?php esc_html_e( 'Separate categories with commas or new lines. Missing names are created in the selected native taxonomy; use Parent > Child for hierarchy.', 'dsa' ); ?></p><?php else : ?><label class="kiwe-full"><span><?php esc_html_e( 'Service categories or hierarchy', 'dsa' ); ?></span><textarea name="<?php echo esc_attr( $base . '[categoryPaths]' ); ?>" rows="2" placeholder="Parent > Child, Another category"><?php echo esc_textarea( (string) ( $service['categoryPaths'] ?? '' ) ); ?></textarea></label><?php endif; ?>
			<?php if ( $meta_fields ) : ?><div class="kiwe-fields kiwe-fields--two kiwe-service-meta"><?php foreach ( $meta_fields as $meta_key=>$definition ) : $meta_value=$service['meta'][ $meta_key ] ?? ''; ?><label class="<?php echo 'boolean' === $definition['type'] ? 'kiwe-check' : ''; ?>"><?php if ( 'boolean' === $definition['type'] ) : ?><input type="checkbox" name="<?php echo esc_attr( $base . '[meta][' . $meta_key . ']' ); ?>" value="1" <?php checked( ! empty( $meta_value ) ); ?>><span><?php echo esc_html( $definition['label'] ); ?></span><?php else : ?><span><?php echo esc_html( $definition['label'] ); ?></span><input type="<?php echo esc_attr( in_array( $definition['type'], [ 'number','integer' ], true ) ? 'number' : 'text' ); ?>" <?php echo 'number' === $definition['type'] ? 'step="any"' : ''; ?> name="<?php echo esc_attr( $base . '[meta][' . $meta_key . ']' ); ?>" value="<?php echo esc_attr( (string) $meta_value ); ?>"><?php endif; ?></label><?php endforeach; ?></div><?php endif; ?>
			<div class="kiwe-media-grid"><?php $this->media_field( $base . '[imageId]', absint( $service['imageId'] ?? 0 ), __( 'Service image', 'dsa' ), false, 'service' ); ?></div>
		</div>
		<?php
	}

	private function country_select( string $selected ): void {
		$countries = function_exists( 'WC' ) && WC() && WC()->countries ? WC()->countries->get_countries() : [ 'IN'=>'India', 'US'=>'United States', 'GB'=>'United Kingdom', 'AE'=>'United Arab Emirates', 'CA'=>'Canada', 'AU'=>'Australia' ];
		echo '<select name="context[contact][address][country]"><option value="">' . esc_html__( 'Select country', 'dsa' ) . '</option>';
		foreach ( $countries as $code=>$name ) echo '<option value="' . esc_attr( $code ) . '"' . selected( $selected, $code, false ) . '>' . esc_html( $name ) . '</option>';
		echo '</select>';
	}

	private function country_multiselect( string $name, array $selected ): void {
		$countries = function_exists( 'WC' ) && WC() && WC()->countries ? WC()->countries->get_countries() : [ 'IN'=>'India', 'US'=>'United States', 'GB'=>'United Kingdom', 'AE'=>'United Arab Emirates', 'CA'=>'Canada', 'AU'=>'Australia' ];
		$selected = array_map( 'strtoupper', array_map( 'sanitize_text_field', $selected ) );
		echo '<select multiple size="7" name="' . esc_attr( $name ) . '">';
		foreach ( $countries as $code=>$label ) echo '<option value="' . esc_attr( $code ) . '"' . selected( in_array( strtoupper( (string) $code ), $selected, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select><small>' . esc_html__( 'Hold Ctrl or Command to select more than one country.', 'dsa' ) . '</small>';
	}

	private function social_link_labels(): array {
		return [ 'facebook'=>'Facebook', 'instagram'=>'Instagram', 'x'=>'X', 'youtube'=>'YouTube', 'pinterest'=>'Pinterest', 'linkedin'=>'LinkedIn' ];
	}

	private function step_for_error_fields( array $fields ): int {
		$first = sanitize_key( (string) ( $fields[0] ?? '' ) );
		if ( in_array( $first, [ 'sitename', 'logoid', 'siteiconid' ], true ) ) return 0;
		if ( in_array( $first, [ 'phone', 'email', 'address' ], true ) ) return 2;
		return 6;
	}

	private function color_role( string $role, string $label, array $selected ): void {
		$current = [];
		foreach ( $selected as $color ) if ( ( $color['role'] ?? '' ) === $role ) $current = $color;
		$palette = [ 'red'=>'#dc2626','coral'=>'#f97360','orange'=>'#f97316','amber'=>'#f59e0b','yellow'=>'#eab308','lime'=>'#84cc16','green'=>'#16a34a','emerald'=>'#059669','teal'=>'#0d9488','cyan'=>'#0891b2','sky'=>'#0284c7','blue'=>'#2563eb','indigo'=>'#4f46e5','violet'=>'#7c3aed','purple'=>'#9333ea','magenta'=>'#c026d3','pink'=>'#db2777','rose'=>'#e11d48','brown'=>'#92400e','sand'=>'#c4a574','olive'=>'#6b7b3e','navy'=>'#1e3a5f','grey'=>'#64748b','black'=>'#171717' ];
		echo '<fieldset class="kiwe-color-role"><legend>' . esc_html( $label ) . '</legend><label class="kiwe-color-none"><input type="radio" name="context[brand][colors][' . esc_attr( $role ) . '][hex]" value=""' . checked( empty( $current['hex'] ), true, false ) . '><span>' . esc_html__( 'Skip', 'dsa' ) . '</span></label><input type="hidden" name="context[brand][colors][' . esc_attr( $role ) . '][role]" value="' . esc_attr( $role ) . '">';
		foreach ( $palette as $name=>$hex ) echo '<label title="' . esc_attr( ucfirst( $name ) ) . '"><input type="radio" name="context[brand][colors][' . esc_attr( $role ) . '][hex]" value="' . esc_attr( $hex ) . '"' . checked( $current['hex'] ?? '', $hex, false ) . '><span style="--kiwe-swatch:' . esc_attr( $hex ) . '"></span></label>';
		echo '</fieldset>';
	}

	private function invitations(): array { $items = get_option( self::INVITATIONS_OPTION, [] ); return is_array( $items ) ? $items : []; }
	private function request_invitation(): ?array { return $this->validate_invitation( sanitize_key( (string) wp_unslash( $_GET['kiwe_invite'] ?? '' ) ), sanitize_text_field( (string) wp_unslash( $_GET['kiwe_token'] ?? '' ) ) ); }
	private function posted_invitation(): ?array { return $this->validate_invitation( sanitize_key( (string) wp_unslash( $_POST['kiwe_invite'] ?? '' ) ), sanitize_text_field( (string) wp_unslash( $_POST['kiwe_token'] ?? '' ) ) ); }
	private function validate_invitation( string $id, string $token ): ?array {
		if ( '' === $id || '' === $token ) return null;
		$item = $this->invitations()[ $id ] ?? null;
		if ( ! is_array( $item ) || ! empty( $item['revoked'] ) || ! empty( $item['completed'] ) || (int) ( $item['expiresAt'] ?? 0 ) < time() || (int) ( $item['userId'] ?? 0 ) !== get_current_user_id() || ! wp_check_password( $token, (string) ( $item['tokenHash'] ?? '' ) ) ) return null;
		return $item;
	}
	private function complete_invitation( string $id ): void { $items=$this->invitations(); if ( isset( $items[$id] ) ) { $items[$id]['completed']=true; $items[$id]['completedAt']=time(); update_option( self::INVITATIONS_OPTION, $items, false ); } }
}
