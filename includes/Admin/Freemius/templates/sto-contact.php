<?php
/**
 * STO Contact Us — replaces Freemius iframe template.
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

use SimpleThemeOptions\Admin\Freemius\StoFreemiusContact;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template view locals use `sto_` prefix (Plugin Check expects slug-derived prefix on included templates).
$sto_freemius_instance = function_exists( 'topten_sto' ) ? topten_sto() : null;
$sto_plugin_label      = is_object( $sto_freemius_instance ) && method_exists( $sto_freemius_instance, 'get_plugin_name' )
	? (string) $sto_freemius_instance->get_plugin_name()
	: __( 'Topten Simple Theme Options', 'topten-simple-theme-options' );

$sto_site_url = function_exists( 'Freemius' ) && is_object( $sto_freemius_instance )
	? (string) \Freemius::get_unfiltered_site_url()
	: (string) home_url( '/' );

$sto_contact_topics = array(
	array(
		'topic'       => 'technical_support',
		'label'       => __( 'Technical support', 'topten-simple-theme-options' ),
		'description' => __( 'Help with setup, bugs, or how something works.', 'topten-simple-theme-options' ),
		'icon'        => 'fa-light fa-life-ring',
	),
	array(
		'topic'       => 'bug',
		'label'       => __( 'Report a bug', 'topten-simple-theme-options' ),
		'description' => __( 'Something broken? Tell us how to reproduce it.', 'topten-simple-theme-options' ),
		'icon'        => 'fa-light fa-bug',
	),
	array(
		'topic'       => 'feature_request',
		'label'       => __( 'Feature request', 'topten-simple-theme-options' ),
		'description' => __( 'Suggest an improvement or new field type.', 'topten-simple-theme-options' ),
		'icon'        => 'fa-light fa-lightbulb',
	),
	array(
		'topic'       => 'billing',
		'label'       => __( 'Billing & license', 'topten-simple-theme-options' ),
		'description' => __( 'Plans, payments, refunds, or activation.', 'topten-simple-theme-options' ),
		'icon'        => 'fa-light fa-credit-card',
	),
);

$sto_default_contact_url = StoFreemiusContact::get_standalone_contact_url();
?>
<div class="wrap sto-fs-contact-wrap sto-section-content">
	<div class="sto-option-panel-wrapper sto-option-panel-wrapper--freemius-contact">
		<div class="sto-option-panel-head">
			<h1 class="sto-option-panel-title"><?php esc_html_e( 'Contact support', 'topten-simple-theme-options' ); ?></h1>
		</div>
		<div class="sto-option-panel-body sto-fs-contact-body">
			<div class="sto-fs-contact-hero">
				<span class="sto-fs-contact-hero__icon" aria-hidden="true">
					<i class="fa-light fa-messages"></i>
				</span>
				<div class="sto-fs-contact-hero__copy">
					<p class="sto-fs-contact-hero__eyebrow"><?php echo esc_html( $sto_plugin_label ); ?></p>
					<h2 class="sto-fs-contact-hero__title">
						<?php esc_html_e( 'We’re here to help', 'topten-simple-theme-options' ); ?>
					</h2>
					<p class="sto-fs-contact-hero__lead">
						<?php esc_html_e( 'Choose a topic below to open the secure support form. It runs on Freemius in a focused window so fields and validation display correctly.', 'topten-simple-theme-options' ); ?>
					</p>
				</div>
				<a
					class="sto-fs-contact-hero__cta"
					href="<?php echo esc_url( $sto_default_contact_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Open support form', 'topten-simple-theme-options' ); ?>
					<i class="fa-light fa-arrow-up-right-from-square" aria-hidden="true"></i>
				</a>
			</div>

			<ul class="sto-fs-contact-topics" role="list">
				<?php foreach ( $sto_contact_topics as $sto_topic_row ) : ?>
					<?php
					$sto_topic_slug = isset( $sto_topic_row['topic'] ) ? sanitize_key( (string) $sto_topic_row['topic'] ) : '';
					$sto_topic_url  = StoFreemiusContact::get_standalone_contact_url( $sto_topic_slug );
					?>
					<li class="sto-fs-contact-topics__item">
						<a
							class="sto-fs-contact-topic-card"
							href="<?php echo esc_url( $sto_topic_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<span class="sto-fs-contact-topic-card__icon" aria-hidden="true">
								<i class="<?php echo esc_attr( (string) ( $sto_topic_row['icon'] ?? 'fa-light fa-circle-question' ) ); ?>"></i>
							</span>
							<span class="sto-fs-contact-topic-card__text">
								<span class="sto-fs-contact-topic-card__label"><?php echo esc_html( (string) ( $sto_topic_row['label'] ?? '' ) ); ?></span>
								<span class="sto-fs-contact-topic-card__desc"><?php echo esc_html( (string) ( $sto_topic_row['description'] ?? '' ) ); ?></span>
							</span>
							<i class="fa-light fa-arrow-up-right-from-square sto-fs-contact-topic-card__arrow" aria-hidden="true"></i>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<aside class="sto-fs-contact-meta">
				<p>
					<strong><?php esc_html_e( 'Site', 'topten-simple-theme-options' ); ?>:</strong>
					<code><?php echo esc_html( $sto_site_url ); ?></code>
				</p>
				<p class="sto-fs-contact-meta__note">
					<?php esc_html_e( 'Allow pop-ups if your browser blocks the form. Replies go to the email on your Freemius account.', 'topten-simple-theme-options' ); ?>
				</p>
			</aside>
		</div>
	</div>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
