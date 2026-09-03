'use strict';

/**
 * Zeilen-Revision – muss Byte-für-Byte dieselbe Ausgabe liefern wie
 * `vp_sync_rev()` in vereinsplugin/includes/rest-sync-api.php.
 *
 * Kanonische Form:
 *   - Feld `_rev` wird entfernt.
 *   - Schlüssel binär aufsteigend sortiert.
 *   - je Feld  "key=value"  mit  value:
 *        null      -> "\N"
 *        true/false-> "1" / "0"
 *        sonst     -> String(value)
 *   - Felder verbunden mit \x1f (US, 0x1F).
 *   - Ergebnis: crc32b als 8-stelliger Kleinbuchstaben-Hex.
 *
 * Hinweis: WordPress `$wpdb` liefert Spalten als Strings/NULL – die App
 * bekommt sie via JSON genauso (Strings bleiben Strings). Zahlen entstehen
 * nur bei explizit gecasteten Feldern (z. B. Mitglied-`id`), dort ist
 * String(5) === "5" auf beiden Seiten identisch.
 */

const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    t[n] = c >>> 0;
  }
  return t;
})();

/** Standard-CRC-32 (wie PHP hash('crc32b', …)), Rückgabe: 8-stellig hex. */
function crc32(str) {
  const bytes = Buffer.from(str, 'utf8');
  let crc = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) {
    crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
  }
  crc = (crc ^ 0xffffffff) >>> 0;
  return crc.toString(16).padStart(8, '0');
}

function canonical(row) {
  const obj = { ...row };
  delete obj._rev;
  const keys = Object.keys(obj).sort(); // ASCII-Schlüssel: Code-Unit-Sort == binär
  const parts = keys.map((k) => {
    const v = obj[k];
    let s;
    if (v === null || v === undefined) s = '\\N';
    else if (v === true) s = '1';
    else if (v === false) s = '0';
    else s = String(v);
    return k + '=' + s;
  });
  return parts.join('\x1f');
}

function rowRev(row) {
  return crc32(canonical(row));
}

module.exports = { rowRev, crc32, canonical };
