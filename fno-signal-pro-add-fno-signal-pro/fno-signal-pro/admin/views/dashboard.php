<?php
/**
 * Admin dashboard — simplified, auto-refreshing, no manual inputs needed.
 *
 * @package FnO_Signal_Pro
 * @var FnOSP_Settings $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$instruments = (array) $settings->get( 'instruments', array() );
$default     = $settings->get( 'default_instrument', 'NIFTY' );
$ai_ready    = $settings->ai_ready();
$index_set   = array( 'NIFTY', 'NIFTY50', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY' );
$idx_list    = array();
foreach ( $instruments as $sym ) {
	if ( in_array( strtoupper( $sym ), $index_set, true ) ) {
		$idx_list[] = $sym;
	}
}

// Get today's top stocks from the scan universe for the dropdown.
$scan_universe = (array) $settings->get( 'scan_universe', array() );
$top_stocks    = array_slice( $scan_universe, 0, 10 ); // Show top 10 from universe in dropdown.
?>
<div class="wrap fnosp-wrap">
	<h1 class="fnosp-title">
		<span class="dashicons dashicons-chart-line"></span>
		<?php esc_html_e( 'F&O Signal Pro', 'fno-signal-pro' ); ?>
		<span class="fnosp-live-dot" id="fnosp-live-dot" title="Auto-refreshing every 5s"></span>
	</h1>

	<div class="fnosp-controls">
		<label for="fnosp-instrument"><?php esc_html_e( 'Index / Stock', 'fno-signal-pro' ); ?></label>
		<select id="fnosp-instrument">
			<?php if ( ! empty( $idx_list ) ) : ?>
				<optgroup label="<?php esc_attr_e( 'Indices', 'fno-signal-pro' ); ?>">
					<?php foreach ( $idx_list as $sym ) : ?>
						<option value="<?php echo esc_attr( $sym ); ?>" <?php selected( $sym, $default ); ?>><?php echo esc_html( $sym ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
			<?php if ( ! empty( $top_stocks ) ) : ?>
				<optgroup label="<?php esc_attr_e( 'Top Stocks', 'fno-signal-pro' ); ?>">
					<?php foreach ( $top_stocks as $sym ) : ?>
						<option value="<?php echo esc_attr( $sym ); ?>"><?php echo esc_html( $sym ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
		</select>

		<span class="fnosp-or"><?php esc_html_e( 'or search:', 'fno-signal-pro' ); ?></span>
		<input type="text" id="fnosp-search" placeholder="<?php esc_attr_e( 'Type any NSE ticker (e.g. TATASTEEL)', 'fno-signal-pro' ); ?>" style="width:220px;" />
		<button class="button button-small" id="fnosp-search-go"><?php esc_html_e( 'Go', 'fno-signal-pro' ); ?></button>

		<span class="fnosp-refresh-info" id="fnosp-refresh-info"><?php esc_html_e( 'Auto-refresh: 5s', 'fno-signal-pro' ); ?></span>
	</div>

	<!-- Signal output (auto-populated) -->
	<div id="fnosp-result" class="fnosp-result" aria-live="polite">
		<p class="fnosp-loading"><?php esc_html_e( 'Loading signal...', 'fno-signal-pro' ); ?></p>
	</div>

	<!-- Today's Top Picks (auto-loaded) -->
	<div class="fnosp-card" style="margin-top:16px;">
		<div class="fnosp-card-head"><strong><?php esc_html_e( "Today's Top Picks — Buy & Sell", 'fno-signal-pro' ); ?></strong></div>
		<div id="fnosp-scan-result">
			<p class="fnosp-loading"><?php esc_html_e( 'Scanning stocks...', 'fno-signal-pro' ); ?></p>
		</div>
	</div>

	<div class="fnosp-help card" style="margin-top:16px;">
		<h2><?php esc_html_e( 'Shortcode', 'fno-signal-pro' ); ?></h2>
		<code>[fno_signal instrument="BANKNIFTY" chart="1"]</code>
		<p class="description"><?php esc_html_e( 'Embed on any page. The strike, targets and stop-loss are always automatic.', 'fno-signal-pro' ); ?></p>
	</div>
</div>
