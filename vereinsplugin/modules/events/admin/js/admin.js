( function ( $ ) {
	'use strict';

	// Bild-Auswahl über den WordPress-Media-Uploader.
	$( document ).on( 'click', '.jbf-select-image', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.jbf-image-field' );
		var frame = wp.media( { title: 'Bild auswählen', multiple: false } );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$field.find( '.jbf-image-id' ).val( attachment.id );
			var previewUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
			$field.find( '.jbf-image-preview' ).html( '<img src="' + previewUrl + '" />' );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.jbf-remove-image', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.jbf-image-field' );
		$field.find( '.jbf-image-id' ).val( '' );
		$field.find( '.jbf-image-preview' ).empty();
	} );

	// Video-Auswahl (z. B. für Instagram-Reels) – Mediathek gefiltert auf Videos.
	$( document ).on( 'click', '.jbf-select-video', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.jbf-video-field' );
		var frame = wp.media( { title: 'Video auswählen', multiple: false, library: { type: 'video' } } );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$field.find( '.jbf-video-id' ).val( attachment.id );
			$field.find( '.jbf-video-preview' ).html( '<video src="' + attachment.url + '" controls style="max-width:220px;display:block;margin-bottom:6px;"></video>' );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.jbf-remove-video', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.jbf-video-field' );
		$field.find( '.jbf-video-id' ).val( '' );
		$field.find( '.jbf-video-preview' ).empty();
	} );

	// Copy-to-Clipboard für die manuellen Vorlagen (Facebook-Veranstaltung, WhatsApp).
	$( document ).on( 'click', '.jbf-copy-btn', function () {
		var $source = $( this ).prev( '.jbf-copy-source' );
		if ( ! $source.length ) {
			$source = $( this ).closest( 'div' ).find( '.jbf-copy-source' );
		}
		$source.select();
		document.execCommand( 'copy' );
		if ( window.JBF && JBF.i18n ) {
			alert( JBF.i18n.copied );
		}
	} );

	// Veröffentlichen-Button in der Sidebar (nur Vorstand).
	$( document ).on( 'click', '#jbf-publish-btn', function () {
		var $btn = $( this );
		var postId = $btn.data( 'post-id' );
		var $log = $( '#jbf-publish-log' );

		$btn.prop( 'disabled', true ).text( JBF.i18n.publishing );
		$log.empty();

		$.post( JBF.ajaxUrl, {
			action: 'jbf_publish_event',
			nonce: JBF.nonce,
			post_id: postId
		} ).done( function ( response ) {
			$btn.prop( 'disabled', false ).text( "🚀 Los geht's – jetzt senden" );

			if ( ! response.success ) {
				$log.append( '<div class="jbf-log-entry jbf-fail">Fehler: ' + ( response.data && response.data.message ? response.data.message : 'Unbekannter Fehler' ) + '</div>' );
				return;
			}

			response.data.log.forEach( function ( entry ) {
				var cls = entry.success ? 'jbf-ok' : 'jbf-fail';
				$log.append( '<div class="jbf-log-entry ' + cls + '">' + entry.channel + ': ' + entry.message + '</div>' );
			} );
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( "🚀 Los geht's – jetzt senden" );
			$log.append( '<div class="jbf-log-entry jbf-fail">Verbindungsfehler beim Senden.</div>' );
		} );
	} );

	// "Zur Freigabe einreichen"-Button (Vereinsmitglieder ohne Sende-Recht).
	$( document ).on( 'click', '#jbf-submit-review-btn', function () {
		var $btn = $( this );
		var postId = $btn.data( 'post-id' );
		var $msg = $( '#jbf-review-message' );

		$btn.prop( 'disabled', true ).text( 'Wird eingereicht …' );

		$.post( JBF.ajaxUrl, {
			action: 'jbf_submit_for_review',
			nonce: JBF.nonce,
			post_id: postId
		} ).done( function ( response ) {
			if ( response.success ) {
				$msg.html( '<div class="jbf-log-entry jbf-ok">' + response.data.message + '</div>' );
				$btn.text( 'Bereits eingereicht' );
			} else {
				$btn.prop( 'disabled', false ).text( 'Zur Freigabe einreichen' );
				$msg.html( '<div class="jbf-log-entry jbf-fail">' + ( response.data && response.data.message ? response.data.message : 'Fehler' ) + '</div>' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Zur Freigabe einreichen' );
			$msg.html( '<div class="jbf-log-entry jbf-fail">Verbindungsfehler.</div>' );
		} );
	} );

	// Kampagne einplanen (Vorstand).
	$( document ).on( 'click', '#jbf-schedule-campaign-btn', function () {
		var $btn = $( this );
		var postId = $btn.data( 'post-id' );
		var $log = $( '#jbf-campaign-schedule-log' );

		$btn.prop( 'disabled', true ).text( 'Wird eingeplant …' );

		$.post( JBF.ajaxUrl, {
			action: 'jbf_schedule_campaign',
			nonce: JBF.nonce,
			post_id: postId
		} ).done( function ( response ) {
			$btn.prop( 'disabled', false ).text( "🚀 Los geht's – Kampagne einplanen" );
			$log.empty();
			if ( ! response.success ) {
				$log.append( '<div class="jbf-log-entry jbf-fail">' + ( response.data && response.data.message ? response.data.message : 'Fehler' ) + '</div>' );
				return;
			}
			response.data.result.forEach( function ( r ) {
				var cls = r.ok ? 'jbf-ok' : 'jbf-fail';
				$log.append( '<div class="jbf-log-entry ' + cls + '">' + r.label + ': ' + r.message + '</div>' );
			} );
			$log.append( '<p class="description">Bitte Seite neu laden, um den aktuellen Status zu sehen.</p>' );
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( "🚀 Los geht's – Kampagne einplanen" );
			$log.append( '<div class="jbf-log-entry jbf-fail">Verbindungsfehler.</div>' );
		} );
	} );

	// Kampagne stoppen (Vorstand).
	$( document ).on( 'click', '#jbf-stop-campaign-btn', function () {
		var $btn = $( this );
		var postId = $btn.data( 'post-id' );
		var $log = $( '#jbf-campaign-schedule-log' );

		$btn.prop( 'disabled', true ).text( 'Wird gestoppt …' );

		$.post( JBF.ajaxUrl, {
			action: 'jbf_stop_campaign',
			nonce: JBF.nonce,
			post_id: postId
		} ).done( function ( response ) {
			$log.empty();
			if ( response.success ) {
				$log.append( '<div class="jbf-log-entry jbf-ok">' + response.data.message + '</div>' );
				$log.append( '<p class="description">Bitte Seite neu laden.</p>' );
			} else {
				$btn.prop( 'disabled', false ).text( 'Kampagne stoppen' );
				$log.append( '<div class="jbf-log-entry jbf-fail">' + ( response.data && response.data.message ? response.data.message : 'Fehler' ) + '</div>' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Kampagne stoppen' );
			$log.append( '<div class="jbf-log-entry jbf-fail">Verbindungsfehler.</div>' );
		} );
	} );

	// ─── Kampagnen-Repeater (Zeilen hinzufügen/entfernen) ─────────────────

	function jbfNextRowIndex() {
		return $( '#jbf-campaign-rows tr.jbf-campaign-row' ).length;
	}

	function jbfAddCampaignRow( label, offsetDays, time ) {
		var template = document.getElementById( 'jbf-campaign-row-template' );
		var index = jbfNextRowIndex();
		var html = template.innerHTML.replace( /__INDEX__/g, index );
		var $row = $( html );

		if ( label !== undefined ) {
			$row.find( 'input[name$="[label]"]' ).val( label );
		}
		if ( offsetDays !== undefined ) {
			$row.find( 'input[name$="[offset_days]"]' ).val( offsetDays );
		}
		if ( time !== undefined ) {
			$row.find( 'input[name$="[time]"]' ).val( time );
		}

		$( '#jbf-campaign-rows' ).append( $row );
	}

	$( document ).on( 'click', '#jbf-campaign-add-row', function () {
		jbfAddCampaignRow();
	} );

	$( document ).on( 'click', '#jbf-campaign-add-template', function () {
		jbfAddCampaignRow( 'Ankündigung', -14, '09:00' );
		jbfAddCampaignRow( 'Erinnerung', -3, '09:00' );
		jbfAddCampaignRow( 'Letzter Aufruf', -1, '17:00' );
		jbfAddCampaignRow( 'Rückblick / Dank', 2, '11:00' );
	} );

	$( document ).on( 'click', '.jbf-campaign-remove-row', function () {
		$( this ).closest( 'tr' ).remove();
	} );

	// ─── Checkliste: Sichtbarkeit je Kanal + Offen/Erledigt-Status ────────

	function jbfSelectedChannels() {
		return $( 'input[name="jbf_channels[]"]:checked' ).map( function () {
			return this.value;
		} ).get();
	}

	function jbfUpdateItemStatus( $item ) {
		var required = $item.data( 'required' ) == 1; // eslint-disable-line eqeqeq
		var $badge = $item.find( '.jbf-checklist-badge' );

		if ( ! required ) {
			$badge.text( 'optional' ).removeClass( 'jbf-badge-open jbf-badge-done' ).addClass( 'jbf-badge-optional' );
			return;
		}

		var allFilled = true;
		$item.find( '.jbf-field' ).each( function () {
			var val = $( this ).val();
			if ( ! val || ! val.toString().trim().length ) {
				allFilled = false;
			}
		} );

		if ( allFilled ) {
			$badge.text( '✓ Erledigt' ).removeClass( 'jbf-badge-open jbf-badge-optional' ).addClass( 'jbf-badge-done' );
		} else {
			$badge.text( '○ Offen' ).removeClass( 'jbf-badge-done jbf-badge-optional' ).addClass( 'jbf-badge-open' );
		}
	}

	function jbfUpdateProgressSummary() {
		var total = 0;
		var done = 0;
		$( '.jbf-checklist-item:visible[data-required="1"]' ).each( function () {
			total++;
			if ( $( this ).find( '.jbf-checklist-badge' ).hasClass( 'jbf-badge-done' ) ) {
				done++;
			}
		} );
		var $progress = $( '#jbf-checklist-progress' );
		if ( total === 0 ) {
			$progress.text( 'Wählt oben Kanäle aus, dann erscheint hier die Checkliste.' );
		} else {
			$progress.text( done + ' von ' + total + ' Pflichtfeldern erledigt' );
		}
	}

	function jbfUpdateChecklistVisibility() {
		var selected = jbfSelectedChannels();
		$( '.jbf-checklist-item' ).each( function () {
			var $item = $( this );
			var chans = ( $item.data( 'channels' ) || '' ).toString().split( ',' );
			var visible = chans.some( function ( c ) {
				return selected.indexOf( c ) !== -1;
			} );
			$item.toggle( visible );
		} );
		jbfUpdateProgressSummary();
	}

	$( document ).on( 'change', 'input[name="jbf_channels[]"]', jbfUpdateChecklistVisibility );

	$( document ).on( 'input change', '.jbf-checklist-item .jbf-field', function () {
		jbfUpdateItemStatus( $( this ).closest( '.jbf-checklist-item' ) );
		jbfUpdateProgressSummary();
	} );

	// Nach Bild-/Video-Auswahl/-Entfernen den Status der jeweiligen Checklisten-Karte neu berechnen.
	$( document ).on( 'click', '.jbf-select-image, .jbf-remove-image, .jbf-select-video, .jbf-remove-video', function () {
		var $item = $( this ).closest( '.jbf-checklist-item' );
		if ( $item.length ) {
			// Kurze Verzögerung, damit der Media-Uploader den Wert erst setzen kann.
			setTimeout( function () {
				jbfUpdateItemStatus( $item );
				jbfUpdateProgressSummary();
			}, 300 );
		}
	} );

	// ─── Zeitplan-Umschalter: Alles gleichzeitig vs. Gestaffelt ───────────

	function jbfApplyTimingMode( mode ) {
		$( '#jbf-staggered-section' ).toggle( 'staggered' === mode );
		$( '.jbf-timing-only-simultaneous' ).toggle( 'simultaneous' === mode );
		$( '.jbf-timing-only-staggered' ).toggle( 'staggered' === mode );
	}

	$( document ).on( 'change', 'input[name="jbf_timing_mode"]', function () {
		jbfApplyTimingMode( this.value );
	} );

	$( function () {
		// Initialzustand beim Laden der Seite herstellen.
		$( '.jbf-checklist-item' ).each( function () {
			jbfUpdateItemStatus( $( this ) );
		} );
		jbfUpdateChecklistVisibility();

		var initialMode = $( 'input[name="jbf_timing_mode"]:checked' ).val() || 'simultaneous';
		jbfApplyTimingMode( initialMode );
	} );

} )( jQuery );
