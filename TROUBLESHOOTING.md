# トラブルシューティング

`ah-monica/monica`（PHP SDK）が出す警告の読み方と、送信が失敗したときの挙動です。
導入と基本的な使い方は [README.md](README.md) を参照してください。

## 警告の見方

出力先は既定で `error_log()` です。`bin/monica`（`monica test` / `monica spool:flush`）
は STDERR に出します。`on_diagnostic` option で差し替えられます。

```php
'on_diagnostic' => static function (string $message, \Monica\Transport\Response $response): void {
    // $message は下のいずれかの 1 行
},
```

`false` か `null` を渡すと警告を出しません。callable でも `false` / `null` でもない値は
無視して既定の出力先を使います（初期化は失敗しません）。

警告はいずれも 1 行で、MONICA が返した内容だけを含みます。envelope の中身や API キーは
出しません。

| 出る条件 | 1 行 |
| --- | --- |
| ingest が `422` を返した | `monica: ingest rejected the envelope with 422 (<code>): N issue(s); <path>: <message>` |
| ingest が `401` を返した | `monica: ingest rejected the envelope with 401 (<code>); no further envelopes will be sent` |
| 1 件で上限を超える item を捨てた | `monica: dropped N item(s) that cannot fit one envelope (<bytes> gzip bytes, limit 1048576, measured by the SDK); the event(s) are lost` |
| spool の envelope を諦めた | `monica: giving up on a spooled envelope after N attempt(s); M event(s) are lost` |

- `<code>` は拒否 body の `error.code` です。読めなかった場合は `unknown` になります
- `422` は `issues` の数だけ `; <path>: <message>` が続きます
- item を捨てた警告の末尾は、SDK が自分で測って超えていた場合が
  `measured by the SDK`、MONICA が `413` を返した場合が `ingest answered 413` です
- 諦めた警告の `M` は envelope の item 数で、読めなかった場合は
  `an unknown number of` です

警告が出るのは `422` と `401` だけです。`400` などその他の 4xx、`429`、`5xx`、
ネットワーク障害では出ません。`401` で送信が止まったあとに捨てられた envelope に
ついても出しません（同じ 1 行が埋まるため）。

## ingest が envelope を拒否したとき

| status | SDK の扱い | spool での挙動 |
| --- | --- | --- |
| 2xx | 受理 | ファイルを削除 |
| `400` / `422` / 未知の status | 恒久的な失敗。送り直しません | `.rejected` へ退けて次の envelope へ |
| `401` | 恒久的な失敗。以後 POST しません | `.rejected` へ退けて、その run を打ち切り |
| `413` | transport が envelope を割って送り直します | （transport 内で完結） |
| `429` / `5xx` / ネットワーク障害 | 再送対象 | spool に戻し、待ち時間の後に再送 |

### 422（envelope schema 不正）

拒否された field は `error.issues[].path` に出ます。`$.items[0].request.method` の
ように、envelope 内の位置を指します。アプリケーションが `captureException()` の
context で渡した値の型が違う場合がほとんどです。

### 401（key 拒否）

`401` を受けた transport は、以後 ingest へ POST しません。以後の送信は request を
投げずに `401` を返すだけになります。`Client::isStopped()` /
transport の `isStopped()` で分かります。有効範囲はその process（HTTP request 1 本、
CLI なら 1 回の実行）で、次の request は新しい Client と transport になるので、キーを
直せば復帰します。

停止後に返る `401` は body を持ちません（`status()` は `401`、`errorCode()` /
`errorMessage()` は `null`、`issues()` は空）。

`spool:flush` は `401` を受けた 1 通を `.rejected` へ退けてその run を打ち切り、残りは
次の run で送り直します。

### 413（envelope が大きすぎる）

envelope 1 通の上限は gzip 後 1 MiB・展開後 8 MiB です。item 数 100 での分割
（`batch_size`）とは別に、transport が送信前に gzip 後の byte 数を測り、上限を超える
envelope を item 境界で半分に割って、収まるまで繰り返します。1 通が複数 request に
なります。MONICA が `413` を返した場合も割って送り直します。

**item 1 件だけで上限を超える場合はその item を捨てます。** 捨てたことは警告に出し、
次の envelope の `discarded` で MONICA にも伝えます。

byte 上限は `Monica\Transport\EnvelopeSplitter::MAX_GZIP_BYTES` /
`MAX_DECOMPRESSED_BYTES` の定数です。

