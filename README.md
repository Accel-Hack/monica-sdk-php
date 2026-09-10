# ah-monica/monica

Frameworkに依存せず、PHP 7.4以上からMONICAへ例外を送るcore SDKです。

```sh
composer require ah-monica/monica
```

## 初期化

```php
\Monica\Monica::init([
    'dsn' => getenv('MONICA_DSN'),
    'environment' => getenv('MONICA_ENVIRONMENT') ?: 'production',
    'release' => getenv('MONICA_RELEASE') ?: null,
    'transport' => 'shutdown',
    'request_timeout_ms' => 2000,
    'before_send' => static function (array $event): array {
        // アプリケーション側で送信を許可した値だけにしてください。
        unset($event['user']);
        if (isset($event['request'])) {
            unset($event['request']['headers']);
        }
        return $event;
    },
]);

\Monica\Monica::captureException($exception, [
    'tags' => ['service' => 'api'],
]);
```

`init()` は未捕捉例外、warning / notice、shutdown時のfatal error・OOM・
実行時間超過を収集します。既存のexception/error handlerがある場合は処理後にchainします。
SDKはrequest body、Cookie、Authorization、SQL引数を自動収集しません。

## Transport

- `shutdown`: FPMではレスポンス完了後に送信します。既定のcURL transportは接続と全体を
  `request_timeout_ms`（既定2000ms）で打ち切ります。
- `spool`: `spool_dir`へ権限0600のJSONをatomicに保存します。CLI/batch向けです。
  flush中にprocessが停止して残ったclaimは、既定5分のlease満了後に次のflushが回収します。

Apache + mod_php（`apache2handler`）では`shutdown`の送信がレスポンスをブロックします。
レスポンスを返し切ってから送るために使う`fastcgi_finish_request()`がFPM / FastCGI
SAPIにしか無く、mod_phpにはコネクションを切り離す手段がないためです。MONICAが
応答しないとクライアントの待ち時間が`request_timeout_ms`（既定2000ms）の分だけ
伸びるので、**この構成では`spool`を使ってください**。CLI/batchと同じ理由です。

MONICA が恒久的に拒否した envelope（`400` / `422` / `413`）は spool に残さず、
`.rejected` を付けて脇に退けます。残すと後続の envelope が、決して成功しない
requestを待って出られなくなるためです。`429` / `5xx` とネットワーク障害はspoolに
残り、次のflushで送り直します。`401`はその1通を退けてflushを打ち切ります。
`spool:flush` の出力の `rejected` がこれで、0 でなければ exit code は 1 です。

**`401` を受けた transport は、以後 ingest へ POST しません**
（`Client::isStopped()` / transport の `isStopped()` で分かります）。以後の送信は
requestを投げずに `401` を返すだけになります。有効範囲はそのprocess
（HTTP request 1本、CLIなら1回の実行）で、次のrequestは新しいClientとtransportに
なるので、キーを直せば復帰します。`spool:flush` は `401` を受けた1通を `.rejected` へ
退けてそのrunを打ち切り、残りは次のrunで送り直します。

停止後に返る `401` は body を持ちません（`status()` は `401`、`errorCode()` /
`errorMessage()` は `null`、`issues()` は空）。

## 拒否の警告

`422`（envelope schema 不正）は拒否レスポンスの body（`error.json`）を読み、
**既定で `error_log()` へ1行の警告を出します**。拒否されたフィールドは
`error.issues[].path` に出ます。`401` も1行出します（停止したあとの envelope に
ついては出しません）。

```text
monica: ingest rejected the envelope with 422 (invalid_envelope): 1 issue(s); $.items[0].request.method: Invalid type: Expected string
monica: ingest rejected the envelope with 401 (invalid_key); no further envelopes will be sent
```

`error.code` が body から読めないときは `unknown` です。ログに出るのはMONICAが
返した内容だけで、envelopeやAPIキーは含みません。

出力先は `on_diagnostic` で差し替えられます。`callable` を渡すと
`(string $message, \Monica\Transport\Response $response)` を受け取り、
`false` / `null` で無効になります。`bin/monica` は STDERR に出します。

プログラムから読む場合は `Client::lastResponse()`（`spool:flush` 経路は
`SpoolFlusher::lastResponse()`）が `status()` / `errorCode()` / `errorMessage()` /
`issues()` を持つ `Response` を返します。

```php
if (!\Monica\Monica::flush()) {
    $response = \Monica\Monica::lastResponse();
    foreach ($response !== null ? $response->issues() : [] as $issue) {
        // $issue['path'] / $issue['message']
    }
}
```

body が空・非JSON・`error.json` に適合しない・64 KiBを超える場合は、例外を投げずに
issues無しの拒否として扱います。`400` などの他の4xxは警告を出しません（`429` は
body を読みません）。

## envelopeの分割

`ingest.md` は envelope 1 通の上限を gzip 後 1 MiB・展開後 8 MiB と定めています。
item 数 100 での分割（`batch_size`）だけでは足りません。大きな `contexts` や
200 frame の stacktrace が並ぶと、100 件未満でも byte 上限を超えます。

transport は送信前に gzip 後の byte 数を測り、**上限を超える envelope を item 境界で
半分に割って**、収まるまで繰り返します。1 通が複数 requestになりますが、
`1 request = 1 envelope` は保たれます（`discarded` は分割の先頭だけが持ちます。
両方に写すと二重に報告されるため）。MONICA が `413` を返した場合も同じで、
上限は MONICA 側のものなので、その envelope を割って送り直します。

