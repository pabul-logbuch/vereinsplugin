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

	// Nach Klick auf einen Navigationspunkt auf Mobil das Menü schließen.
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.vp-nav-item')) return;
		var nav = document.getElementById('vp-app-nav');
		if (nav && window.matchMedia('(max-width:820px)').matches) {
			nav.classList.remove('is-open');
		}
	});
})();