## 送信結果の受け取り

`Monica::lastResponse()` / `Client::lastResponse()`（`spool:flush` 経路は
`SpoolFlusher::lastResponse()`）が `Monica\Transport\Response` を返します。最初の送信
試行より前は `null` です。

```php
if (!\Monica\Monica::flush()) {
    $response = \Monica\Monica::lastResponse();
    foreach ($response !== null ? $response->issues() : [] as $issue) {
        // $issue['path'] / $issue['message']
    }
}
```

| method | 返り値 |
| --- | --- |
| `status()` | HTTP status。応答が無かった場合は `null` |
| `errorCode()` | 拒否 body の `error.code`。無ければ `null` |
| `errorMessage()` | 拒否 body の `error.message`。無ければ `null` |
| `issues()` | `422` の `error.issues`（`['path' => …, 'message' => …]` の配列）。他の status では空 |
| `outcome()` | `accepted` / `rejected` / `rejected_stop` / `retryable` |
| `retryAfterSeconds()` | `Retry-After` を整数秒にしたもの。無ければ `null` |
| `droppedItems()` | 大きすぎて捨てた item の数 |

`Client::isStopped()` は `401` で送信が止まったかを返します。`spool` mode では常に
`false` です（envelope は disk に書くだけで、MONICA と話すのは flusher の transport の
ため）。

body を読むのは `429` を除く 4xx だけです。body が空・非 JSON・`error.json` に適合
しない・64 KiB（`Response::MAX_BODY_BYTES`）を超える場合は、例外を投げずに issues
無しの拒否として扱います。

## 再送・queue の挙動

再送するのは `429` / `5xx` とネットワーク障害だけで、他の 4xx は恒久的な失敗です。

- `429` は `Retry-After` の秒数だけ待ちます。整数秒だけを解釈し（HTTP-date は backoff
  に落とします）、上限は 60 秒で、超える値は丸めます
- それ以外は `min(1000 * 2^(attempt-1), 30000)` ms に 50〜100% の jitter です
- attempt が上限（既定 5 回、`SpoolFlusher` の第 4 引数 `RetryPolicy` で変更可）に
  達したら envelope を捨て、警告を出します

待ち時間は `sleep()` で消費せず、attempt 回数と「この時刻まで送らない」を spool の
ファイル名（`…--try2-at1757500000.json`）に持ちます。待ち時間中の envelope は
`spool:flush` の出力の `deferred` に出ます。`sent=0 failed=0 deferred=3` は「MONICA が
応答しない」ではなく「まだ時刻ではない」という意味で、exit code は 0 です。

flush 中に process が停止して残った claim（`.sending-<pid>-…`）は、既定 5 分の lease
満了後に次の flush が回収します。回収しても attempt 回数と待ち時間は引き継ぎます。

**直接送信（`shutdown`）は再送しません。** `flush()` が `false` を返した event は queue
に残り、同じ process の中で次に `flush()` が呼ばれたときに送り直すだけで、process が
終われば失われます。

## よくある原因と対処

| 症状 | 原因と対処 |
| --- | --- |
| 初期化で `dsn carries a public key (mpk_)…` の例外 | DSN に public key を書いています。PHP SDK は secret key（`msk_`）を使います |
| 初期化で `dsn must use https except for localhost` の例外 | `localhost` / `127.0.0.1` 以外では `https` が必要です |
| 初期化で `http_client, request_factory and stream_factory must be supplied together` の例外 | PSR-18 経路はこの 3 つをまとめて渡します |
| `The cURL extension is required when no PSR-18 client is supplied` | `ext-curl` が無い環境です。拡張を入れるか PSR-18 client を渡してください |
| event がまったく届かず、警告も出ない | `vendor/bin/monica test` で疎通を確認してください。`sample_rate` と `before_send` が event を落としていないかも確認します |
| mod_php でレスポンスが `request_timeout_ms` 分遅くなる | `shutdown` は mod_php ではレスポンスをブロックします。`spool` に切り替えてください |
| `spool:flush` の `deferred` だけが増える | 再送の待ち時間です。`spool:flush` を定期実行していれば時刻が来たら送られます |
| `spool:flush` の `invalid` が増える | spool に JSON として読めないファイルがあります。`.invalid` へ退けてあります |
| spool のファイルが減らない | `spool:flush` が実行されていないか、`MONICA_DSN` / `--spool-dir` が書き込み側と食い違っています |
