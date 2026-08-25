<?php

namespace DSA\Onboarding;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Onboarding_Service {
	private const INVITATIONS_OPTION = 'kiwe_onboarding_invitations_v1';
	private const PROMPTED_META = '_kiwe_onboarding_prompted_v1';

	public function __construct( private Design_Context_Profile_Service $profiles, private Channel_Service $channels ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_init', [ $this, 'maybe_open_fresh_install' ], 30 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_kiwe_save_onboarding', [ $this, 'handle_save' ] );
		add_action( 'admin_post_kiwe_create_onboarding_invite', [ $this, 'handle_invite' ] );
	}

	public function menu(): void {
		add_submenu_page( 'kiwe', __( 'Kiwe Owner Onboarding', 'dsa' ), __( 'Onboarding', 'dsa' ), 'manage_options', 'kiwe-onboarding', [ $this, 'render' ] );
	}

	public function assets( string $hook ): void {
		if ( 'kiwe_page_kiwe-onboarding' !== $hook ) return;
		wp_enqueue_media();
		wp_enqueue_style( 'kiwe-onboarding', DSA_URL . 'assets/css/onboarding.css', [], DSA_VERSION );
		wp_enqueue_script( 'kiwe-onboarding', DSA_URL . 'assets/js/onboarding.js', [], DSA_VERSION, true );
		$saved = ! empty( $_GET['saved'] );
		$step  = $saved ? 6 : min( 6, absint( $_GET['step'] ?? 0 ) );
		wp_localize_script( 'kiwe-onboarding', 'KIWE_ONBOARDING', [
			'chooseImage' => __( 'Choose image', 'dsa' ),
			'useImage'    => __( 'Use this image', 'dsa' ),
			'saving'      => __( 'Saving owner context…', 'dsa' ),
			'saved'       => $saved,
			'startStep'   => $step,
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
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to complete this onboarding.', 'dsa' ) );
		$invitation = $this->request_invitation();
		if ( isset( $_GET['kiwe_invite'] ) && ! $invitation ) wp_die( esc_html__( 'This onboarding invitation is invalid, expired, revoked, or belongs to another administrator.', 'dsa' ) );
		$p = $this->profiles->current(); $scores = $this->profiles->scores( $p );
		$flash = get_transient( 'kiwe_onboarding_flash_' . get_current_user_id() );
		if ( $flash ) delete_transient( 'kiwe_onboarding_flash_' . get_current_user_id() );
		$invite_link = get_transient( 'kiwe_onboarding_link_' . get_current_user_id() );
		if ( $invite_link ) delete_transient( 'kiwe_onboarding_link_' . get_current_user_id() );
		?>
		<div class="wrap kiwe-onboarding" data-kiwe-onboarding>
			<header class="kiwe-onboarding__hero">
				<div><span class="kiwe-onboarding__eyebrow"><?php esc_html_e( 'Kiwe · SEAM Design Context', 'dsa' ); ?></span><h1><?php esc_html_e( 'Tell your website what the owner already knows', 'dsa' ); ?></h1><p><?php esc_html_e( 'A guided setup for business identity, contact details, SEO foundations, brand direction and store context. Existing WordPress and WooCommerce information is already filled in.', 'dsa' ); ?></p></div>
				<div class="kiwe-onboarding__scores"><div><strong><?php echo esc_html( (string) $scores['seoStrength'] ); ?>%</strong><span><?php esc_html_e( 'SEO strength', 'dsa' ); ?></span></div><div><strong><?php echo esc_html( (string) $scores['designContextStrength'] ); ?>%</strong><span><?php esc_html_e( 'Design context', 'dsa' ); ?></span></div></div>
			</header>
			<?php if ( is_array( $flash ) ) : ?><div class="notice notice-<?php echo esc_attr( $flash['type'] ?? 'info' ); ?> is-dismissible"><p><?php echo esc_html( $flash['message'] ?? '' ); ?></p></div><?php endif; ?>
			<?php if ( is_string( $invite_link ) && $invite_link ) : ?><div class="kiwe-onboarding__share"><strong><?php esc_html_e( 'Owner link created', 'dsa' ); ?></strong><input type="text" readonly value="<?php echo esc_attr( $invite_link ); ?>" data-kiwe-copy-source><button type="button" class="button" data-kiwe-copy><?php esc_html_e( 'Copy link', 'dsa' ); ?></button><small><?php esc_html_e( 'This link expires in seven days, requires the selected administrator to sign in, and cannot authorize another account.', 'dsa' ); ?></small></div><?php endif; ?>

			<nav class="kiwe-onboarding__steps" aria-label="<?php esc_attr_e( 'Onboarding progress', 'dsa' ); ?>">
				<?php foreach ( [ 'Identity', 'Story', 'Contact', 'Brand', 'Website plan', 'Store', 'Review' ] as $i => $label ) : ?><button type="button" data-kiwe-step-button="<?php echo esc_attr( (string) $i ); ?>"><span><?php echo esc_html( (string) ( $i + 1 ) ); ?></span><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-kiwe-onboarding-form>
				<input type="hidden" name="action" value="kiwe_save_onboarding"><?php wp_nonce_field( 'kiwe_save_onboarding' ); ?>
				<?php if ( $invitation ) : ?><input type="hidden" name="kiwe_invite" value="<?php echo esc_attr( $invitation['id'] ); ?>"><input type="hidden" name="kiwe_token" value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( $_GET['kiwe_token'] ?? '' ) ) ); ?>"><?php endif; ?>

				<section class="kiwe-onboarding__panel" data-kiwe-step="0">
					<div class="kiwe-onboarding__intro"><span>01</span><div><h2><?php esc_html_e( 'Your identity', 'dsa' ); ?></h2><p><?php esc_html_e( 'These values become native WordPress identity and reusable Bricks/Kiwe dynamic data.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Website or store name', 'dsa' ); ?> *</span><input required name="context[identity][siteName]" value="<?php echo esc_attr( $p['identity']['siteName'] ); ?>"></label><label><span><?php esc_html_e( 'Short tagline', 'dsa' ); ?></span><input name="context[identity][tagline]" value="<?php echo esc_attr( $p['identity']['tagline'] ); ?>" maxlength="160"></label><label><span><?php esc_html_e( 'Type of website', 'dsa' ); ?></span><select name="context[identity][siteType]" data-kiwe-site-type><?php foreach ( [ 'business'=>'Business', 'ecommerce'=>'Online store', 'service'=>'Services', 'publication'=>'News or publication', 'portfolio'=>'Portfolio', 'nonprofit'=>'Nonprofit', 'community'=>'Community', 'education'=>'Education', 'other'=>'Other' ] as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['identity']['siteType'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Industry or category', 'dsa' ); ?></span><input name="context[identity][industry]" value="<?php echo esc_attr( $p['identity']['industry'] ); ?>" placeholder="Traditional sweets, healthcare, education…"></label></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Industry group', 'dsa' ); ?></span><select name="context[identity][industrySector]" data-kiwe-industry-sector><?php foreach ( [ ''=>'Choose if known', 'food_beverage'=>'Food and beverage', 'retail'=>'Retail', 'manufacturing'=>'Manufacturing', 'healthcare'=>'Healthcare and wellness', 'education'=>'Education', 'hospitality'=>'Hospitality', 'professional_services'=>'Professional services', 'technology'=>'Technology', 'media'=>'Media and publishing', 'nonprofit'=>'Nonprofit', 'real_estate'=>'Real estate', 'other'=>'Other' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['identity']['industrySector'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><p class="description"><?php esc_html_e( 'This controls relevant owner questions such as food licensing; it does not impose a visual style.', 'dsa' ); ?></p></div>
					<div class="kiwe-media-grid"><?php $this->media_field( 'context[identity][logoId]', (int) $p['identity']['logoId'], __( 'Main logo', 'dsa' ), true, 'logo' ); ?><?php $this->media_field( 'context[identity][logoInverseId]', (int) $p['identity']['logoInverseId'], __( 'Logo for dark backgrounds', 'dsa' ), false, 'logo-inverse' ); ?><?php $this->media_field( 'context[identity][siteIconId]', (int) $p['identity']['siteIconId'], __( 'Square site icon', 'dsa' ), true, 'icon' ); ?></div>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="1" hidden>
					<div class="kiwe-onboarding__intro"><span>02</span><div><h2><?php esc_html_e( 'Story and audience', 'dsa' ); ?></h2><p><?php esc_html_e( 'These optional answers are design and copy context only. They do not become SEO metadata; the separate search description below owns that job.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields"><label><span><?php esc_html_e( 'What does the business or website do?', 'dsa' ); ?></span><textarea name="context[identity][description]" rows="4" placeholder="Explain the offer, origin and what makes it different."><?php echo esc_textarea( $p['identity']['description'] ); ?></textarea></label><label><span><?php esc_html_e( 'Who is it mainly for?', 'dsa' ); ?></span><input name="context[audience][primary]" value="<?php echo esc_attr( $p['audience']['primary'] ); ?>" placeholder="Families buying gifts, local patients, young professionals…"></label><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Where are they?', 'dsa' ); ?></span><input name="context[audience][locations]" value="<?php echo esc_attr( $p['audience']['locations'] ); ?>" placeholder="India, Jammu, worldwide…"></label><label><span><?php esc_html_e( 'What do they need most?', 'dsa' ); ?></span><input name="context[audience][needs]" value="<?php echo esc_attr( $p['audience']['needs'] ); ?>" placeholder="Trust, fast ordering, clear information…"></label></div><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Official or legal business name', 'dsa' ); ?></span><input name="context[seo][legalName]" value="<?php echo esc_attr( $p['seo']['legalName'] ); ?>" placeholder="Only if different from the website name"></label><label><span><?php esc_html_e( 'Year the business started', 'dsa' ); ?></span><input type="number" min="1000" max="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" name="context[seo][foundedYear]" value="<?php echo esc_attr( (string) $p['seo']['foundedYear'] ); ?>" placeholder="1992"></label><label><span><?php esc_html_e( 'Most important website result', 'dsa' ); ?></span><select name="context[seo][primaryGoal]"><?php foreach ( [ ''=>'Not sure', 'buy'=>'Buy online', 'contact'=>'Contact the business', 'book'=>'Book an appointment or service', 'visit'=>'Visit a location', 'subscribe'=>'Subscribe or join', 'donate'=>'Donate', 'read'=>'Read or learn' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['seo']['primaryGoal'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'What might a customer search for?', 'dsa' ); ?></span><input name="context[seo][searchIntent]" value="<?php echo esc_attr( $p['seo']['searchIntent'] ); ?>" placeholder="Traditional Dharwad pedha delivery in India"></label></div><label><span><?php esc_html_e( 'Factual proof customers should know', 'dsa' ); ?></span><textarea name="context[seo][proofPoints]" rows="3" placeholder="Awards, certifications, years in business, service areas or other facts that can be verified."><?php echo esc_textarea( $p['seo']['proofPoints'] ); ?></textarea></label><p class="description"><?php esc_html_e( 'These facts improve the AI design brief and SEO readiness. Kiwe never publishes claims or keyword text that the owner did not provide or later approve.', 'dsa' ); ?></p><label><span><?php esc_html_e( 'Homepage search description', 'dsa' ); ?></span><textarea name="context[seo][homepageDescription]" rows="3" maxlength="320" placeholder="A concise description that can be used as the homepage meta description."><?php echo esc_textarea( $p['seo']['homepageDescription'] ); ?></textarea></label><label class="kiwe-check"><input type="checkbox" name="context[seo][allowIndexing]" value="1" <?php checked( ! empty( $p['seo']['allowIndexing'] ) ); ?>><span><?php esc_html_e( 'Allow search engines to index the website', 'dsa' ); ?></span></label></div>
					<h3><?php esc_html_e( 'About the business', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'These are durable owner facts for About pages and future designs. Leave founder fields empty when the brand story is not founder-led.', 'dsa' ); ?></p>
					<div class="kiwe-fields"><label><span><?php esc_html_e( 'Business or brand story', 'dsa' ); ?></span><textarea name="context[about][story]" rows="4"><?php echo esc_textarea( $p['about']['story'] ); ?></textarea></label><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Mission', 'dsa' ); ?></span><textarea name="context[about][mission]" rows="3"><?php echo esc_textarea( $p['about']['mission'] ); ?></textarea></label><label><span><?php esc_html_e( 'Vision', 'dsa' ); ?></span><textarea name="context[about][vision]" rows="3"><?php echo esc_textarea( $p['about']['vision'] ); ?></textarea></label><label><span><?php esc_html_e( 'Values', 'dsa' ); ?></span><textarea name="context[about][values]" rows="3" placeholder="One per line or a short statement"><?php echo esc_textarea( $p['about']['values'] ); ?></textarea></label><label><span><?php esc_html_e( 'Unique selling proposition', 'dsa' ); ?></span><textarea name="context[about][usp]" rows="3"><?php echo esc_textarea( $p['about']['usp'] ); ?></textarea></label></div></div>
					<h3><?php esc_html_e( 'Founder or principal', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'Link an existing WordPress user when possible. Their WordPress display name and bio remain canonical; Kiwe adds only the public title, LinkedIn and local portrait that WordPress does not own.', 'dsa' ); ?></p><div class="kiwe-person-card" data-kiwe-person><?php $this->user_select( 'context[about][founder][userId]', absint( $p['about']['founder']['userId'] ?? 0 ) ); ?><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Founder or principal name', 'dsa' ); ?></span><input data-kiwe-person-name name="context[about][founder][name]" value="<?php echo esc_attr( $p['about']['founder']['name'] ); ?>"></label><label><span><?php esc_html_e( 'Founder role or title', 'dsa' ); ?></span><input data-kiwe-person-title name="context[about][founder][title]" value="<?php echo esc_attr( $p['about']['founder']['title'] ); ?>"></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Founder bio', 'dsa' ); ?></span><textarea data-kiwe-person-bio name="context[about][founder][bio]" rows="3"><?php echo esc_textarea( $p['about']['founder']['bio'] ); ?></textarea></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Founder LinkedIn', 'dsa' ); ?></span><input data-kiwe-person-linkedin type="url" name="context[about][founder][linkedin]" value="<?php echo esc_url( $p['about']['founder']['linkedin'] ?? '' ); ?>" placeholder="https://www.linkedin.com/in/…"></label></div><div class="kiwe-media-grid"><?php $this->media_field( 'context[about][founder][imageId]', (int) $p['about']['founder']['imageId'], __( 'Founder or principal image', 'dsa' ), false, 'founder' ); ?></div></div>
					<h3><?php esc_html_e( 'Public team', 'dsa' ); ?></h3><div class="kiwe-choice-grid kiwe-choice-grid--binary" data-kiwe-team-toggle><?php foreach ( [ '0'=>__( 'No team section', 'dsa' ), '1'=>__( 'Yes, show a team', 'dsa' ) ] as $value=>$label ) : ?><label><input type="radio" name="context[about][team][enabled]" value="<?php echo esc_attr( $value ); ?>" <?php checked( ! empty( $p['about']['team']['enabled'] ) ? '1' : '0', $value ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div><div data-kiwe-team-fields <?php echo empty( $p['about']['team']['enabled'] ) ? 'hidden inert' : ''; ?>><p class="description"><?php esc_html_e( 'Link a member to an existing account or leave it unlinked until that person receives an account. No login, email, capability or WordPress role is changed or exposed.', 'dsa' ); ?></p><?php $members = $p['about']['team']['members'] ?: [ [] ]; ?><div class="kiwe-team-list" data-kiwe-team-members><?php foreach ( $members as $i=>$member ) $this->team_member_row( (int) $i, (array) $member ); ?></div><template data-kiwe-team-member-template><?php $this->team_member_row( '__INDEX__', [] ); ?></template><button type="button" class="button" data-kiwe-add-team-member><?php esc_html_e( 'Add team member', 'dsa' ); ?></button></div>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="2" hidden>
					<div class="kiwe-onboarding__intro"><span>03</span><div><h2><?php esc_html_e( 'Contact and location', 'dsa' ); ?></h2><p><?php esc_html_e( 'Public contact details can appear in headers, footers, About and Contact pages through dynamic tags.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Public phone number', 'dsa' ); ?> *</span><input required type="tel" name="context[contact][phone]" value="<?php echo esc_attr( $p['contact']['phone'] ); ?>" data-kiwe-public-phone></label><label><span><?php esc_html_e( 'Public email address', 'dsa' ); ?> *</span><input required type="email" name="context[contact][email]" value="<?php echo esc_attr( $p['contact']['email'] ); ?>"></label><label class="kiwe-check kiwe-field-span"><input type="checkbox" name="context[contact][whatsappSameAsPhone]" value="1" <?php checked( ! empty( $p['contact']['whatsappSameAsPhone'] ) ); ?> data-kiwe-whatsapp-same><span><?php esc_html_e( 'Use the public phone number for WhatsApp', 'dsa' ); ?></span></label><label data-kiwe-whatsapp-field><span><?php esc_html_e( 'Different WhatsApp number', 'dsa' ); ?></span><input type="tel" name="context[contact][whatsapp]" value="<?php echo esc_attr( $p['contact']['whatsapp'] ); ?>" data-kiwe-whatsapp></label><label><span><?php esc_html_e( 'Timezone', 'dsa' ); ?></span><input name="context[localization][timezone]" value="<?php echo esc_attr( $p['localization']['timezone'] ); ?>" data-kiwe-timezone></label></div>
					<h3><?php esc_html_e( 'Business or store address', 'dsa' ); ?></h3><div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Address line 1', 'dsa' ); ?></span><input name="context[contact][address][line1]" value="<?php echo esc_attr( $p['contact']['address']['line1'] ); ?>"></label><label><span><?php esc_html_e( 'Address line 2', 'dsa' ); ?></span><input name="context[contact][address][line2]" value="<?php echo esc_attr( $p['contact']['address']['line2'] ); ?>"></label><label><span><?php esc_html_e( 'City', 'dsa' ); ?></span><input name="context[contact][address][city]" value="<?php echo esc_attr( $p['contact']['address']['city'] ); ?>"></label><label><span><?php esc_html_e( 'State or region', 'dsa' ); ?></span><input name="context[contact][address][state]" value="<?php echo esc_attr( $p['contact']['address']['state'] ); ?>"></label><label><span><?php esc_html_e( 'Postal code', 'dsa' ); ?></span><input name="context[contact][address][postcode]" value="<?php echo esc_attr( $p['contact']['address']['postcode'] ); ?>"></label><label><span><?php esc_html_e( 'Country', 'dsa' ); ?></span><?php $this->country_select( $p['contact']['address']['country'] ); ?></label></div>
					<h3><?php esc_html_e( 'Public social profiles', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'Optional. These update Kiwe Links and become SiteGraph/Bricks design context.', 'dsa' ); ?></p><div class="kiwe-fields kiwe-fields--three"><?php foreach ( $this->social_link_labels() as $network=>$label ) : ?><label><span><?php echo esc_html( $label ); ?></span><input type="url" name="context[contact][socialLinks][<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_url( $p['contact']['socialLinks'][ $network ] ?? '' ); ?>" placeholder="https://"></label><?php endforeach; ?></div>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="3" hidden>
					<div class="kiwe-onboarding__intro"><span>04</span><div><h2><?php esc_html_e( 'Brand feeling', 'dsa' ); ?></h2><p><?php esc_html_e( 'Optional owner preferences, aligned to real SEAM color tokens. SEAM later derives readable text, borders, states, raised surfaces and dark-mode pairs; the owner is not asked to engineer them.', 'dsa' ); ?></p></div></div>
					<h3><?php esc_html_e( 'Overall mood', 'dsa' ); ?></h3><div class="kiwe-choice-grid"><?php foreach ( [ 'pastel'=>'Soft pastel', 'vibrant'=>'Bright & energetic', 'muted'=>'Calm & muted', 'natural'=>'Earthy & natural', 'dark'=>'Rich & dark', 'light'=>'Fresh & light', 'luxury'=>'Elegant & premium', 'playful'=>'Playful', 'minimal'=>'Minimal' ] as $value => $label ) : ?><label><input type="radio" name="context[brand][tone]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $p['brand']['tone'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div><button type="button" class="button-link kiwe-clear-choice" data-kiwe-clear-tone><?php esc_html_e( 'Clear mood preference', 'dsa' ); ?></button>
					<div class="kiwe-color-roles"><?php foreach ( [ 'brand'=>'Primary brand · color-brand', 'accent'=>'Secondary/accent · color-accent', 'hero'=>'Decorative hero · color-hero', 'neutral'=>'Neutral UI · color-neutral', 'surface'=>'Page surface · color-surface' ] as $role => $label ) $this->color_role( $role, $label, $p['brand']['colors'] ); ?></div>
					<label class="kiwe-full"><span><?php esc_html_e( 'Anything the design must keep, avoid or include?', 'dsa' ); ?></span><textarea name="context[brand][notes]" rows="3" placeholder="Visual likes or dislikes, must-keep details, required homepage ideas, interactions or references."><?php echo esc_textarea( $p['brand']['notes'] ); ?></textarea></label>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="4" hidden>
					<div class="kiwe-onboarding__intro"><span>05</span><div><h2><?php esc_html_e( 'Website plan', 'dsa' ); ?></h2><p><?php esc_html_e( 'Primary pages are indexable and included in WordPress XML sitemaps. Secondary utility pages are excluded and marked noindex. Kiwe does not create the pages.', 'dsa' ); ?></p></div></div>
					<?php if ( $p['contentPlan']['existingPages'] ) : ?><div class="kiwe-page-table"><div class="kiwe-page-table__head"><span><?php esc_html_e( 'Existing page', 'dsa' ); ?></span><span><?php esc_html_e( 'Search role', 'dsa' ); ?></span></div><?php foreach ( $p['contentPlan']['existingPages'] as $i => $page ) : ?><div><input type="hidden" name="context[contentPlan][existingPages][<?php echo esc_attr( (string) $i ); ?>][id]" value="<?php echo esc_attr( (string) $page['id'] ); ?>"><span><strong><?php echo esc_html( $page['name'] ); ?></strong><small><?php echo esc_html( $page['status'] ); ?></small></span><select name="context[contentPlan][existingPages][<?php echo esc_attr( (string) $i ); ?>][visibility]"><option value="primary" <?php selected( $page['visibility'], 'primary' ); ?>><?php esc_html_e( 'Primary · indexable', 'dsa' ); ?></option><option value="secondary" <?php selected( $page['visibility'], 'secondary' ); ?>><?php esc_html_e( 'Secondary · utility/noindex', 'dsa' ); ?></option></select></div><?php endforeach; ?></div><?php endif; ?>
					<h3><?php esc_html_e( 'Pages still planned', 'dsa' ); ?></h3><p class="description"><?php esc_html_e( 'Start with one page. Add another only when you need it.', 'dsa' ); ?></p><?php $planned_pages = $p['contentPlan']['plannedPages'] ?: [ [ 'name'=>'', 'visibility'=>'primary' ] ]; ?><div class="kiwe-page-plan" data-kiwe-planned-pages><?php foreach ( $planned_pages as $i => $page ) $this->planned_page_row( (int) $i, $page ); ?></div><template data-kiwe-planned-page-template><?php $this->planned_page_row( '__INDEX__', [ 'name'=>'', 'visibility'=>'primary' ] ); ?></template><button type="button" class="button" data-kiwe-add-planned-page><?php esc_html_e( 'Add another planned page', 'dsa' ); ?></button>
					<div class="kiwe-choice-stack"><label class="kiwe-check"><input type="checkbox" name="context[contentPlan][showBlogRailOnHome]" value="1" <?php checked( ! empty( $p['contentPlan']['showBlogRailOnHome'] ) ); ?>><span><?php esc_html_e( 'Show recent articles or a blog rail on the homepage', 'dsa' ); ?></span></label><label class="kiwe-check"><input type="checkbox" name="context[contentPlan][highlightBestsellers]" value="1" <?php checked( ! empty( $p['contentPlan']['highlightBestsellers'] ) ); ?>><span><?php esc_html_e( 'Highlight best-selling products somewhere appropriate', 'dsa' ); ?></span></label></div><p class="description"><?php esc_html_e( 'These are content priorities only. The designer or AI decides where and how they fit the approved design.', 'dsa' ); ?></p>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="5" hidden data-kiwe-commerce-panel>
					<div class="kiwe-onboarding__intro"><span>06</span><div><h2><?php esc_html_e( 'Store plan', 'dsa' ); ?></h2><p><?php esc_html_e( 'WooCommerce remains the authority for products, prices, tax calculation and shipping zones. This step configures safe store basics and records the owner’s plan.', 'dsa' ); ?></p></div></div>
					<label class="kiwe-switch"><input type="checkbox" name="context[commerce][enabled]" value="1" <?php checked( ! empty( $p['commerce']['enabled'] ) ); ?> data-kiwe-commerce-toggle><span><?php esc_html_e( 'This website sells products', 'dsa' ); ?></span></label>
					<div class="kiwe-fields kiwe-fields--two" data-kiwe-commerce-fields><label><span><?php esc_html_e( 'Expected number of products', 'dsa' ); ?></span><input type="number" min="0" name="context[commerce][expectedProductCount]" value="<?php echo esc_attr( (string) $p['commerce']['expectedProductCount'] ); ?>"></label><label><span><?php esc_html_e( 'Currency', 'dsa' ); ?></span><input maxlength="3" name="context[commerce][currency]" value="<?php echo esc_attr( $p['commerce']['currency'] ); ?>"></label><label><span><?php esc_html_e( 'Currency position', 'dsa' ); ?></span><select name="context[commerce][currencyPosition]"><?php foreach ( [ 'left'=>'Before price', 'right'=>'After price', 'left_space'=>'Before price with space', 'right_space'=>'After price with space' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['currencyPosition'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Price decimal places', 'dsa' ); ?></span><input type="number" min="0" max="6" name="context[commerce][priceDecimals]" value="<?php echo esc_attr( (string) $p['commerce']['priceDecimals'] ); ?>"></label><label><span><?php esc_html_e( 'Weight unit', 'dsa' ); ?></span><select name="context[commerce][weightUnit]"><?php foreach ( [ 'kg'=>'Kilograms', 'g'=>'Grams', 'lbs'=>'Pounds', 'oz'=>'Ounces' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['weightUnit'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Dimension unit', 'dsa' ); ?></span><select name="context[commerce][dimensionUnit]"><?php foreach ( [ 'm'=>'Metres', 'cm'=>'Centimetres', 'mm'=>'Millimetres', 'in'=>'Inches', 'yd'=>'Yards' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['dimensionUnit'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Lowest expected price', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][expectedPriceRange][min]" value="<?php echo esc_attr( (string) $p['commerce']['expectedPriceRange']['min'] ); ?>"></label><label><span><?php esc_html_e( 'Highest expected price', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][expectedPriceRange][max]" value="<?php echo esc_attr( (string) $p['commerce']['expectedPriceRange']['max'] ); ?>"></label><label><span><?php esc_html_e( 'Shipping model for design context', 'dsa' ); ?></span><select name="context[commerce][shippingModel]"><?php foreach ( [ ''=>'Not decided', 'free'=>'Free shipping', 'flat'=>'Flat charge', 'calculated'=>'Calculated by location', 'pickup'=>'Pickup', 'mixed'=>'A mix of methods' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['shippingModel'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Typical shipping charge for previews', 'dsa' ); ?></span><input type="number" min="0" step="0.01" name="context[commerce][typicalShippingCharge]" value="<?php echo esc_attr( (string) $p['commerce']['typicalShippingCharge'] ); ?>"></label><label><span><?php esc_html_e( 'Sell to', 'dsa' ); ?></span><select name="context[commerce][sellingLocationMode]" data-kiwe-country-mode="selling"><?php foreach ( [ 'all'=>'All countries', 'all_except'=>'All countries except selected', 'specific'=>'Only selected countries' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['sellingLocationMode'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Ship to', 'dsa' ); ?></span><select name="context[commerce][shippingLocationMode]" data-kiwe-country-mode="shipping"><?php foreach ( [ ''=>'All countries you sell to', 'all'=>'All countries', 'specific'=>'Only selected countries', 'disabled'=>'Shipping disabled' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['commerce']['shippingLocationMode'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label class="kiwe-field-span" data-kiwe-country-list="selling-specific"><span><?php esc_html_e( 'Countries you sell to', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][sellingCountries][]', $p['commerce']['sellingCountries'] ); ?></label><label class="kiwe-field-span" data-kiwe-country-list="selling-excluded"><span><?php esc_html_e( 'Countries excluded from selling', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][excludedSellingCountries][]', $p['commerce']['excludedSellingCountries'] ); ?></label><label class="kiwe-field-span" data-kiwe-country-list="shipping-specific"><span><?php esc_html_e( 'Countries you ship to', 'dsa' ); ?></span><?php $this->country_multiselect( 'context[commerce][shippingCountries][]', $p['commerce']['shippingCountries'] ); ?></label></div>
					<div class="kiwe-choice-stack"><label class="kiwe-check"><input type="checkbox" name="context[commerce][hasBundles]" value="1" <?php checked( ! empty( $p['commerce']['hasBundles'] ) ); ?>><span><?php esc_html_e( 'The store has or will have bundled/grouped products', 'dsa' ); ?></span></label><label class="kiwe-check"><input type="checkbox" name="context[commerce][taxEnabled]" value="1" <?php checked( ! empty( $p['commerce']['taxEnabled'] ) ); ?>><span><?php esc_html_e( 'Enable WooCommerce tax calculation', 'dsa' ); ?></span></label><label class="kiwe-check"><input type="checkbox" name="context[commerce][pricesIncludeTax]" value="1" <?php checked( ! empty( $p['commerce']['pricesIncludeTax'] ) ); ?>><span><?php esc_html_e( 'Entered product prices include tax', 'dsa' ); ?></span></label></div><p class="description"><?php esc_html_e( 'Kiwe does not invent tax rates or overwrite shipping zones. A developer or store manager must configure jurisdiction-specific rates and methods in WooCommerce.', 'dsa' ); ?></p>
					<div data-kiwe-regulatory-fields><h3><?php esc_html_e( 'Business and product disclosures', 'dsa' ); ?></h3><div class="kiwe-fields kiwe-fields--two"><label data-kiwe-food-field><span><?php esc_html_e( 'FSSAI licence number', 'dsa' ); ?></span><input name="context[regulatory][fssaiLicense]" value="<?php echo esc_attr( $p['regulatory']['fssaiLicense'] ); ?>"></label><label class="kiwe-check" data-kiwe-food-field><input type="checkbox" name="context[regulatory][showFssaiOnProducts]" value="1" <?php checked( ! empty( $p['regulatory']['showFssaiOnProducts'] ) ); ?>><span><?php esc_html_e( 'Show FSSAI licence on every product page', 'dsa' ); ?></span></label><label><span><?php esc_html_e( 'GST number', 'dsa' ); ?></span><input name="context[regulatory][gstNumber]" value="<?php echo esc_attr( $p['regulatory']['gstNumber'] ); ?>"></label><label class="kiwe-check"><input type="checkbox" name="context[regulatory][showGstOnProducts]" value="1" <?php checked( ! empty( $p['regulatory']['showGstOnProducts'] ) ); ?>><span><?php esc_html_e( 'Show GST number on product pages', 'dsa' ); ?></span></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Manufacturing address', 'dsa' ); ?></span><textarea name="context[regulatory][manufacturingAddress]" rows="3"><?php echo esc_textarea( $p['regulatory']['manufacturingAddress'] ); ?></textarea></label><label class="kiwe-check kiwe-field-span"><input type="checkbox" name="context[regulatory][showManufacturingAddress]" value="1" <?php checked( ! empty( $p['regulatory']['showManufacturingAddress'] ) ); ?>><span><?php esc_html_e( 'Show the manufacturing address publicly on product pages', 'dsa' ); ?></span></label></div><p class="description"><?php esc_html_e( 'Each WooCommerce product also gets a Nutrition information image field in Product data. SiteGraph supplies the product record and that image to design tools.', 'dsa' ); ?></p></div>
				</section>

				<section class="kiwe-onboarding__panel" data-kiwe-step="6" hidden>
					<div class="kiwe-onboarding__intro"><span>07</span><div><h2><?php esc_html_e( 'Ready for the designer', 'dsa' ); ?></h2><p><?php esc_html_e( 'Saving updates the native owners and publishes a safe, read-only design brief through SiteGraph. It does not create pages, products, shipping zones or a SEAM Framework profile.', 'dsa' ); ?></p></div></div>
					<div class="kiwe-review-grid"><article><h3><?php esc_html_e( 'WordPress', 'dsa' ); ?></h3><p><?php esc_html_e( 'Site title, tagline, main/inverse logos, site icon, timezone, indexing preference and native XML sitemap intent.', 'dsa' ); ?></p></article><article><h3><?php esc_html_e( 'WooCommerce', 'dsa' ); ?></h3><p><?php esc_html_e( 'Store base location, selling/shipping countries, currency display, measurement units and safe tax switches.', 'dsa' ); ?></p></article><article><h3><?php esc_html_e( 'Kiwe', 'dsa' ); ?></h3><p><?php esc_html_e( 'Public phone/email/WhatsApp, social links, owner design brief, page search roles and product-plan context.', 'dsa' ); ?></p></article><article><h3><?php esc_html_e( 'SiteGraph', 'dsa' ); ?></h3><p><?php esc_html_e( 'One bounded evidence packet combining this owner context with configured public content, media, products, custom fields and taxonomies.', 'dsa' ); ?></p></article></div>
				</section>

				<footer class="kiwe-onboarding__actions"><button type="button" class="button button-large" data-kiwe-prev><?php esc_html_e( 'Back', 'dsa' ); ?></button><span data-kiwe-step-status></span><button type="button" class="button button-primary button-large" data-kiwe-next><?php esc_html_e( 'Continue', 'dsa' ); ?></button><button type="submit" class="button button-primary button-hero" data-kiwe-save hidden><?php esc_html_e( 'Save owner context', 'dsa' ); ?></button></footer>
			</form>

			<?php if ( ! $invitation ) $this->invite_panel(); ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		check_admin_referer( 'kiwe_save_onboarding' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to save this onboarding.', 'dsa' ) );
		$invite = $this->posted_invitation();
		if ( isset( $_POST['kiwe_invite'] ) && ! $invite ) wp_die( esc_html__( 'The invitation could not be verified.', 'dsa' ) );
		$raw = isset( $_POST['context'] ) && is_array( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : [];
		$result = $this->profiles->save( $raw, get_current_user_id(), $invite['id'] ?? '' );
		if ( is_wp_error( $result ) ) {
			set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>'error', 'message'=>$result->get_error_message() ], 300 );
			$data = $result->get_error_data();
			$step = $this->step_for_error_fields( is_array( $data['fields'] ?? null ) ? $data['fields'] : [] );
			wp_safe_redirect( admin_url( 'admin.php?page=kiwe-onboarding&incomplete=1&step=' . $step ) ); exit;
		}
		if ( $invite ) $this->complete_invitation( $invite['id'] );
		update_option( 'blog_public', ! empty( $result['seo']['allowIndexing'] ) ? '1' : '0' );
		set_transient( 'kiwe_onboarding_flash_' . get_current_user_id(), [ 'type'=>'success', 'message'=>__( 'Owner context saved. SiteGraph now has the updated SEO and design evidence.', 'dsa' ) ], 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=kiwe-onboarding&saved=1' ) ); exit;
	}

	public function handle_invite(): void {
		check_admin_referer( 'kiwe_create_onboarding_invite' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to create invitations.', 'dsa' ) );
		$user_id = absint( $_POST['user_id'] ?? 0 ); $user = get_user_by( 'id', $user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
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

	private function planned_page_row( $index, array $page ): void {
		$name       = sanitize_text_field( (string) ( $page['name'] ?? '' ) );
		$visibility = 'secondary' === ( $page['visibility'] ?? '' ) ? 'secondary' : 'primary';
		$base       = 'context[contentPlan][plannedPages][' . (string) $index . ']';
		?>
		<div data-kiwe-planned-page-row>
			<input name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'About us', 'dsa' ); ?>">
			<select name="<?php echo esc_attr( $base . '[visibility]' ); ?>"><option value="primary" <?php selected( $visibility, 'primary' ); ?>><?php esc_html_e( 'Primary', 'dsa' ); ?></option><option value="secondary" <?php selected( $visibility, 'secondary' ); ?>><?php esc_html_e( 'Secondary', 'dsa' ); ?></option></select>
			<button type="button" class="button-link-delete" data-kiwe-remove-planned-page aria-label="<?php esc_attr_e( 'Remove planned page', 'dsa' ); ?>"><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
		</div>
		<?php
	}

	private function team_member_row( $index, array $member ): void {
		$base = 'context[about][team][members][' . (string) $index . ']';
		?>
		<div class="kiwe-person-card" data-kiwe-team-member data-kiwe-person>
			<input type="hidden" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( (string) ( $member['id'] ?? '' ) ); ?>">
			<button type="button" class="button-link-delete kiwe-person-card__remove" data-kiwe-remove-team-member><?php esc_html_e( 'Remove member', 'dsa' ); ?></button>
			<?php $this->user_select( $base . '[userId]', absint( $member['userId'] ?? 0 ) ); ?>
			<div class="kiwe-fields kiwe-fields--two"><label><span><?php esc_html_e( 'Public name', 'dsa' ); ?></span><input data-kiwe-person-name name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( (string) ( $member['name'] ?? '' ) ); ?>"></label><label><span><?php esc_html_e( 'Public role or title', 'dsa' ); ?></span><input data-kiwe-person-title name="<?php echo esc_attr( $base . '[title]' ); ?>" value="<?php echo esc_attr( (string) ( $member['title'] ?? '' ) ); ?>"></label><label class="kiwe-field-span"><span><?php esc_html_e( 'Public bio', 'dsa' ); ?></span><textarea data-kiwe-person-bio name="<?php echo esc_attr( $base . '[bio]' ); ?>" rows="3"><?php echo esc_textarea( (string) ( $member['bio'] ?? '' ) ); ?></textarea></label><label class="kiwe-field-span"><span><?php esc_html_e( 'LinkedIn', 'dsa' ); ?></span><input data-kiwe-person-linkedin type="url" name="<?php echo esc_attr( $base . '[linkedin]' ); ?>" value="<?php echo esc_url( (string) ( $member['linkedin'] ?? '' ) ); ?>" placeholder="https://www.linkedin.com/in/…"></label></div>
			<div class="kiwe-media-grid"><?php $this->media_field( $base . '[imageId]', absint( $member['imageId'] ?? 0 ), __( 'Member image', 'dsa' ), false, 'team-member' ); ?></div>
		</div>
		<?php
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
