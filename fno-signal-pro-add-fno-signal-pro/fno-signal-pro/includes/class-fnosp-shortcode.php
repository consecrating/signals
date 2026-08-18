<?php
/**
 * Frontend shortcode: [fno_signal]
 *
 * Attributes:
 *   instrument="NIFTY"   Symbol to analyze.
 *   ai="0"               1 to request AI commentary (if configured).
 *   refresh="0"          Auto-refresh interval in seconds (0 = off).
 *   title="..."          Optional widget heading.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Shortcode {

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct() {
		$this->settings = new FnOSP_Settings();
	}

	public function hooks() {
		add_shortcode( 'fno_signal', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style(
			'fnosp-frontend',
			FNOSP_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			FNOSP_VERSION
		);
		wp_register_script(
			'fnosp-frontend',
			FNOSP_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			FNOSP_VERSION,
			true
		);
		wp_register_script( 'fnosp-tradingview', 'https://s3.tradingview.com/tv.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_localize_script(
			'fnosp-frontend',
			'FNOSP_FRONT',
			array(
				'restUrl' => esc_url_raw( rest_url( 'fnosp/v1/signal' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'loading' => __( 'Analyzing market data...', 'fno-signal-pro' ),
					'error'   => __( 'Unable to load signal right now.', 'fno-signal-pro' ),
					'refresh' => __( 'Refresh', 'fno-signal-pro' ),
				),
			)
		);
	}

	/**
	 * Render the shortcode container. JS hydrates it from REST.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'instrument' => $this->settings->get( 'default_instrument', 'NIFTY' ),
				'ai'         => '0',
				'refresh'    => '0',
				'title'      => '',
				'strike'     => '',
				'opt_type'   => 'CE',
				'dte'        => '7',
				'premium'    => '',
				'chart'      => '0',
			),
			$atts,
			'fno_signal'
		);

		wp_enqueue_style( 'fnosp-frontend' );
		wp_enqueue_script( 'fnosp-frontend' );
		$want_chart = ( '1' === (string) $atts['chart'] || 'true' === strtolower( (string) $atts['chart'] ) );
		if ( $want_chart ) {
			wp_enqueue_script( 'fnosp-tradingview' );
		}

		$id   = 'fnosp-widget-' . wp_rand( 1000, 9999 );
		$inst = esc_attr( strtoupper( $atts['instrument'] ) );
		$ai   = ( '1' === (string) $atts['ai'] || 'true' === strtolower( (string) $atts['ai'] ) ) ? '1' : '0';
		$ref  = absint( $atts['refresh'] );
		$strike  = ( '' !== $atts['strike'] ) ? (float) $atts['strike'] : '';
		$otype   = ( 'PE' === strtoupper( (string) $atts['opt_type'] ) ) ? 'PE' : 'CE';
		$dte     = max( 1, absint( $atts['dte'] ) );
		$premium = ( '' !== $atts['premium'] ) ? (float) $atts['premium'] : '';
		$title = $atts['title'] ? esc_html( $atts['title'] ) : sprintf( 'F&O Signal · %s', $inst );

		ob_start();
		?>
		<div class="fnosp-widget"
			id="<?php echo esc_attr( $id ); ?>"
			data-instrument="<?php echo $inst; ?>"
			data-ai="<?php echo esc_attr( $ai ); ?>"
			data-refresh="<?php echo esc_attr( $ref ); ?>"
			data-strike="<?php echo esc_attr( $strike ); ?>"
			data-opt-type="<?php echo esc_attr( $otype ); ?>"
			data-dte="<?php echo esc_attr( $dte ); ?>"
			data-premium="<?php echo esc_attr( $premium ); ?>"
			data-chart="<?php echo $want_chart ? '1' : '0'; ?>"
			data-theme="<?php echo esc_attr( $this->settings->get( 'chart_theme', 'light' ) ); ?>">
			<div class="fnosp-widget-head">
				<span class="fnosp-widget-title"><?php echo esc_html( $title ); ?></span>
				<button type="button" class="fnosp-refresh-btn" aria-label="<?php esc_attr_e( 'Refresh', 'fno-signal-pro' ); ?>">&#x21bb;</button>
			</div>
			<?php if ( $want_chart ) : ?>
			<div class="fnosp-w-chart" id="<?php echo esc_attr( $id ); ?>-chart"></div>
			<?php endif; ?>
			<div class="fnosp-widget-body">
				<p class="fnosp-w-loading"><?php esc_html_e( 'Loading signal...', 'fno-signal-pro' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
