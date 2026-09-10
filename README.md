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

`401` は 1 通の失敗ではなくキー自体の拒否なので、`transport.json` は `drop_and_stop`
と定めています。**`401` を受けた transport は、以後 ingest へ POST しません**
（`Client::isStopped()` / transport の `isStopped()` で分かります）。以後の送信は
requestを投げずに `401` を返すだけになります。PHPのprocessは短命なので、これは
「そのprocess（HTTP request 1本、CLIなら1回の実行）の中では送らない」という意味です。
次のrequestは新しいClientとtransportになるので、キーを直せばそのまま復帰します。
`spool:flush` も同じで、`401` を受けた1通を `.rejected` へ退けてそのrunを打ち切り、
残りは次のrunで送り直します。

`422`（envelope schema 不正）は、拒否レスポンスの body（`error.json`）を読んで
**既定で `error_log()` へ1行の警告を出します**。envelope のどのフィールドが拒否された
かは `error.issues[].path` にあり、これはアプリ側で直せる唯一の失敗なので、黙って
捨てると「いつからかeventが届かない」だけが残ります。`401`（キー自体が拒否され、
以後そのprocessからは送らなくなる）も同じく1行出します。送らなくなったあとの
envelopeについては出しません（同じ行が毎回出て埋もれるため、`401` を受けた1回だけ）。

```text
monica: ingest rejected the envelope with 422 (invalid_envelope): 1 issue(s); $.items[0].request.method: Invalid type: Expected string
monica: ingest rejected the envelope with 401 (invalid_key); no further envelopes will be sent
```

書式は6つのSDKで揃えてあります。`error.code` が body から読めないときは `unknown`
です。

出力先は `on_diagnostic` で差し替えられます（`callable` を渡すと
`(string $message, \Monica\Transport\Response $response)` を受け取り、
`false` / `null` で無効）。ログに出るのはMONICAが返した内容だけで、envelopeやAPIキーは
含みません。プログラムから読む場合は `Client::lastResponse()`（`spool:flush` 経路は
`SpoolFlusher::lastResponse()`）が `status()` / `errorCode()` / `errorMessage()` /
`issues()` を持つ `Response` を返します。`flush()` の戻り値の意味は変わりません。

```php
if (!\Monica\Monica::flush()) {
    $response = \Monica\Monica::lastResponse();
    foreach ($response !== null ? $response->issues() : [] as $issue) {
        // $issue['path'] / $issue['message']
    }
}
```

body が空・非JSON・`error.json` に適合しない・64 KiBを超える場合は、例外を投げずに
issues無しの拒否として扱います。`400` などの他の4xxも body は読むので `Response` から
`issues()` が取れますが、警告は出さず、破棄という扱いは変わりません（`429` は body を
読みません）。`429` / `5xx` の再送も変わりません。

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

`transport.json` のうち実装しているのは `endpoint` / `dsn` / `auth` と、
`status`（status ごとの挙動）の一部です。`status` は `Monica\Transport\Outcome`
が「受理 / 恒久失敗 / 再送可」の3つに分類し、spool の flusher がそれに従います。
残っているのは次の2つです。

- `retry`（`Retry-After`、backoff）には consumer がありません。再送は「次の flush で
  もう一度送る」だけで、待ち時間もjitterも回数上限もありません。shutdown transport は
  そもそも再送しないので、`429` / `5xx` の間の event は落ちます
- `413` の `split_and_retry`。envelope の byte 上限（gzip 1 MiB / 展開後 8 MiB）での
  分割が未実装なので、同じ byte を送り直しても `413` のままです。いまは恒久失敗として
  扱っています。item 数 100 での分割はあります

黙って取り残されないように、契約テストは `transport.json` の section 名と status の
語彙、それに status ごとの分類そのものを固定しています。MONICA 側が section や status
を増やすと、「この SDK が考慮していない契約が増えた」として落ちます。

## Release

tag を打つだけです。Packagist が push webhook で version を拾います。配布物には
`src/`、`bin/`、`composer.json`、`README.md`、`LICENSE` だけが入ります
（`.gitattributes` の `export-ignore`。CI の「配布物」job が実際の tarball で確認します）。
