<?php
/**
 * Admin settings view.
 *
 * @package FnO_Signal_Pro
 * @var FnOSP_Settings $settings
 * @var array          $values
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$instruments_csv = implode( ', ', (array) $values['instruments'] );
?>
<div class="wrap fnosp-wrap">
	<h1 class="fnosp-title">
		<span class="dashicons dashicons-admin-generic"></span>
		<?php esc_html_e( 'F&O Signal Pro — Settings', 'fno-signal-pro' ); ?>
	</h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'fno-signal-pro' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'fnosp_save_settings', 'fnosp_settings_nonce' ); ?>

		<h2 class="title"><?php esc_html_e( 'Market Data Source', 'fno-signal-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Provider', 'fno-signal-pro' ); ?></th>
				<td>
					<select name="fnosp[data_provider]">
						<option value="free" <?php selected( $values['data_provider'], 'free' ); ?>><?php esc_html_e( 'Free live data (no API key needed)', 'fno-signal-pro' ); ?></option>
						<option value="demo" <?php selected( $values['data_provider'], 'demo' ); ?>><?php esc_html_e( 'Demo (synthetic data — works offline)', 'fno-signal-pro' ); ?></option>
						<option value="custom_rest" <?php selected( $values['data_provider'], 'custom_rest' ); ?>><?php esc_html_e( 'Custom REST endpoint', 'fno-signal-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Free mode pulls real price/OHLC/volume from a public source and computes all indicators on your server — no API key required. Option-chain (PCR/OI/Max Pain) is loaded best-effort. FII/DII, breadth, sector & news need a custom feed for full coverage.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Free-mode fallback', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[free_fallback_demo]" value="1" <?php checked( $values['free_fallback_demo'], 1 ); ?> /> <?php esc_html_e( 'If the free source is temporarily unreachable, fall back to demo data instead of erroring', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'NSE proxy (optional)', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="url" class="large-text" name="fnosp[nse_proxy]" value="<?php echo esc_attr( $values['nse_proxy'] ); ?>" placeholder="https://your-proxy.example.com/?url={url}" />
					<p class="description"><?php esc_html_e( 'NSE blocks many datacenter IPs, which disables live option chain & FII/DII in Free mode. If your host is blocked, route those requests through a proxy. Use {url} as a placeholder for the target URL, or end the value with "=" to append the encoded URL, or give a plain prefix. Leave blank if your host can reach NSE directly (e.g. an India-based host).', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data API URL', 'fno-signal-pro' ); ?></th>
				<td><input type="url" class="regular-text" name="fnosp[data_api_url]" value="<?php echo esc_attr( $values['data_api_url'] ); ?>" placeholder="https://your-feed.example.com/quote" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data API Key', 'fno-signal-pro' ); ?></th>
				<td><input type="password" class="regular-text" name="fnosp[data_api_key]" value="<?php echo esc_attr( $values['data_api_key'] ); ?>" autocomplete="new-password" /></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'AI Analyst (Anthropic Claude)', 'fno-signal-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable AI commentary', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[ai_enabled]" value="1" <?php checked( $values['ai_enabled'], 1 ); ?> /> <?php esc_html_e( 'Add an AI narrative on top of the numeric signal', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API Key', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="password" class="regular-text" name="fnosp[ai_api_key]" value="<?php echo esc_attr( $values['ai_api_key'] ); ?>" autocomplete="new-password" placeholder="sk-ant-..." />
					<p class="description"><?php esc_html_e( 'Your Anthropic API key. Stored in the WordPress options table.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Model', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="fnosp[ai_model]" value="<?php echo esc_attr( $values['ai_model'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Exact Anthropic model id your account supports, e.g. claude-3-5-sonnet-20241022, claude-3-opus-20240229, or a newer Opus model id.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Max tokens', 'fno-signal-pro' ); ?></th>
				<td><input type="number" min="256" max="8192" name="fnosp[ai_max_tokens]" value="<?php echo esc_attr( $values['ai_max_tokens'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API endpoint', 'fno-signal-pro' ); ?></th>
				<td><input type="url" class="regular-text" name="fnosp[ai_endpoint]" value="<?php echo esc_attr( $values['ai_endpoint'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'anthropic-version', 'fno-signal-pro' ); ?></th>
				<td><input type="text" class="regular-text" name="fnosp[ai_version]" value="<?php echo esc_attr( $values['ai_version'] ); ?>" /></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Engine & Risk', 'fno-signal-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Minimum confidence (%)', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="number" min="50" max="99" name="fnosp[confidence_min]" value="<?php echo esc_attr( $values['confidence_min'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Below this, the engine returns NO TRADE. Framework default is 75.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cache TTL (seconds)', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="number" min="0" max="3600" name="fnosp[cache_ttl]" value="<?php echo esc_attr( $values['cache_ttl'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Cached responses make repeated requests near-instant. Set 0 to disable caching.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Instruments', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="text" class="large-text" name="fnosp[instruments]" value="<?php echo esc_attr( $instruments_csv ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated list. e.g. NIFTY, BANKNIFTY, FINNIFTY, SENSEX, MIDCPNIFTY, RELIANCE', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default instrument', 'fno-signal-pro' ); ?></th>
				<td><input type="text" name="fnosp[default_instrument]" value="<?php echo esc_attr( $values['default_instrument'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Risk filters', 'fno-signal-pro' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Block BUY if RSI >', 'fno-signal-pro' ); ?> <input type="number" min="50" max="100" name="fnosp[rsi_buy_block]" value="<?php echo esc_attr( $values['rsi_buy_block'] ); ?>" /></label><br />
					<label><?php esc_html_e( 'Block SELL if RSI <', 'fno-signal-pro' ); ?> <input type="number" min="0" max="50" name="fnosp[rsi_sell_block]" value="<?php echo esc_attr( $values['rsi_sell_block'] ); ?>" /></label><br />
					<label><?php esc_html_e( 'Block if |price − VWAP| > (%)', 'fno-signal-pro' ); ?> <input type="number" step="0.1" min="0.5" max="20" name="fnosp[vwap_dev_block]" value="<?php echo esc_attr( $values['vwap_dev_block'] ); ?>" /></label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Stock Scanner (Top Picks)', 'fno-signal-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Scan universe', 'fno-signal-pro' ); ?></th>
				<td>
					<textarea name="fnosp[scan_universe]" rows="3" class="large-text"><?php echo esc_textarea( implode( ', ', (array) $values['scan_universe'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Comma-separated NSE stock tickers the "Today\'s Top Picks" scanner ranks (e.g. RELIANCE, HDFCBANK, INFY, SBIN...). Keep to ~25 for speed.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scanner min confidence (%)', 'fno-signal-pro' ); ?></th>
				<td><input type="number" min="50" max="95" name="fnosp[scan_min_confidence]" value="<?php echo esc_attr( $values['scan_min_confidence'] ); ?>" /> <span class="description"><?php esc_html_e( 'Only list stocks at or above this confidence.', 'fno-signal-pro' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Include indices in scan', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[scan_include_indices]" value="1" <?php checked( $values['scan_include_indices'], 1 ); ?> /> <?php esc_html_e( 'Also rank NIFTY/BANKNIFTY/FINNIFTY/SENSEX/MIDCPNIFTY alongside stocks', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Daily digest', 'fno-signal-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="fnosp[digest_enabled]" value="1" <?php checked( $values['digest_enabled'], 1 ); ?> /> <?php esc_html_e( 'Auto-send a Top Picks digest every day via Telegram + Email', 'fno-signal-pro' ); ?></label>
					&nbsp;&nbsp;<?php esc_html_e( 'at (IST)', 'fno-signal-pro' ); ?>
					<input type="text" name="fnosp[digest_time]" value="<?php echo esc_attr( $values['digest_time'] ); ?>" placeholder="09:00" style="width:70px;" />
					<p class="description"><?php esc_html_e( 'Requires Telegram and/or Email alerts configured above. Time is IST, 24-hour HH:MM. Relies on WP-Cron (site traffic) — use a real server cron for exact timing.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Frontend', 'fno-signal-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show disclaimer', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[show_disclaimer]" value="1" <?php checked( $values['show_disclaimer'], 1 ); ?> /> <?php esc_html_e( 'Display the risk disclaimer on the frontend widget (recommended)', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Live chart', 'fno-signal-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="fnosp[show_chart]" value="1" <?php checked( $values['show_chart'], 1 ); ?> /> <?php esc_html_e( 'Show the live TradingView chart on the dashboard', 'fno-signal-pro' ); ?></label>
					&nbsp;&nbsp;<?php esc_html_e( 'Theme:', 'fno-signal-pro' ); ?>
					<select name="fnosp[chart_theme]">
						<option value="light" <?php selected( $values['chart_theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'fno-signal-pro' ); ?></option>
						<option value="dark" <?php selected( $values['chart_theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'fno-signal-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Public REST access', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[allow_public_rest]" value="1" <?php checked( $values['allow_public_rest'], 1 ); ?> /> <?php esc_html_e( 'Allow logged-out visitors to fetch signals (needed for public-page widgets)', 'fno-signal-pro' ); ?></label></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Telegram Alerts', 'fno-signal-pro' ); ?></h2>
		<p class="description" style="max-width:760px;"><?php esc_html_e( 'Get a Telegram message when a high-confidence signal fires. You need a free bot token from @BotFather and your chat id (message your bot, then use @userinfobot or @getidsbot to find the chat id). No paid API key required.', 'fno-signal-pro' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable alerts', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[telegram_enabled]" value="1" <?php checked( $values['telegram_enabled'], 1 ); ?> /> <?php esc_html_e( 'Send Telegram alerts automatically (scanned every 5 minutes)', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bot token', 'fno-signal-pro' ); ?></th>
				<td><input type="password" class="regular-text" name="fnosp[telegram_token]" value="<?php echo esc_attr( $values['telegram_token'] ); ?>" autocomplete="new-password" placeholder="123456789:ABCdef..." /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Chat id(s)', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="fnosp[telegram_chat_id]" value="<?php echo esc_attr( $values['telegram_chat_id'] ); ?>" placeholder="123456789, -1001234567890" />
					<p class="description"><?php esc_html_e( 'One or more chat ids (comma separated). Use a negative id for a group/channel.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Alert confidence (%)', 'fno-signal-pro' ); ?></th>
				<td><input type="number" min="50" max="99" name="fnosp[alert_min_confidence]" value="<?php echo esc_attr( $values['alert_min_confidence'] ); ?>" /> <span class="description"><?php esc_html_e( 'Only alert when confidence is at least this high.', 'fno-signal-pro' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Directions', 'fno-signal-pro' ); ?></th>
				<td>
					<?php $dirs = (array) $values['alert_directions']; ?>
					<label><input type="checkbox" name="fnosp[alert_directions][]" value="BUY" <?php checked( in_array( 'BUY', $dirs, true ) ); ?> /> <?php esc_html_e( 'BUY', 'fno-signal-pro' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="checkbox" name="fnosp[alert_directions][]" value="SELL" <?php checked( in_array( 'SELL', $dirs, true ) ); ?> /> <?php esc_html_e( 'SELL', 'fno-signal-pro' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Watch instruments', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="text" class="large-text" name="fnosp[alert_instruments]" value="<?php echo esc_attr( implode( ', ', (array) $values['alert_instruments'] ) ); ?>" placeholder="<?php echo esc_attr( implode( ', ', (array) $values['instruments'] ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated. Leave blank to watch all configured instruments.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Repeat cooldown (min)', 'fno-signal-pro' ); ?></th>
				<td><input type="number" min="1" max="1440" name="fnosp[alert_cooldown]" value="<?php echo esc_attr( $values['alert_cooldown'] ); ?>" /> <span class="description"><?php esc_html_e( 'Minimum minutes before re-alerting the same direction. A direction flip always alerts.', 'fno-signal-pro' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Market hours only', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[alert_market_hours_only]" value="1" <?php checked( $values['alert_market_hours_only'], 1 ); ?> /> <?php esc_html_e( 'Only scan during NSE hours (Mon–Fri 09:15–15:30 IST)', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Include AI commentary', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[alert_use_ai]" value="1" <?php checked( $values['alert_use_ai'], 1 ); ?> /> <?php esc_html_e( 'Add AI narrative to alerts (requires AI key; slower)', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Include option plan', 'fno-signal-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="fnosp[alert_include_option]" value="1" <?php checked( $values['alert_include_option'], 1 ); ?> /> <?php esc_html_e( 'Add an ATM Call/Put buy-sell plan to each alert', 'fno-signal-pro' ); ?></label>
					&nbsp;&nbsp;<?php esc_html_e( 'Days to expiry:', 'fno-signal-pro' ); ?>
					<input type="number" min="1" max="60" name="fnosp[alert_option_dte]" value="<?php echo esc_attr( $values['alert_option_dte'] ); ?>" style="width:70px;" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test connection', 'fno-signal-pro' ); ?></th>
				<td>
					<button type="button" class="button" id="fnosp-tg-test"><?php esc_html_e( 'Send test message', 'fno-signal-pro' ); ?></button>
					<span id="fnosp-tg-test-result" style="margin-left:10px;"></span>
					<p class="description"><?php esc_html_e( 'Save your settings first, then send a test to confirm the bot can reach your chat.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Email Alerts', 'fno-signal-pro' ); ?></h2>
		<p class="description" style="max-width:760px;"><?php esc_html_e( 'Send the same high-confidence alerts by email (uses your site mailer / SMTP). Shares the confidence threshold, directions, instruments, cooldown and market-hours settings above.', 'fno-signal-pro' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable email alerts', 'fno-signal-pro' ); ?></th>
				<td><label><input type="checkbox" name="fnosp[email_enabled]" value="1" <?php checked( $values['email_enabled'], 1 ); ?> /> <?php esc_html_e( 'Email high-confidence signals', 'fno-signal-pro' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recipients', 'fno-signal-pro' ); ?></th>
				<td>
					<input type="text" class="large-text" name="fnosp[email_recipients]" value="<?php echo esc_attr( $values['email_recipients'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated email addresses. Leave blank to use the site admin email.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test email', 'fno-signal-pro' ); ?></th>
				<td>
					<button type="button" class="button" id="fnosp-email-test"><?php esc_html_e( 'Send test email', 'fno-signal-pro' ); ?></button>
					<span id="fnosp-email-test-result" style="margin-left:10px;"></span>
					<p class="description"><?php esc_html_e( 'Save your settings first. If delivery fails, configure an SMTP plugin on your site.', 'fno-signal-pro' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'fno-signal-pro' ) ); ?>
	</form>
</div>
