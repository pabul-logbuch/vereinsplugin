/* Vereinsplugin – Mitgliederbereich-Shell: nur Navigation/Burger.
   Der PWA-/Install-Code steht bewusst inline im <head> (pwa.php), damit er
   auch ohne dieses Script vor dem Rendern greift. */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var burger = e.target.closest('.vp-app-burger');
		if (!burger) return;
		var nav = document.getElementById('vp-app-nav');
		if (!nav) return;
		var open = nav.classList.toggle('is-open');
		burger.setAttribute('aria-expanded', open ? 'true' : 'false');
	});

	// Einklappbare Navigationsgruppen: Zustand merken. Gruppen mit dem aktiven
	// Bereich rendert der Server immer offen – dort gilt der gemerkte Wert nicht.
	function foldKey(d) { return 'vp_nav_fold_' + d.getAttribute('data-fold'); }
	document.querySelectorAll('.vp-app-nav details[data-fold]').forEach(function (d) {
		if (!d.hasAttribute('data-has-active')) {
			try { if (localStorage.getItem(foldKey(d)) === '1') d.open = true; } catch (err) {}
		}
		d.addEventListener('toggle', function () {
			try { localStorage.setItem(foldKey(d), d.open ? '1' : '0'); } catch (err) {}
		});
	});

	// Nach Klick auf einen Navigationspunkt auf Mobil das Menü schließen
	// (nicht beim Auf-/Zuklappen einer Gruppe).
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.vp-nav-item') || e.target.closest('summary')) return;
		var nav = document.getElementById('vp-app-nav');
		if (nav && window.matchMedia('(max-width:820px)').matches) {
			nav.classList.remove('is-open');
		}
	});
})();
