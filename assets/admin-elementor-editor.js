/**
 * STM Elementor editor panel
 *
 * Adds a standalone floating panel (not injected into Elementor's own
 * internal DOM, so it stays stable across Elementor versions) that lets an
 * editor pick a language and translate the page's text widgets without
 * leaving the Elementor editor. Reads/writes via the STM REST API.
 */
( function ( $ ) {
	'use strict';

	if ( typeof stmElementorEditor === 'undefined' ) {
		return;
	}

	var config = stmElementorEditor;
	var $panel, $body, $langSelect, $status;

	function buildPanel() {
		var $toggle = $(
			'<button type="button" id="stm-elementor-toggle" class="stm-elementor-toggle">' +
				config.i18n.panelTitle +
			'</button>'
		);

		$panel = $(
			'<div id="stm-elementor-panel" class="stm-elementor-panel" style="display:none;">' +
				'<div class="stm-elementor-panel-header">' +
					'<span>' + config.i18n.panelTitle + '</span>' +
					'<select id="stm-elementor-lang"></select>' +
					'<button type="button" class="stm-elementor-close">&times;</button>' +
				'</div>' +
				'<div class="stm-elementor-panel-body"></div>' +
				'<div class="stm-elementor-panel-footer">' +
					'<span class="stm-elementor-status"></span>' +
					'<button type="button" class="stm-elementor-save button button-primary">Save</button>' +
				'</div>' +
			'</div>'
		);

		$body = $panel.find( '.stm-elementor-panel-body' );
		$langSelect = $panel.find( '#stm-elementor-lang' );
		$status = $panel.find( '.stm-elementor-status' );

		config.languages.forEach( function ( lang ) {
			$langSelect.append(
				$( '<option></option>' )
					.attr( 'value', lang.code )
					.text( ( lang.flag_emoji || '' ) + ' ' + lang.name )
			);
		} );

		$( 'body' ).append( $toggle ).append( $panel );

		$toggle.on( 'click', function () {
			$panel.toggle();
			if ( $panel.is( ':visible' ) ) {
				loadLanguage( $langSelect.val() );
			}
		} );

		$panel.find( '.stm-elementor-close' ).on( 'click', function () {
			$panel.hide();
		} );

		$langSelect.on( 'change', function () {
			loadLanguage( $( this ).val() );
		} );

		$panel.find( '.stm-elementor-save' ).on( 'click', saveLanguage );
	}

	function loadLanguage( lang ) {
		$status.text( '' );
		$body.html( '<p class="stm-elementor-loading">…</p>' );

		$.ajax( {
			url: config.restUrl + encodeURIComponent( lang ),
			method: 'GET',
			headers: { 'X-WP-Nonce': config.restNonce },
		} )
			.done( function ( response ) {
				renderFields( response.fields || [], response.translations || {} );
			} )
			.fail( function () {
				$body.html( '<p class="stm-elementor-error">' + config.i18n.saveFailed + '</p>' );
			} );
	}

	function renderFields( fields, translations ) {
		$body.empty();

		if ( ! fields.length ) {
			$body.html( '<p class="stm-elementor-empty">' + config.i18n.noText + '</p>' );
			return;
		}

		fields.forEach( function ( field ) {
			var existing = ( translations[ field.id ] && translations[ field.id ][ field.key ] ) || '';

			var $row = $( '<div class="stm-elementor-field"></div>' );
			$row.append( $( '<label></label>' ).text( field.widgetType + ' — ' + field.key ) );
			$row.append( $( '<div class="stm-elementor-source"></div>' ).text( field.source ) );

			var $input = $( '<textarea></textarea>' )
				.attr( 'data-element-id', field.id )
				.attr( 'data-key', field.key )
				.val( existing );

			$row.append( $input );
			$body.append( $row );
		} );
	}

	function saveLanguage() {
		var lang = $langSelect.val();
		var translations = {};

		$body.find( 'textarea' ).each( function () {
			var $t = $( this );
			var value = $t.val();
			if ( ! value ) {
				return;
			}
			var elementId = $t.attr( 'data-element-id' );
			var key = $t.attr( 'data-key' );
			translations[ elementId ] = translations[ elementId ] || {};
			translations[ elementId ][ key ] = value;
		} );

		$status.text( '' );

		$.ajax( {
			url: config.restUrl + encodeURIComponent( lang ),
			method: 'POST',
			headers: { 'X-WP-Nonce': config.restNonce },
			contentType: 'application/json',
			data: JSON.stringify( { translations: translations } ),
		} )
			.done( function () {
				$status.text( config.i18n.saved );
			} )
			.fail( function () {
				$status.text( config.i18n.saveFailed );
			} );
	}

	$( function () {
		buildPanel();
	} );
} )( jQuery );
