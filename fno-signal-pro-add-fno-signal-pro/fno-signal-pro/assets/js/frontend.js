/* global FNOSP_FRONT */
( function () {
	'use strict';

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null === s || undefined === s ? '' : String( s );
		return d.innerHTML;
	}

	function badgeClass( signal ) {
		if ( 'BUY' === signal ) { return 'buy'; }
		if ( 'SELL' === signal ) { return 'sell'; }
		return 'notrade';
	}

	function cell( cls, k, v ) {
		return '<div class="fnosp-w-cell ' + cls + '"><div class="k">' + esc( k ) + '</div><div class="v">' + esc( v ) + '</div></div>';
	}

	function render( data ) {
		var html = '';

		html += '<div class="fnosp-w-top">';
		html += '<span class="fnosp-w-badge ' + badgeClass( data.signal ) + '">' + esc( data.signal ) + '</span>';
		html += '<span class="fnosp-w-price">₹' + esc( data.ltp ) + ' <small>' + esc( data.trend_label ) + '</small></span>';
		html += '</div>';

		var conf = Math.max( 0, Math.min( 100, data.confidence || 0 ) );
		html += '<div class="fnosp-w-conf">Confidence: <strong>' + esc( data.confidence ) + '%</strong></div>';
		html += '<div class="fnosp-w-meter"><span style="width:' + conf + '%"></span></div>';

		if ( data.setup ) {
			html += '<div class="fnosp-w-grid">';
			html += cell( 'entry', 'Entry', '₹' + data.setup.entry_low + ' – ₹' + data.setup.entry_high );
			html += cell( 'sl', 'Stop Loss', '₹' + data.setup.stop_loss );
			html += cell( 't1', 'Target 1', '₹' + data.setup.target1 );
			html += cell( 't2', 'Target 2', '₹' + data.setup.target2 );
			html += cell( 't3', 'Target 3', '₹' + data.setup.target3 );
			html += cell( 'rr', 'Risk:Reward', data.setup.risk_reward );
			html += '</div>';
		}

		if ( data.option_strategy && data.option_strategy.primary ) {
			html += '<div class="fnosp-w-conf"><strong>Strategy:</strong> ' + esc( data.option_strategy.primary );
			if ( data.option_strategy.legs && data.option_strategy.legs[ 0 ] ) {
				html += ' · ' + esc( data.option_strategy.legs[ 0 ].strike ) + ' (' + esc( data.option_strategy.legs[ 0 ].premium_range ) + ')';
			}
			html += '</div>';
		}

		if ( data.final_verdict ) {
			html += '<div class="fnosp-w-verdict">' + esc( data.final_verdict ) + '</div>';
		}

		if ( data.layman_summary ) {
			html += '<div class="fnosp-w-layman">';
			if ( data.layman_summary.headline ) {
				html += '<div class="fnosp-w-layman-head">' + esc( data.layman_summary.headline ) + '</div>';
			}
			if ( data.layman_summary.text ) {
				html += '<div>' + esc( data.layman_summary.text ) + '</div>';
			}
			html += '</div>';
		}

		if ( data.option_plan ) {
			var op = data.option_plan;
			html += '<div class="fnosp-w-option">';
			html += '<div class="fnosp-w-option-head">Option: ' + esc( op.label ) + ' (' + esc( op.moneyness ) + ', ' + esc( op.dte ) + 'd, IV ' + esc( op.iv_used ) + '%)</div>';
			html += '<div>' + esc( op.buy_when.condition ) + '<br>Entry premium: ' + esc( op.buy_when.est_premium ) + ' (' + esc( op.premium_source || 'estimate' ) + ')</div>';
			if ( op.sell_when && op.sell_when.targets ) {
				var tg = op.sell_when.targets;
				html += '<div class="fnosp-w-conf"><strong>Sell (premium):</strong> ₹' + esc( tg[0].premium ) + ' / ₹' + esc( tg[1].premium ) + ' / ₹' + esc( tg[2].premium ) + '</div>';
			}
			if ( op.sell_when && op.sell_when.stop_loss ) {
				html += '<div><strong>Stop premium:</strong> ≈ ₹' + esc( op.sell_when.stop_loss.premium ) + '</div>';
			}
			html += '</div>';
		}

		if ( data.ai && data.ai.narrative ) {
			html += '<div class="fnosp-w-ai">' + esc( data.ai.narrative ) + '</div>';
		}

		if ( data.expert_advice ) {
			html += '<div class="fnosp-w-expert"><div class="fnosp-w-expert-head">🧑‍🏫 Expert Advice (in simple words)</div>' + esc( data.expert_advice ) + '</div>';
		}

		html += '<div class="fnosp-w-meta">Source: ' + esc( data.source ) + ( data.cached ? ' (cached)' : '' ) + ' · ' + esc( data.generated_at ) + '</div>';		if ( data.disclaimer ) {
			html += '<div class="fnosp-w-disc">' + esc( data.disclaimer ) + '</div>';
		}

		return html;
	}

	function load( widget ) {
		var body = widget.querySelector( '.fnosp-widget-body' );
		var instrument = widget.getAttribute( 'data-instrument' );
		var ai = widget.getAttribute( 'data-ai' ) || '0';

		body.innerHTML = '<p class="fnosp-w-loading">' + esc( FNOSP_FRONT.i18n.loading ) + '</p>';

		var url = FNOSP_FRONT.restUrl + '?instrument=' + encodeURIComponent( instrument ) + '&ai=' + encodeURIComponent( ai );

		var strike = widget.getAttribute( 'data-strike' );
		if ( strike && parseFloat( strike ) > 0 ) {
			var ot = widget.getAttribute( 'data-opt-type' ) || 'CE';
			var dte = widget.getAttribute( 'data-dte' ) || '7';
			url += '&strike=' + encodeURIComponent( strike ) + '&opt_type=' + encodeURIComponent( ot ) + '&dte=' + encodeURIComponent( dte );
			var prem = widget.getAttribute( 'data-premium' );
			if ( prem && parseFloat( prem ) > 0 ) {
				url += '&premium=' + encodeURIComponent( prem );
			}
		}

		fetch( url, {
			headers: { 'X-WP-Nonce': FNOSP_FRONT.nonce },
			credentials: 'same-origin'
		} )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					var msg = res.body && res.body.message ? res.body.message : FNOSP_FRONT.i18n.error;
					body.innerHTML = '<p class="fnosp-w-error">' + esc( msg ) + '</p>';
					return;
				}
				body.innerHTML = render( res.body );
			} )
			.catch( function () {
				body.innerHTML = '<p class="fnosp-w-error">' + esc( FNOSP_FRONT.i18n.error ) + '</p>';
			} );
	}

	function tvSymbol( sym ) {
		var m = { NIFTY: 'NSE:NIFTY', NIFTY50: 'NSE:NIFTY', BANKNIFTY: 'NSE:BANKNIFTY', FINNIFTY: 'NSE:CNXFINANCE', MIDCPNIFTY: 'NSE:NIFTYMIDSELECT', SENSEX: 'BSE:SENSEX' };
		sym = ( sym || '' ).toUpperCase();
		if ( m[ sym ] ) { return m[ sym ]; }
		if ( sym.indexOf( ':' ) !== -1 ) { return sym; }
		return 'NSE:' + sym;
	}

	function initChart( widget ) {
		if ( widget.getAttribute( 'data-chart' ) !== '1' || typeof TradingView === 'undefined' ) { return; }
		var holder = widget.querySelector( '.fnosp-w-chart' );
		if ( ! holder ) { return; }
		try {
			new TradingView.widget( {
				autosize: true,
				symbol: tvSymbol( widget.getAttribute( 'data-instrument' ) ),
				interval: '15',
				timezone: 'Asia/Kolkata',
				theme: ( widget.getAttribute( 'data-theme' ) === 'dark' ? 'dark' : 'light' ),
				style: '1',
				locale: 'en',
				container_id: holder.id,
				hide_side_toolbar: true,
				allow_symbol_change: true
			} );
		} catch ( e ) {}
	}

	function init( widget ) {
		initChart( widget );
		load( widget );

		var btn = widget.querySelector( '.fnosp-refresh-btn' );
		if ( btn ) {
			btn.addEventListener( 'click', function () { load( widget ); } );
		}

		var refresh = parseInt( widget.getAttribute( 'data-refresh' ), 10 );
		if ( refresh && refresh >= 5 ) {
			setInterval( function () { load( widget ); }, refresh * 1000 );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var widgets = document.querySelectorAll( '.fnosp-widget' );
		Array.prototype.forEach.call( widgets, init );
	} );
} )();
