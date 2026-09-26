/**
 * GiroCode (EPC-QR) für Rückzahlungen.
 *
 * Kleiner, abhängigkeitsfreier QR-Encoder: Byte-Modus, Fehlerkorrektur M
 * (so schreibt es der EPC-Standard vor), Version 1–20. Das reicht für die
 * maximal 331 Byte eines EPC-Datensatzes (Version 13).
 *
 * Nutzung: <div class="vp-girocode-qr" data-epc="BCD\n002\n…"></div> – wird
 * beim Laden automatisch als SVG gerendert. Nachgeladene Elemente:
 * window.vpGirocode.renderAll(container).
 */
(function () {
	'use strict';

	// Pro Version (Index 1–20) für Level M: EC-Codewörter je Block, Anzahl Blöcke.
	var ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
	var NUM_BLOCKS    = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];
	var MAX_VERSION   = 20;

	function rawModules(ver) {
		var r = (16 * ver + 128) * ver + 64;
		if (ver >= 2) {
			var n = Math.floor(ver / 7) + 2;
			r -= (25 * n - 10) * n - 55;
			if (ver >= 7) { r -= 36; }
		}
		return r;
	}
	function dataCodewords(ver) {
		return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver] * NUM_BLOCKS[ver];
	}
	function alignPositions(ver) {
		if (ver === 1) { return []; }
		var n = Math.floor(ver / 7) + 2;
		var step = Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
		var res = [6];
		for (var pos = ver * 4 + 10; res.length < n; pos -= step) { res.splice(1, 0, pos); }
		return res;
	}

	/* ---- Reed-Solomon über GF(256), Polynom 0x11D ---- */
	function gfMul(x, y) {
		var z = 0;
		for (var i = 7; i >= 0; i--) {
			z = (z << 1) ^ ((z >>> 7) * 0x11D);
			z ^= ((y >>> i) & 1) * x;
		}
		return z & 0xFF;
	}
	function rsDivisor(degree) {
		var res = [];
		for (var i = 0; i < degree - 1; i++) { res.push(0); }
		res.push(1);
		var root = 1;
		for (i = 0; i < degree; i++) {
			for (var j = 0; j < res.length; j++) {
				res[j] = gfMul(res[j], root);
				if (j + 1 < res.length) { res[j] ^= res[j + 1]; }
			}
			root = gfMul(root, 0x02);
		}
		return res;
	}
	function rsRemainder(data, divisor) {
		var res = divisor.map(function () { return 0; });
		data.forEach(function (b) {
			var factor = b ^ res.shift();
			res.push(0);
			divisor.forEach(function (coef, i) { res[i] ^= gfMul(coef, factor); });
		});
		return res;
	}

	function utf8Bytes(str) {
		var bin = unescape(encodeURIComponent(str)), out = [];
		for (var i = 0; i < bin.length; i++) { out.push(bin.charCodeAt(i)); }
		return out;
	}

	/** Liefert die Modulmatrix (Array von Zeilen mit true = dunkel). */
	function encode(text) {
		var bytes = utf8Bytes(text), ver, cap;
		for (ver = 1; ver <= MAX_VERSION; ver++) {
			cap = dataCodewords(ver) * 8;
			if (4 + (ver < 10 ? 8 : 16) + bytes.length * 8 <= cap) { break; }
		}
		if (ver > MAX_VERSION) { throw new Error('Text zu lang für den QR-Code'); }

		// Bitstrom: Modus Byte, Länge, Daten, Terminator, Auffüllen.
		var bits = [];
		function put(val, len) { for (var i = len - 1; i >= 0; i--) { bits.push((val >>> i) & 1); } }
		put(4, 4);
		put(bytes.length, ver < 10 ? 8 : 16);
		bytes.forEach(function (b) { put(b, 8); });
		put(0, Math.min(4, cap - bits.length));
		put(0, (8 - bits.length % 8) % 8);
		for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) { put(pad, 8); }
		var data = [];
		for (var i = 0; i < bits.length; i += 8) {
			var v = 0;
			for (var k = 0; k < 8; k++) { v = (v << 1) | bits[i + k]; }
			data.push(v);
		}

		// In Blöcke teilen, EC anhängen, verschränken.
		var nb = NUM_BLOCKS[ver], ecl = ECC_PER_BLOCK[ver];
		var raw = Math.floor(rawModules(ver) / 8);
		var nShort = nb - raw % nb, shortLen = Math.floor(raw / nb);
		var div = rsDivisor(ecl), blocks = [], off = 0;
		for (i = 0; i < nb; i++) {
			var dat = data.slice(off, off + shortLen - ecl + (i < nShort ? 0 : 1));
			off += dat.length;
			var ecc = rsRemainder(dat, div);
			if (i < nShort) { dat.push(0); } // Platzhalter, wird beim Verschränken übersprungen
			blocks.push(dat.concat(ecc));
		}
		var cw = [];
		for (i = 0; i < blocks[0].length; i++) {
			blocks.forEach(function (bl, j) {
				if (i !== shortLen - ecl || j >= nShort) { cw.push(bl[i]); }
			});
		}

		var size = ver * 4 + 17;
		var mod = [], fn = [];
		for (i = 0; i < size; i++) { mod.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
		function setF(x, y, dark) { mod[y][x] = dark; fn[y][x] = true; }

		// Funktionsmuster.
		for (i = 0; i < size; i++) { setF(6, i, i % 2 === 0); setF(i, 6, i % 2 === 0); }
		[[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
			for (var dy = -4; dy <= 4; dy++) {
				for (var dx = -4; dx <= 4; dx++) {
					var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = c[0] + dx, yy = c[1] + dy;
					if (xx >= 0 && xx < size && yy >= 0 && yy < size) { setF(xx, yy, d !== 2 && d !== 4); }
				}
			}
		});
		var al = alignPositions(ver);
		al.forEach(function (ay, ai) {
			al.forEach(function (ax, aj) {
				if ((ai === 0 && aj === 0) || (ai === 0 && aj === al.length - 1) || (ai === al.length - 1 && aj === 0)) { return; }
				for (var dy = -2; dy <= 2; dy++) {
					for (var dx = -2; dx <= 2; dx++) { setF(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1); }
				}
			});
		});
		function drawFormat(mask) {
			var d = (0 << 3) | mask; // Level M = 00
			var r = d;
			for (var q = 0; q < 10; q++) { r = (r << 1) ^ ((r >>> 9) * 0x537); }
			var b = ((d << 10) | r) ^ 0x5412;
			function bit(n) { return ((b >>> n) & 1) !== 0; }
			for (var q2 = 0; q2 <= 5; q2++) { setF(8, q2, bit(q2)); }
			setF(8, 7, bit(6)); setF(8, 8, bit(7)); setF(7, 8, bit(8));
			for (q2 = 9; q2 < 15; q2++) { setF(14 - q2, 8, bit(q2)); }
			for (q2 = 0; q2 < 8; q2++) { setF(size - 1 - q2, 8, bit(q2)); }
			for (q2 = 8; q2 < 15; q2++) { setF(8, size - 15 + q2, bit(q2)); }
			setF(8, size - 8, true);
		}
		drawFormat(0); // reserviert die Flächen
		if (ver >= 7) {
			var r = ver;
			for (i = 0; i < 12; i++) { r = (r << 1) ^ ((r >>> 11) * 0x1F25); }
			var vb = (ver << 12) | r;
			for (i = 0; i < 18; i++) {
				var dk = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, b2 = Math.floor(i / 3);
				setF(a, b2, dk); setF(b2, a, dk);
			}
		}

		// Daten im Zickzack einsetzen.
		var bi = 0;
		for (var right = size - 1; right >= 1; right -= 2) {
			if (right === 6) { right = 5; }
			for (var vert = 0; vert < size; vert++) {
				for (var j2 = 0; j2 < 2; j2++) {
					var x = right - j2, upward = ((right + 1) & 2) === 0, y = upward ? size - 1 - vert : vert;
					if (!fn[y][x] && bi < cw.length * 8) {
						mod[y][x] = ((cw[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0;
						bi++;
					}
				}
			}
		}

		function applyMask(m) {
			for (var yy = 0; yy < size; yy++) {
				for (var xx = 0; xx < size; xx++) {
					if (fn[yy][xx]) { continue; }
					var inv;
					switch (m) {
						case 0: inv = (xx + yy) % 2 === 0; break;
						case 1: inv = yy % 2 === 0; break;
						case 2: inv = xx % 3 === 0; break;
						case 3: inv = (xx + yy) % 3 === 0; break;
						case 4: inv = (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; break;
						case 5: inv = xx * yy % 2 + xx * yy % 3 === 0; break;
						case 6: inv = (xx * yy % 2 + xx * yy % 3) % 2 === 0; break;
						default: inv = ((xx + yy) % 2 + xx * yy % 3) % 2 === 0;
					}
					if (inv) { mod[yy][xx] = !mod[yy][xx]; }
				}
			}
		}
		function penalty() {
			var p = 0, dark = 0, xx, yy;
			function runs(get) {
				for (var a = 0; a < size; a++) {
					var run = 1;
					for (var b = 1; b <= size; b++) {
						if (b < size && get(a, b) === get(a, b - 1)) { run++; continue; }
						if (run >= 5) { p += run - 2; }
						run = 1;
					}
				}
			}
			runs(function (a, b) { return mod[a][b]; });
			runs(function (a, b) { return mod[b][a]; });
			for (yy = 0; yy < size - 1; yy++) {
				for (xx = 0; xx < size - 1; xx++) {
					var c = mod[yy][xx];
					if (c === mod[yy][xx + 1] && c === mod[yy + 1][xx] && c === mod[yy + 1][xx + 1]) { p += 3; }
				}
			}
			// Finder-ähnliche Muster 1:1:3:1:1 mit 4 hellen Modulen daneben.
			var pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
			function matches(get, a, b, pat) {
				for (var q = 0; q < 11; q++) { if ((get(a, b + q) ? 1 : 0) !== pat[q]) { return false; } }
				return true;
			}
			[function (a, b) { return mod[a][b]; }, function (a, b) { return mod[b][a]; }].forEach(function (get) {
				for (var a = 0; a < size; a++) {
					for (var b = 0; b + 11 <= size; b++) {
						if (matches(get, a, b, pat1) || matches(get, a, b, pat2)) { p += 40; }
					}
				}
			});
			for (yy = 0; yy < size; yy++) { for (xx = 0; xx < size; xx++) { if (mod[yy][xx]) { dark++; } } }
			var total = size * size;
			p += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
			return p;
		}

		var best = 0, bestP = Infinity;
		for (var m = 0; m < 8; m++) {
			applyMask(m); drawFormat(m);
			var pp = penalty();
			if (pp < bestP) { best = m; bestP = pp; }
			applyMask(m); // XOR rückgängig
		}
		applyMask(best); drawFormat(best);
		return mod;
	}

	function toSvg(mod, title) {
		var size = mod.length, q = 4, d = '';
		for (var y = 0; y < size; y++) {
			for (var x = 0; x < size; x++) {
				if (mod[y][x]) { d += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z'; }
			}
		}
		var n = size + 2 * q;
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" role="img" aria-label="' + (title || 'QR-Code').replace(/"/g, '&quot;') + '">'
			+ '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' + d + '"/></svg>';
	}

	function renderAll(root) {
		(root || document).querySelectorAll('.vp-girocode-qr[data-epc]').forEach(function (el) {
			if (el.getAttribute('data-done')) { return; }
			try {
				el.innerHTML = toSvg(encode(el.getAttribute('data-epc')), el.getAttribute('data-label'));
				el.setAttribute('data-done', '1');
			} catch (e) {
				el.textContent = e.message;
			}
		});
	}

	var api = { encode: encode, toSvg: toSvg, renderAll: renderAll };
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	if (typeof window !== 'undefined') {
		window.vpGirocode = api;
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () { renderAll(); });
		} else {
			renderAll();
		}
		// Kopier-Knöpfe neben den Überweisungsdaten.
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.vp-girocode-copy') : null;
			if (!btn || !navigator.clipboard) { return; }
			navigator.clipboard.writeText(btn.getAttribute('data-copy') || '').then(function () {
				var old = btn.textContent;
				btn.textContent = '✓';
				setTimeout(function () { btn.textContent = old; }, 1200);
			});
		});
	}
})();
