# Payload の生成義務 (v1)

envelope の形は [`envelope.json`](./envelope.json) が決める。schema を通っても
grouping が壊れる書き方があるので、SDK が守るべきことをここに書く。

## 例外チェーンとフレームの並び順

- `exception.values` は外側から内側の順に並べる（`cause` / `getPrevious` を辿った順）
- `stacktrace.frames` は古い呼び出し元から throw 地点の順に並べる。全プラットフォームで同じ向きにする
- 並び順が逆だと、同じ例外が別の Issue になる。ここが言語ごとにズレやすい

## timestamp

- `sent_at`、`timestamp`、breadcrumb の `timestamp` は RFC 3339 の date-time にする。timezone を必ず付ける（`2026-08-30T09:00:00+09:00` か `...Z`）
- `T` の代わりに空白を使わない。暦として存在しない日付を送らない。どちらも `422` になる
- 公開している JSON Schema は形（`pattern`）までを表す。各欄が範囲内か（月が 12 以下、日がその月に実在する、時刻と offset が 23:59 以下）は表せないので、MONICA 側の検証だけが弾く。schema を通ったことを送信可否の判断に使わない

## in_app

- `in_app` は「利用者が書いたコードか」を SDK が判定した結果。`node_modules` / `vendor` / 標準ライブラリ / フレームワークは `false`
- 判定基準は SDK の設定で決める（例: Java の `inAppPackage`、JS の bundle 内かどうか）
- `in_app` を全フレームで `false` にすると、そのエラーはフレームワークの位置で group される

## filename

- `filename` は空文字にしない
- Java / Android は宣言クラスの package と `StackTraceElement.getFileName()` を連結して組み立てる。例: `com/example/app/service/OrderService.java`
- `$` を含む内部クラスや `lambda$…` は `function` 側に残す。`filename` を書き換えて表現しない
- native method には行番号が無い。`lineno` を省く

## fingerprint

- `fingerprint` は利用者が明示的にグルーピングを指定するための順序付き文字列配列。空配列にしない
- 値はそのまま使われる。SDK 側で trim・正規化・結合をしない
- 要素に区切り文字が入っていても衝突しない。SDK 側で escape しない

## tags と contexts

- `tags` は索引される。値は文字列だけにし、`user_id` のような高カーディナリティ値を入れない
- `contexts` は索引されない。構造を持つ付帯情報はこちらに入れる
- PII の除去は利用者の責任。SDK は推測で値を落とさず、`beforeSend` で利用者に選ばせる

## 確かめ方

[`vectors/envelope/`](./vectors/envelope) の test vectors を自言語で回す。
`valid: true` は受理され、`valid: false` は拒否されなければならない。
`schema_rejects: false` の vector は、公開している JSON Schema では通るが
MONICA 側の検証では拒否される（schema は形の検査で、意味の検査までは
表現できない）。
