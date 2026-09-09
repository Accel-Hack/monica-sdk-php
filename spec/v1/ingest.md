# Ingest API (v1)

MONICA へ event を送る唯一の経路。

```http
POST /v1/envelope
Content-Type: application/json
Content-Encoding: gzip
```

## DSN

```
https://<api key>@<host>/<任意のパス>
```

- API キーは URL の user info に入れる。password 部分は使わない
- 送信先は DSN の origin に `/v1/envelope` を付けたもの。DSN のパス・クエリ・フラグメントは捨てる。DSN 末尾の数字を project id として送信先パスに使ってはいけない
- `https` 以外は `localhost` と `127.0.0.1` に限って許す

## 認証

| 鍵の種別 | 接頭辞 | ヘッダ | 使いどころ |
| --- | --- | --- | --- |
| secret | `msk_` | `Authorization: Bearer <key>` | サーバ SDK。配布物に埋め込まない |
| public | `mpk_` | `X-Monica-Key: <key>` | ブラウザとモバイル。DSN ごと配布される前提の書き込み専用キー |

鍵の種別とヘッダが食い違うと `401` になる。public key で読み取り API は叩けない。

送信先・ヘッダ・status ごとの挙動・リトライの定数は
[`transport.json`](./transport.json) にも同じ値がある。SDK
の契約テストはこの表を読んで定数を写すのではなく、そちらと突き合わせる。
`status` のキーは HTTP status の文字列で、`5xx` のように末尾の
`x` で範囲を表すものがある。個別のコードが無ければ範囲の方を引く。

## リクエスト

- body は envelope 1 通の JSON を gzip したもの。`Content-Encoding: gzip` を必ず付ける
- 1 リクエスト = 1 envelope。複数 envelope を連結して送らない
- 上限を超える分は SDK 側で envelope を分割する。分割の境界は item
- `sdk.name` は配布 registry での package 名（`@ah-monica/core`、`com.accelhack.monica:monica-core` のように npm / Maven / Composer で公開している名前）、`sdk.version` はその package の版。どちらも空文字にしない。取り込み状況の集計単位になる

## 上限

| 対象 | 上限 |
| --- | --- |
| envelope（gzip 後） | 1 MiB（1048576 bytes） |
| envelope（展開後） | 8 MiB（8388608 bytes） |
| envelope あたりの item 数 | 100 |
| stacktrace あたりの frame 数 | 200 |

機械可読な値は [`limits.json`](./limits.json) にある。

## レスポンス

| コード | 意味 | SDK の挙動 |
| --- | --- | --- |
| `202` | 受理（処理はこれから） | キューから除去する |
| `400` | gzip / リクエスト形式の不正 | 破棄する。リトライしない |
| `401` | キー不正・失効 | 破棄し、以後の送信を止める |
| `413` | サイズ超過 | 分割して再送する |
| `422` | envelope schema 不正 | 破棄する。`issues` の path を見て payload を直す |
| `429` | レート制限 | `Retry-After` 秒だけ待ってから再送する |
| `5xx` | サーバ側の障害 | backoff してリトライする |

エラー時の body の形は [`error.json`](./error.json)。`422` だけが
field-level の `issues` を持つ。

```json
{
  "error": {
    "code": "invalid_envelope",
    "message": "The envelope does not match the MONICA schema",
    "issues": [
      { "path": "$.items[0].exception.values[0].mechanism.type", "message": "Invalid type: Expected ..." }
    ]
  }
}
```

`code` は人が読む用で、SDK の分岐は HTTP status で行う。新しい `code`
が増えることは互換性を壊す変更ではない。

## リトライ

- リトライするのは `429` と `5xx`、そしてネットワーク障害だけ。他の 4xx は恒久的な失敗として扱う
- `Retry-After` は整数秒だけ解釈する。HTTP-date は解釈せず backoff に落とす。上限は 60 秒
- backoff は `min(1000 * 2^attempt, 30000)` ミリ秒に 50〜100% の jitter を掛ける
- リトライ回数には上限を持たせ、尽きたら envelope を捨てる。無限に溜めない
