/* stamp/index.html のスクリプトを Node で回して分岐を確認する簡易テスト。
   実機の代わりにはならないが、数え方と表示の切り替わりはここで確かめられる。 */
const fs = require('fs');
const path = require('path');
const HTML = path.join(__dirname, '../web/index.html');
const src = fs.readFileSync(HTML, 'utf8');
const code = src.match(/<script>([\s\S]*)<\/script>/)[1];

const store = {};
let html = '', texts = {};
function visit(query) {
  html = '';
  const g = {
    localStorage: {
      getItem: k => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
    },
    location: { search: query, pathname: '/stamp/' },
    history: { replaceState() {} },
    Image: function () { return {}; },
    document: {
      getElementById: id => ({
        set innerHTML(v) { html = v; },
        set textContent(v) { texts[id] = v; },
      }),
    },
    URLSearchParams,
  };
  new Function(...Object.keys(g), code)(...Object.values(g));
  const n = (html.match(/<b>(\d+)<\/b> \/ (\d+) 社/) || [])[1];
  const goal = (html.match(/class="goal (\w+)">([\s\S]*?)<\/div>/) || [])[2] || '';
  const msg = (html.match(/class="msg ?\w*">([\s\S]*?)<\/div>/) || [])[1] || '';
  return { n: Number(n), msg: msg.replace(/<br>/g, ' ').trim(),
           goal: goal.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() };
}

const T = [];
const eq = (label, got, want) => T.push([label, got === want, got, want]);

let r = visit('?n=9KXKVT');
eq('名札を読むと0社から', r.n, 0);
eq('案内が出る', r.msg, '準備できました。企業ブースを回りましょう');

r = visit('?b=UY6B'); eq('1社目', r.n, 1);
eq('社名が出る', r.msg, '中国電力ネットワーク株式会社 を獲得しました！');
r = visit('?b=UY6B'); eq('同じ社は増えない', r.n, 1);
eq('取得済みと出る', r.msg, '中国電力ネットワーク株式会社 は取得済みです');

r = visit('?b=DXUB'); eq('2社目', r.n, 2);
r = visit('?b=SHYM'); eq('3社目', r.n, 3);
eq('3社では未達', r.goal, 'あと1社で抽選に参加できます');

r = visit('?b=3RWU'); eq('4社目', r.n, 4);
eq('4社で1回目', r.goal.includes('抽選'), true);
eq('残りの案内', r.goal.includes('あと4社でもう1回'), true);

['U9XW','MTF8','MFZT','MB8A'].forEach(t => { r = visit('?b=' + t); });
eq('8社', r.n, 8);
eq('コンプリート', r.goal.includes('2回'), true);

r = visit(''); eq('リロードで保持', r.n, 8);
r = visit('?b=ZZZZ'); eq('不明なQR', r.msg, 'このQRは読み取れませんでした');
eq('不明でも減らない', r.n, 8);

// 別の名札に持ち替えたら進捗はリセット
r = visit('?n=ABFBGR'); eq('名札を変えるとリセット', r.n, 0);
// 不正なコード
r = visit('?n=abc'); eq('短いコードは弾く', r.msg, 'QRを読み取れませんでした。受付にお声がけください');

let ng = 0;
T.forEach(([l, ok, got, want]) => {
  if (!ok) { ng++; console.log('NG  ' + l + '  got=' + JSON.stringify(got) + ' want=' + JSON.stringify(want)); }
});
console.log((T.length - ng) + '/' + T.length + ' 合格' + (ng ? '' : '　✓'));
process.exit(ng ? 1 : 0);
