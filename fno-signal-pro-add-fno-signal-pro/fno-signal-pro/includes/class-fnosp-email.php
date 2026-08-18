<?php
/**
 * Email notifier — sends signal alerts via wp_mail() as an HTML email.
 * A fallback / complement to Telegram. No external service needed beyond the
 * site's configured mailer.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Email {

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether email alerts are enabled (recipients fall back to admin email).
	 *
	 * @return bool
	 */
	public function ready() {
		return (bool) $this->settings->get( 'email_enabled' ) && ! empty( $this->recipients() );
	}

	/**
	 * Resolve recipient list (configured list, else site admin email).
	 *
	 * @return string[]
	 */
	private function recipients() {
		$raw  = (string) $this->settings->get( 'email_recipients' );
		$list = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out  = array();
		foreach ( (array) $list as $addr ) {
			$addr = sanitize_email( trim( $addr ) );
			if ( $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}
		if ( empty( $out ) ) {
			$admin = get_option( 'admin_email' );
			if ( $admin && is_email( $admin ) ) {
				$out[] = $admin;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Send an HTML email to all recipients.
	 *
	 * @param string $subject Subject.
	 * @param string $html    HTML body.
	 * @return array|WP_Error
	 */
	public function send( $subject, $html ) {
		$to = $this->recipients();
		if ( empty( $to ) ) {
			return new WP_Error( 'fnosp_email_to', __( 'No email recipients configured.', 'fno-signal-pro' ) );
		}

		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$wrap_open = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e5ea;border-radius:10px;overflow:hidden">';
		$wrap_end  = '</div>';
		$body      = $wrap_open . $html . $wrap_end;

		$ok = wp_mail( $to, $subject, $body, $headers );
		if ( ! $ok ) {
			return new WP_Error( 'fnosp_email_fail', __( 'wp_mail() returned false. Check your site mailer/SMTP configuration.', 'fno-signal-pro' ) );
		}
		return array(
			'sent'       => count( $to ),
			'recipients' => $to,
		);
	}

	/**
	 * Send a formatted signal email.
	 *
	 * @param array $result Engine result.
	 * @return array|WP_Error
	 */
	public function send_signal_alert( array $result ) {
		$subject = sprintf( '[F&O Signal] %s %s · %d%%', $result['signal'], $result['instrument'], (int) $result['confidence'] );
		return $this->send( $subject, $this->format_signal( $result ) );
	}

	/**
	 * Build the HTML email body for a signal.
	 *
	 * @param array $r Result.
	 * @return string
	 */
	public function format_signal( array $r ) {
		$color = 'BUY' === $r['signal'] ? '#14794a' : ( 'SELL' === $r['signal'] ? '#b32424' : '#555' );
		$e     = function ( $v ) {
			return esc_html( (string) $v );
		};

		$head = '<div style="background:' . $color . ';color:#fff;padding:16px 20px">'
			. '<div style="font-size:20px;font-weight:700">' . $e( $r['signal'] ) . ' ' . $e( $r['instrument'] ) . '</div>'
			. '<div style="opacity:.9">' . $e( $r['trend_label'] ) . ' · Confidence ' . $e( $r['confidence'] ) . '%</div>'
			. '</div>';

		$rows = array();
		$rows[] = $this->row( 'LTP', '₹' . $e( number_format_i18n( (float) $r['ltp'], 2 ) ) );

		if ( ! empty( $r['setup'] ) ) {
			$s = $r['setup'];
			$rows[] = $this->row( 'Entry', '₹' . $e( $s['entry_low'] ) . ' – ₹' . $e( $s['entry_high'] ) );
			$rows[] = $this->row( 'Stop Loss', '₹' . $e( $s['stop_loss'] ) );
			$rows[] = $this->row( 'Targets', '₹' . $e( $s['target1'] ) . ' / ₹' . $e( $s['target2'] ) . ' / ₹' . $e( $s['target3'] ) );
			$rows[] = $this->row( 'Risk:Reward', $e( $s['risk_reward'] ) . ' · ' . $e( $s['holding'] ) );
		}
		if ( ! empty( $r['option_strategy']['primary'] ) ) {
			$leg    = $r['option_strategy']['legs'][0] ?? null;
			$detail = $leg ? ( ' · ' . $leg['strike'] . ' (' . $leg['premium_range'] . ')' ) : '';
			$rows[] = $this->row( 'Strategy', $e( $r['option_strategy']['primary'] . $detail ) );
		}

		$table = '<table style="width:100%;border-collapse:collapse">' . implode( '', $rows ) . '</table>';

		$verdict = '';
		if ( ! empty( $r['final_verdict'] ) ) {
			$verdict = '<div style="padding:0 20px 12px;font-style:italic;color:#333">' . $e( $r['final_verdict'] ) . '</div>';
		}

		$layman = '';
		if ( ! empty( $r['layman_summary']['text'] ) ) {
			$head_l = ! empty( $r['layman_summary']['headline'] ) ? '<div style="font-weight:700;color:#14794a;margin-bottom:4px">' . $e( $r['layman_summary']['headline'] ) . '</div>' : '';
			$layman = '<div style="margin:0 20px 12px;padding:10px 12px;background:#eefaf1;border-left:4px solid #14794a;border-radius:6px;font-size:13px;color:#234">'
				. '<div style="font-size:11px;text-transform:uppercase;color:#14794a;margin-bottom:4px">In simple words</div>'
				. $head_l
				. $e( $r['layman_summary']['text'] )
				. '</div>';
		}

		$option = '';
		if ( ! empty( $r['option_plan'] ) ) {
			$op    = $r['option_plan'];
			$rows2 = '';
			foreach ( $op['sell_when']['targets'] as $tg ) {
				$rows2 .= '<tr><td style="padding:3px 8px 3px 0;color:#666">Sell @ underlying ' . $e( $tg['spot'] ) . '</td><td style="padding:3px 0;font-weight:600">≈ ₹' . $e( $tg['premium'] ) . '</td></tr>';
			}
			$option = '<div style="margin:0 20px 12px;padding:10px 12px;background:#f3f6fc;border-left:4px solid #2563eb;border-radius:6px;font-size:13px;color:#234">'
				. '<div style="font-weight:700;color:#1d3a73;margin-bottom:4px">Option plan — ' . $e( $op['label'] ) . ' (' . $e( $op['moneyness'] ) . ', ' . (int) $op['dte'] . 'd, IV ' . $e( $op['iv_used'] ) . '%)</div>'
				. '<div>' . $e( $op['buy_when']['condition'] ) . ' &middot; entry premium ' . $e( $op['buy_when']['est_premium'] ) . '</div>'
				. '<table style="width:100%;border-collapse:collapse;margin-top:4px">' . $rows2 . '</table>'
				. '<div style="margin-top:4px">Stop premium ≈ ₹' . $e( $op['sell_when']['stop_loss']['premium'] ) . '</div>'
				. '</div>';
		}

		$notes = '';
		if ( ! empty( $r['data_notes'] ) ) {			$items = '';
			foreach ( (array) $r['data_notes'] as $n ) {
				$items .= '<li>' . $e( $n ) . '</li>';
			}
			$notes = '<div style="padding:0 20px 8px"><div style="font-size:11px;text-transform:uppercase;color:#888;margin:8px 0 4px">Data coverage</div><ul style="margin:0;padding-left:18px;font-size:12px;color:#555">' . $items . '</ul></div>';
		}

		$foot = '<div style="padding:12px 20px;background:#f7f8fa;color:#888;font-size:11px">'
			. 'Educational analysis, not investment advice. F&amp;O trading involves substantial risk of loss.<br>'
			. esc_html( gmdate( 'Y-m-d H:i', time() ) ) . ' UTC'
			. '</div>';

		return $head . '<div style="padding:8px 20px">' . $table . '</div>' . $verdict . $layman . $option . $notes . $foot;
	}

	private function row( $k, $v ) {
		return '<tr>'
			. '<td style="padding:6px 0;color:#888;font-size:13px;width:120px">' . esc_html( $k ) . '</td>'
			. '<td style="padding:6px 0;font-size:14px;font-weight:600;color:#1d2230">' . $v . '</td>'
			. '</tr>';
	}

	/**
	 * Format a daily Top Picks digest as HTML email body.
	 *
	 * @param array $res Scanner result.
	 * @return string
	 */
	public function format_digest( array $res ) {
		$e = function ( $v ) {
			return esc_html( (string) $v );
		};
		$m   = $res['macro'];
		$head = '<div style="background:#11203f;color:#fff;padding:16px 20px">'
			. '<div style="font-size:18px;font-weight:700">Daily Top Picks</div>'
			. '<div style="opacity:.85;font-size:12px">VIX ' . $e( $m['vix'] ) . ' · FII ' . $e( $m['fii_net'] ) . ' · DII ' . $e( $m['dii_net'] ) . ' · ' . $e( gmdate( 'Y-m-d' ) ) . '</div>'
			. '</div>';

		$tbl = function ( $list, $color, $title ) use ( $e ) {
			$rows = '';
			foreach ( array_slice( $list, 0, 8 ) as $x ) {
				$lvl = $x['setup'] ? ( 'Entry ₹' . $x['setup']['entry_low'] . '–₹' . $x['setup']['entry_high'] . ' · SL ₹' . $x['setup']['stop_loss'] . ' · T ₹' . $x['setup']['target1'] ) : '';
				$rows .= '<tr><td style="padding:5px 8px 5px 0;font-weight:700">' . $e( $x['instrument'] ) . '</td>'
					. '<td style="padding:5px 8px;color:' . $color . ';font-weight:700">' . $e( $x['confidence'] ) . '%</td>'
					. '<td style="padding:5px 0;font-size:12px;color:#555">' . $e( $x['trend'] ) . ' · ' . $e( $lvl ) . '</td></tr>';
			}
			if ( '' === $rows ) {
				$rows = '<tr><td style="padding:6px 0;color:#888">No high-confidence ' . $e( $title ) . ' today.</td></tr>';
			}
			return '<div style="padding:6px 20px"><div style="font-weight:700;color:' . $color . ';margin:8px 0 2px">' . $e( $title ) . '</div>'
				. '<table style="width:100%;border-collapse:collapse">' . $rows . '</table></div>';
		};

		$foot = '<div style="padding:12px 20px;background:#f7f8fa;color:#888;font-size:11px">Educational analysis, not investment advice. F&amp;O involves substantial risk.</div>';
		return $head . $tbl( $res['buy'], '#14794a', 'BUY' ) . $tbl( $res['sell'], '#b32424', 'SELL' ) . $foot;
	}

	/**
	 * Send a test email.
	 *
	 * @return array|WP_Error
	 */
	public function test() {
		$html = '<div style="background:#1d3a73;color:#fff;padding:16px 20px;font-size:18px;font-weight:700">F&amp;O Signal Pro</div>'
			. '<div style="padding:16px 20px;color:#333">Email alerts are connected and working. You will receive high-confidence signals at this address.</div>';
		return $this->send( __( 'F&O Signal Pro — test email', 'fno-signal-pro' ), $html );
	}
}
