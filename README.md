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

MONICA が恒久的に拒否した envelope（`400` / `422` / `413`）は spool に残さず、
`.rejected` を付けて脇に退けます。残すと後続の envelope が、決して成功しない
requestを待って出られなくなるためです。`429` / `5xx` とネットワーク障害はspoolに
残り、次のflushで送り直します。`401`はその1通を退けてflushを打ち切ります。
`spool:flush` の出力の `rejected` がこれで、0 でなければ exit code は 1 です。

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

`error.json` の body も読んでいません（`422` の `issues` が見えません）。

黙って取り残されないように、契約テストは `transport.json` の section 名と status の
語彙、それに status ごとの分類そのものを固定しています。MONICA 側が section や status
を増やすと、「この SDK が考慮していない契約が増えた」として落ちます。

## Release

tag を打つだけです。Packagist が push webhook で version を拾います。配布物には
`src/`、`bin/`、`composer.json`、`README.md`、`LICENSE` だけが入ります
（`.gitattributes` の `export-ignore`。CI の「配布物」job が実際の tarball で確認します）。
