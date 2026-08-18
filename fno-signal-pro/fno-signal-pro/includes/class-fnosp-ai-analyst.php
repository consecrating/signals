<?php
/**
 * AI analyst — optional narrative enrichment via Anthropic Claude.
 *
 * The quantitative engine always produces the numeric signal. This class only
 * adds a concise institutional narrative + sanity commentary on top. The model
 * name is fully configurable so you can point it at whichever Claude model your
 * account supports (e.g. claude-3-5-sonnet, claude-3-opus, claude-opus-4, ...).
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_AI_Analyst {

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Produce an AI narrative for a computed signal result.
	 *
	 * @param array $result Signal result from the engine (without 'ai').
	 * @return array|WP_Error  { narrative, bias_check, model, latency_ms }
	 */
	public function analyze( array $result ) {
		if ( ! $this->settings->ai_ready() ) {
			return new WP_Error( 'fnosp_ai_disabled', __( 'AI analyst is disabled or API key missing.', 'fno-signal-pro' ) );
		}

		$provider = $this->settings->get( 'ai_provider', 'anthropic' );
		if ( 'anthropic' !== $provider ) {
			return new WP_Error( 'fnosp_ai_provider', __( 'Unsupported AI provider configured.', 'fno-signal-pro' ) );
		}

		$start = microtime( true );

		$payload = array(
			'model'      => $this->settings->get( 'ai_model' ),
			'max_tokens' => (int) $this->settings->get( 'ai_max_tokens', 1200 ),
			'system'     => $this->system_prompt(),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $this->user_prompt( $result ),
				),
			),
		);

		$response = wp_remote_post(
			$this->settings->get( 'ai_endpoint', 'https://api.anthropic.com/v1/messages' ),
			array(
				'timeout' => 25,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->settings->get( 'ai_api_key' ),
					'anthropic-version' => $this->settings->get( 'ai_version', '2023-06-01' ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'fnosp_ai_http', sprintf( /* translators: %s: error */ __( 'AI request failed: %s', 'fno-signal-pro' ), $msg ) );
		}

		$text = $this->extract_text( $body );
		if ( '' === $text ) {
			return new WP_Error( 'fnosp_ai_empty', __( 'AI returned an empty response.', 'fno-signal-pro' ) );
		}

		return array(
			'narrative'  => $text,
			'model'      => $this->settings->get( 'ai_model' ),
			'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * Extract concatenated text blocks from the Anthropic Messages response.
	 *
	 * @param mixed $body Decoded body.
	 * @return string
	 */
	private function extract_text( $body ) {
		if ( ! is_array( $body ) || empty( $body['content'] ) || ! is_array( $body['content'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $body['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$parts[] = $block['text'];
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	/**
	 * System prompt establishing the analyst persona & guardrails.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return implode(
			' ',
			array(
				'You are an institutional-grade Futures & Options trading analyst for Indian markets (NIFTY, BANKNIFTY, FINNIFTY, SENSEX, MIDCPNIFTY, stock & equity options).',
				'You receive a pre-computed quantitative signal with scores. Do NOT recompute or override the numeric signal, confidence, entries, targets or stop loss.',
				'Your job is to write a concise, data-driven institutional commentary that explains WHY the setup looks the way it does and what could invalidate it.',
				'Be numerical and specific. Avoid generic filler. Always emphasise risk management.',
				'End with one clear line: a risk reminder. This is educational analysis, not financial advice.',
				'Keep the whole response under 220 words.',
			)
		);
	}

	/**
	 * Build the user message from the signal result (compact JSON + ask).
	 *
	 * @param array $result Signal result.
	 * @return string
	 */
	private function user_prompt( array $result ) {
		$compact = array(
			'instrument'  => $result['instrument'],
			'ltp'         => $result['ltp'],
			'signal'      => $result['signal'],
			'confidence'  => $result['confidence'],
			'trend_label' => $result['trend_label'],
			'structure'   => $result['structure'],
			'net_bias'    => $result['net_bias'],
			'scores'      => $result['scores'],
			'analysis'    => $result['analysis'],
			'setup'       => $result['setup'],
			'probabilities' => $result['probabilities'],
			'risk_factors'  => $result['risk_factors'],
		);

		return "Here is the pre-computed signal as JSON:\n\n" .
			wp_json_encode( $compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) .
			"\n\nWrite the institutional commentary as instructed. Structure it as: (1) one-line thesis, " .
			"(2) 2-3 bullet drivers with numbers, (3) key invalidation level, (4) risk reminder.";
	}
}
