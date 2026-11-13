#!/usr/bin/env bash
# Gera dist/iugucc-<versão>.zip contendo apenas a pasta modules/ do gateway, pronta para
# extrair na raiz do WHMCS (cPanel > Gerenciador de Arquivos > Extrair), e o instalador.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$(tr -d '[:space:]' < VERSION)"
OUT="dist/iugucc-${VERSION}.zip"
mkdir -p dist
rm -f "$OUT"
node - "$OUT" <<'NODE'
// zip sem dependências externas (store + deflate via zlib)
const fs = require('fs'), path = require('path'), zlib = require('zlib');
const out = process.argv[2];
const files = [];
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name)).forEach((e) => { const p = path.join(d, e.name); e.isDirectory() ? walk(p) : files.push(p); });
walk('modules');
files.push('scripts/install-on-whmcs.sh', 'docs/INSTALLATION_WHMCS.md');
const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
const chunks = [], central = []; let offset = 0;
for (const f of files) {
  const data = fs.readFileSync(f), comp = zlib.deflateRawSync(data, { level: 9 }), name = Buffer.from(f.split(path.sep).join('/'));
  const crc = crc32(data), mode = f.endsWith('.sh') ? 0o100755 : 0o100644;
  const local = Buffer.alloc(30); local.writeUInt32LE(0x04034b50, 0); local.writeUInt16LE(20, 4); local.writeUInt16LE(0x0800, 6); local.writeUInt16LE(8, 8);
  local.writeUInt16LE(0, 10); local.writeUInt16LE(0x21, 12); local.writeUInt32LE(crc, 14); local.writeUInt32LE(comp.length, 18); local.writeUInt32LE(data.length, 22); local.writeUInt16LE(name.length, 26);
  const cen = Buffer.alloc(46); cen.writeUInt32LE(0x02014b50, 0); cen.writeUInt16LE(0x0314, 4); cen.writeUInt16LE(20, 6); cen.writeUInt16LE(0x0800, 8); cen.writeUInt16LE(8, 10);
  cen.writeUInt16LE(0, 12); cen.writeUInt16LE(0x21, 14); cen.writeUInt32LE(crc, 16); cen.writeUInt32LE(comp.length, 20); cen.writeUInt32LE(data.length, 24); cen.writeUInt16LE(name.length, 28);
  cen.writeUInt32LE((mode << 16) >>> 0, 38); cen.writeUInt32LE(offset, 42);
  chunks.push(local, name, comp); central.push(cen, name); offset += 30 + name.length + comp.length;
}
const cenBuf = Buffer.concat(central), end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(files.length, 8); end.writeUInt16LE(files.length, 10); end.writeUInt32LE(cenBuf.length, 12); end.writeUInt32LE(offset, 16);
fs.writeFileSync(out, Buffer.concat([...chunks, cenBuf, end]));
console.log(`${out}: ${files.length} arquivos`);
NODE
sha256sum "$OUT" | tee "${OUT}.sha256"