**item 1 件だけで上限を超える場合はその item を捨てます。** それ以上割れず、
送り直しても永久に `413` になるだけで、後続の envelope が出られなくなるからです。
捨てたことは警告に出し、次の envelope の `discarded` で MONICA にも伝えます。

```text
monica: dropped 1 item(s) that cannot fit one envelope (1234567 gzip bytes, limit 1048576, measured by the SDK); the event(s) are lost
```

byte 上限は `Monica\Transport\EnvelopeSplitter::MAX_GZIP_BYTES` /
`MAX_DECOMPRESSED_BYTES` の定数です（配布物に `spec/` は入らないので実行時に
読む先がありません）。契約テストが `limits.json` と突き合わせるので、MONICA が
上限を変えるとテストが落ちます。

DSNのAPIキーは secret key（`msk_`）です。public key（`mpk_`）は`X-Monica-Key`で
送るbrowser / mobile向けなので、渡すと初期化の時点で弾きます。Bearerとして送っても
`401`になり、eventが黙って消えるだけだからです。

```sh
vendor/bin/monica test
vendor/bin/monica spool:flush --spool-dir=/var/spool/monica
```

`MONICA_DSN`、`MONICA_ENVIRONMENT`、`MONICA_RELEASE`、
`MONICA_SPOOL_DIR`をCLIから利用できます。

PSR-18 clientを使う場合は、`http_client`、`request_factory`、
`stream_factory`をまとめて渡します。PSR-18自体にtimeout設定の標準がないため、
注入するclientも2秒程度に設定してください。

## Privacy

`before_send`は送信直前のevent配列を受け取り、加工後の配列または破棄する場合は
`null`を返します。個人情報を扱うサービスではblacklistではなく、MONICAへ送ってよい
キーだけでeventを組み直すallowlist方式を推奨します。transportとhook内の例外は
アプリケーションへ投げ返さず、MONICA自身の失敗を再収集しません。

## 開発

```sh
composer install
composer test
```

`composer test` は 3 つを順に走らせます。

- `tests/run.php`: SDK 内部の振る舞い（DSN 検証、before_send、spool、PSR-18 経路）
- `tests/fatal-runner.php`: 子プロセスの fatal shutdown で spool に 1 件残ること
- `tests/spec-contract.php`: 送信する envelope が MONICA の公開契約を満たすこと

## 公開契約

protocol は言語に依存しない契約なので、この repository は持ちません。MONICA が
<https://spec.monica.accelhack.net/v1/> に配信しているものを取り込んだコピーが
`spec/` にあります。

```text
spec.lock.json   取り込んだ内容の記録（version、revision、全ファイルの sha256）
spec/v1/         取り込んだコピー（配布物には入りません）
```

取り込みは script でやります。手で `spec/` を編集しても、次の取り込みで消えます。

```sh
composer spec:sync           # 配信元から取り込み直す
composer spec:check          # 取り込んだコピーが spec.lock.json と一致するか（network 不要）
composer spec:check-remote   # さらに配信元が動いていないか
```

起点は配信元の `index.json` です。他の全ファイルのパスと sha256、バンドル全体の
`revision` がそこに並んでいるので、**何を取り込むかは配信元が決めます**。この
repository は取り込む対象の一覧を持ちません。結果として

- ファイルの列挙、取得したものの整合性検査、上流にファイルが増えたことの検知が
  索引 1 本で済みます
- `spec:check-remote` は `revision` を 1 個比べるだけで、動いていたときに何が
  追加・変更・削除されたかを索引から出します

`revision` はバンドル全体の指紋（各ファイルの `"<sha256>  <path>"` を path の
byte 順に改行で繋いだ文字列の sha256）で、版番号ではないので新旧や大小は読めません。
`spec:check` はこれを `spec.lock.json` の `files` から再計算するので、`spec/` を
書き換えて lock の digest を揃えただけの改竄も落ちます。

契約テストは spec が見つからないと skip せず失敗します。契約が変わったときに
PHP だけ気付けない状態を作らないためです。schema を通ることは受理されることと
同じではない（`payload.md` が prose で定めている義務がある）ので、
`tests/spec-contract.php` は次の 4 層を見ます。

1. `envelope.json` と `limits.json` が、この SDK の前提どおりであること
2. MONICA の test vector が、bundle の言うとおりの判定になること
3. この SDK が出す envelope が、schema と `payload.md` の義務を満たすこと
4. この SDK が投げる request が、`transport.json` の値と一致すること

4 は `ingest.md` の散文から定数を写すのではなく、`transport.json` を読んで
突き合わせます。だから MONICA 側が endpoint やヘッダを変えると、ここが落ちます。

### まだ実装していない契約

`transport.json` のうち実装しているのは `endpoint` / `dsn` / `auth` と `status` です。
`413` は transport が envelope を分割して送り直します（[envelopeの分割](#envelopeの分割)）。
残っているのは次の1つです。

- `retry`（`Retry-After`、backoff、回数上限）。再送は次の flush で送り直すだけで、
  待ち時間も回数上限もありません。`shutdown` は再送しません

契約テストは `transport.json` の section 名、status の語彙、status ごとの分類を
固定しています。

## Release

tag を打つだけです。Packagist が push webhook で version を拾います。配布物には
`src/`、`bin/`、`composer.json`、`README.md`、`LICENSE` だけが入ります
（`.gitattributes` の `export-ignore`。CI の「配布物」job が実際の tarball で確認します）。
