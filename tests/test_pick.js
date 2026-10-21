/* admin.php の pick()（QRの中身から名札コードを取り出す）を取り出して検証する */
const fs = require('fs'), path = require('path');
const src = fs.readFileSync(path.join(__dirname, '../web/admin.php'), 'utf8');
const body = src.match(/function pick\(text\) \{[\s\S]*?\n    \}/)[0];
const pick = new Function('return ' + body)();

const T = [];
const eq = (l, g, w) => T.push([l, g === w, g, w]);
eq('参加者ページのURL', pick('https://example.org/stamp/?n=9KXKVT'), '9KXKVT');
eq('小文字でも',      pick('https://example.org/stamp/?n=9kxkvt'), '9KXKVT');
eq('他の引数つき',    pick('https://example.org/stamp/?x=1&n=ABFBGR&y=2'), 'ABFBGR');
eq('コード単体',      pick('9KXKVT'), '9KXKVT');
eq('前後に空白',      pick('  9KXKVT \n'), '9KXKVT');
eq('ブースQRは拾わない', pick('https://example.org/stamp/?b=UY6B'), '');
eq('管理URLも拾える', pick('https://example.org/stamp/admin.php?admin=K&n=9KXKVT'), '9KXKVT');
eq('無関係なQR',      pick('https://example.com/'), '');
eq('空',              pick(''), '');
eq('紛らわしい文字を含む長い文字列', pick('NNNNNNNN'), '');
eq('7文字は拾わない', pick('ABCDEFG'), '');

let ng = 0;
T.forEach(([l, ok, g, w]) => { if (!ok) { ng++; console.log('NG ' + l + ' got=' + JSON.stringify(g) + ' want=' + JSON.stringify(w)); } });
console.log((T.length - ng) + '/' + T.length + ' 合格' + (ng ? '' : '　✓'));
process.exit(ng ? 1 : 0);
