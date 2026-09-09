# MONICA public contract (v1)

MONICA へ event を送る SDK が守る契約。**これは生成物**で、正本は MONICA
本体にある。SDK repository はこのバンドルを vendoring したコピーを持ち、CI は
そのコピーに対して契約テストを回す。

| ファイル | 中身 |
| --- | --- |
| [`index.json`](./index.json) | 索引。全ファイルのパスとダイジェスト、バンドル全体の `revision` |
| [`envelope.json`](./envelope.json) | envelope と error item の JSON Schema（draft 2020-12） |
| [`error.json`](./error.json) | Ingest がエラー時に返す body の JSON Schema。`422` の `issues` を含む |
| [`limits.json`](./limits.json) | envelope の上限値 |
| [`transport.json`](./transport.json) | HTTP 契約の機械可読な形。ヘッダ、status ごとの挙動、リトライの定数 |
| [`ingest.md`](./ingest.md) | Ingest API の叩き方 |
| [`payload.md`](./payload.md) | SDK が負う payload 生成義務 |
| [`vectors/envelope/`](./vectors/envelope) | envelope の test vectors。受理されるものと拒否されるもの |

配信元は `https://spec.monica.accelhack.net/v1/`。この `v1` は **Ingest API の版**
（`POST /v1/envelope`）で、バンドル自身の版ではない。v1 API
が受け付ける範囲が広がれば、このパスの中身も変わる。

取り込みは [`index.json`](./index.json) から始める。他の全ファイルのパスと
ダイジェストが並んでいるので、ファイルの列挙、取得したものの整合性検査、
上流にファイルが増えたことの検知はすべてそこで済む。ディレクトリの一覧は
配信していない。

`index.json` の `revision` はバンドル全体の指紋で、中身が 1 byte でも
違えば別の値になる。vendoring した側が「どの契約で作ったか」を名乗るのに
使う。版番号ではないので、新旧や大小は読めない。更新の確認は `index.json`
を取り直して `revision` を比べるか、`ETag` の条件付き GET で行う。

再計算できるように定義を固定する。`files` は path の byte 順に並び（保証）、
`revision` は各要素を「ダイジェスト、空白 2 つ、path」の 1 行にして改行で
繋いだ文字列（末尾に改行なし）を、各ファイルと同じハッシュ関数に通したもの。`base` はこのバンドルを生成した配信元
で、取得先の設定ではない。取得した URL と食い違っていれば複製を読んでいる
ということで、その場合も信じるのは自分の設定した取得先と各ファイルの
ダイジェストの方。

互換性を壊す変更は新しい Ingest エンドポイントの形で出るので、そのときは
別のパス（`v2/`）が隣に生えて両方が現役になる。

grouping と scrubbing のアルゴリズムはこのバンドルに入らない。SDK
はどちらも実行しないため。
