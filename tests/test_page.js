/* web/index.html のスクリプトを Node で回して表示の分岐を確認する簡易テスト。
   進捗はサーバ管理なので、fetch（status.php）をモックして応答ごとの描画を確かめる。
   実機の代わりにはならないが、特典の段の数え方と文言の切り替わりはここで確かめられる。 */
const fs = require('fs');
const path = require('path');
const HTML = path.join(__dirname, '../web/index.html');
const src = fs.readFileSync(HTML, 'utf8');
const code = src.match(/<script>([\s\S]*)<\/script>/)[1];

/* query で開き、status.php が resp を返した（'fail' なら通信失敗）ときの画面を返す */
async function visit(query, resp) {
  const els = {};
  const el = id => els[id] || (els[id] = { innerHTML: '', textContent: '' });
  let fetched = '';
  const g = {
    location: { search: query, pathname: '/stamp/' },
    document: { getElementById: el },
    URLSearchParams,
    Date,
    fetch: url => {
      fetched = url;
      if (resp === 'fail') return Promise.reject(new Error('net'));
      return Promise.resolve({ json: () => Promise.resolve(resp) });
    },
  };
  new Function(...Object.keys(g), code)(...Object.values(g));
  await new Promise(r => setImmediate(r));   // fetch の then を流す
  const html = el('app').innerHTML;
  const count = html.match(/<b>(\d+)<\/b> \/ (\d+) ブース/) || [];
  const goals = [...html.matchAll(/class="goal (\w+)"[^>]*>([\s\S]*?)<\/div>/g)]
    .map(m => [m[1], m[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim()]);
  return {
    n: count[1] === undefined ? null : Number(count[1]),
    all: count[2] === undefined ? null : Number(count[2]),
    msg: (html.match(/class="msg ?\w*">([\s\S]*?)<\/div>/) || ['', ''])[1]
      .replace(/<br>/g, ' ').trim(),
    goals,
    done: (html.match(/<li class="done"/g) || []).length,
    needtxt: el('needtxt').textContent,
    mycode: el('mycode').textContent,
    evttl: el('evttl').textContent,
    fetched,
  };
}

/* status.php の正常応答を組み立てる */
function st(got, needs, draw) {
  return { ok: true, event: 'テスト大会', date: '2026-10-17', code: 'AKSDWC',
           got, all: 9, needs, draw,
           booths: Object.fromEntries('123456789'.split('').map(i => ['T' + i, 'ブース' + i])) };
}
const g = n => '123456789'.split('').slice(0, n).map(i => 'T' + i);

const T = [];
const eq = (label, got, want) => T.push([label, got === want, got, want]);

(async () => {
  // 名札以外・形式不正
  let r = await visit('?b=UY6B', null);
  eq('ブースQRを読んだら案内', r.msg, 'こちらはブースの方が読み取るQRです');
  eq('ブースQRでは照会しない', r.fetched, '');
  r = await visit('', null);
  eq('コードなしは名札の案内', r.msg, '名札のQRを読み取ってください');
  r = await visit('?n=abc', null);
  eq('短いコードは弾く', r.msg, '名札のQRを読み取ってください');
  r = await visit('?n=NNNNNN', null);
  eq('検査はサーバ任せ（形式が合えば照会する）', r.fetched.startsWith('status.php?n=NNNNNN'), true);
  r = await visit('?n=akSdwc', st([], [4, 8], 0));
  eq('小文字は大文字に直して照会', r.fetched.startsWith('status.php?n=AKSDWC'), true);

  // サーバの否定応答
  r = await visit('?n=AKSDWC', { ok: false, unknown: true, event: 'テスト大会', date: '' });
  eq('無効な名札', r.msg, 'この名札は無効です');
  eq('イベント名は出る', r.evttl, 'テスト大会');
  r = await visit('?n=AKSDWC', { ok: false, unregistered: true });
  eq('未登録は受付へ案内', r.msg.includes('参加登録'), true);
  r = await visit('?n=AKSDWC', 'fail');
  eq('通信失敗', r.msg, '通信できませんでした');

  // 進捗の表示（needs = [4, 8]）
  r = await visit('?n=AKSDWC', st(g(0), [4, 8], 0));
  eq('0ブースから', r.n, 0);
  eq('特典1までの残り', r.goals[0][1], 'あと4ブースで特典1です');
  eq('回り方に段の説明', r.needtxt, '4ブースで特典1、8ブースで特典2');
  eq('名札コードの表示', r.mycode, '名札コード AKSDWC');
  r = await visit('?n=AKSDWC', st(g(3), [4, 8], 0));
  eq('3ブースでは未達', r.goals[0][1], 'あと1ブースで特典1です');
  eq('済みブースに印', r.done, 3);
  r = await visit('?n=AKSDWC', st(g(4), [4, 8], 0));
  eq('4ブースで特典1', r.goals[0][1].includes('特典1まで達成'), true);
  eq('次の段への残り', r.goals[0][1].includes('あと4ブースで特典2'), true);
  r = await visit('?n=AKSDWC', st(g(8), [4, 8], 0));
  eq('8ブースで全段', r.goals[0][0], 'on2');
  eq('全段達成の文言', r.goals[0][1].includes('特典2まで達成'), true);

  // 受け取り済みの表示
  r = await visit('?n=AKSDWC', st(g(4), [4, 8], 1));
  eq('特典1受け取り済み', r.goals[1][1], '特典1まで受け取り済みです');
  r = await visit('?n=AKSDWC', st(g(8), [4, 8], 2));
  eq('全部受け取り済み', r.goals[1][1], '特典はすべて受け取り済みです');

  // 段が1つだけの設定
  r = await visit('?n=AKSDWC', st(g(4), [4], 0));
  eq('1段なら「特典を達成」', r.goals[0][1].includes('特典を達成'), true);

  let ng = 0;
  T.forEach(([l, ok, got, want]) => {
    if (!ok) { ng++; console.log('NG  ' + l + '  got=' + JSON.stringify(got) + ' want=' + JSON.stringify(want)); }
  });
  console.log((T.length - ng) + '/' + T.length + ' 合格' + (ng ? '' : '　✓'));
  process.exit(ng ? 1 : 0);
})();
