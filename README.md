# ah-monica/monica

Framework に依存しない PHP 用の core SDK です。未捕捉例外・PHP error・fatal error を
MONICA の ingest へ送ります。

## 対応環境

- PHP 7.4 以上（7.4 / 8.0 / 8.3 / 8.5 で検証しています）
- 必須拡張: `ext-json`、`ext-zlib`
- 既定の HTTP transport は `ext-curl` を使います。PSR-18 client を渡す場合は不要です
- SAPI: FPM / FastCGI、CLI、mod_php（mod_php は「制約」を参照）

## インストール

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
]);
```

DSN は `https://msk_xxxxx@<host>/` の形式で、送信先は `<host>/v1/envelope` になります。
`https` が必須です（`localhost` / `127.0.0.1` に限り `http` も使えます）。

`init()` は例外 handler・error handler・shutdown function を登録します。既存の
handler がある場合は、SDK の処理後にその handler へ chain します。登録したくない
場合は `'auto_capture' => false` を渡してください。

## 使い方

### 例外・メッセージを送る

```php
\Monica\Monica::captureException($exception, [
    'tags' => ['service' => 'api'],
]);

\Monica\Monica::captureMessage('cache rebuild skipped', 'warning');
```

第 2 引数（`captureMessage` は第 3 引数）の context で event に載せられるキーは
`tags` / `contexts` / `request` / `user` / `fingerprint` / `breadcrumbs` です。
`captureException` は `level` も受け付けます（既定は `error`）。戻り値は `event_id`
で、送らなかった場合（sampling で外れた、`before_send` が `null` を返した）は `null`
です。

queue に溜まった event は shutdown 時に自動で送られます。その前に送りたい場合は
`flush()` を呼びます。

```php
\Monica\Monica::flush(); // bool。送れなかった event は queue に残ります
```

### transport の選び方

| `transport` | 動作 | 用途 |
| --- | --- | --- |
| `shutdown`（既定） | request の処理後に ingest へ直接 POST します。FPM / FastCGI では `fastcgi_finish_request()` でレスポンスを返し切ってから送ります | FPM / FastCGI |
| `spool` | envelope を `spool_dir` に権限 0600 の JSON として atomic に書き出します。送信は `monica spool:flush` が行います | CLI / batch、mod_php、`429` / `5xx` の間も event を落としたくない場合 |

### spool の運用

`spool` を使う場合は、`vendor/bin/monica spool:flush` を定期実行してください。再送
待ちの envelope は次の flush が拾うので、1 回しか実行しない運用では待ち時間に入った
envelope が送られません。間隔は 1 分程度が扱いやすく、同時に複数走っても問題あり
ません。

```cron
* * * * * /usr/bin/php /srv/app/vendor/bin/monica spool:flush >> /var/log/monica-spool.log 2>&1
```

systemd timer なら次のようになります（`MONICA_DSN` は `Environment=` か
`EnvironmentFile=` で渡します）。

```ini
# monica-spool.service
[Service]
Type=oneshot
EnvironmentFile=/etc/monica.env
ExecStart=/usr/bin/php /srv/app/vendor/bin/monica spool:flush

# monica-spool.timer
[Timer]
OnUnitActiveSec=1min
AccuracySec=1s
```

出力は 5 つの counter です。

```text
MONICA spool: sent=0 failed=0 rejected=0 invalid=0 deferred=3
```

| counter | 意味 |
| --- | --- |
| `sent` | 送れた envelope |
| `failed` | 送れずに spool へ戻した envelope（次の flush で送り直します） |
| `rejected` | 送れないと判断して `.rejected` へ退けた envelope |
| `invalid` | JSON として読めず `.invalid` へ退けたファイル |
| `deferred` | 再送の待ち時間がまだ明けていない envelope（触っていません） |

`failed` / `rejected` / `invalid` のいずれかが 0 でなければ exit code は 1 です。
`deferred` だけが立っている場合は 0 です。警告は STDERR に出るので、上の例のように
ログへ落としてください。

### CLI

```sh
vendor/bin/monica test
vendor/bin/monica spool:flush --spool-dir=/var/spool/monica
```

`test` は疎通確認の event を 1 件送ります。共通の option は `--dsn`、`--environment`、
`--release`、`--spool-dir`、`--timeout-ms`（既定 2000）で、`MONICA_DSN`、
`MONICA_ENVIRONMENT`、`MONICA_RELEASE`、`MONICA_SPOOL_DIR` からも読みます。

### PSR-18 client を使う

`http_client`、`request_factory`、`stream_factory` を 3 つまとめて渡すと、cURL の
代わりにその client で送ります。PSR-18 自体に timeout 設定の標準がないため、注入する
client 側で 2 秒程度に設定してください。

```php
\Monica\Monica::init([
    'dsn' => getenv('MONICA_DSN'),
    'environment' => 'production',
    'http_client' => $psr18Client,
    'request_factory' => $psr17Factory,
    'stream_factory' => $psr17Factory,
]);
```

## オプション

`Monica::init()` / `new \Monica\Client()` に渡す配列のキーです。

| option | 型 | default | 説明 |
| --- | --- | --- | --- |
| `dsn` | string | （必須） | 空文字は不可。`mpk_` で始まる key は拒否します |
| `environment` | string | （必須） | 空文字は不可 |
| `release` | string\|null | `null` | event の `release` |
| `transport` | `'shutdown'` \| `'spool'` | `'shutdown'` | それ以外の値は例外 |
| `auto_capture` | bool | `true` | `false` で handler を登録しません |
| `error_types` | int | `E_WARNING｜E_USER_WARNING｜E_NOTICE｜E_USER_NOTICE` | 収集する PHP error の bitmask。fatal error は別途 shutdown で拾います |
| `sample_rate` | float | `1.0` | 0〜1。範囲外は例外 |
| `before_send` | callable\|null | `null` | 送信直前の event 配列を受け取り、加工した配列か `null`（破棄）を返す |
| `on_diagnostic` | callable\|false\|null | `error_log()` へ 1 行 | 警告の出力先。`false` / `null` で無効 |
| `max_queue_size` | int | `100` | 超えた分は古い event から捨て、`discarded` として MONICA に伝えます |
| `batch_size` | int | `100` | envelope 1 通あたりの item 数。実効値は `min(指定値, 100, max_queue_size)` |
| `request_timeout_ms` | int | `2000` | 接続と全体の両方に適用します |
| `spool_dir` | string | `sys_get_temp_dir()/monica-spool` | `transport` が `spool` のとき使います |
| `spool_max_files` | int | `1000` | 超えると古いファイルから削除します |
| `memory_reserve_bytes` | int | `262144` | OOM 後の shutdown 処理用に確保しておく領域 |
| `project_root` | string\|null | `null` | stack frame の `in_app` 判定の基準 |
| `server_name` | string\|null | `gethostname()` の値 | event の `server_name` |
| `http_client` / `request_factory` / `stream_factory` | PSR-18 / PSR-17 | `null` | 3 つまとめて渡します。1 つでも欠けると例外 |
| `transport_instance` | `Monica\Transport\TransportInterface` | `null` | transport を差し替えます |

正の整数を取る option（`max_queue_size`、`batch_size`、`request_timeout_ms`、
`spool_max_files`、`memory_reserve_bytes`）に 1 未満を渡すと例外になります。

## 自動で収集するもの

`init()` が自動で拾うのは次の 3 つです。

- 未捕捉例外（`level` は `fatal`、`mechanism.handled` は `false`）
- `error_types` に該当する PHP error（既定は warning / notice）
- shutdown 時の fatal error・OOM・実行時間超過

event に自動で入るのは、`event_id`、`timestamp`、`level`、`platform`（`php`）、
`environment`、`release`、`server_name`（`gethostname()`）、例外の class 名・
message・stack frame（ファイル名・関数名・行番号・`in_app`）です。連鎖した例外は
10 段、stack frame は 200 段までです。

request body、Cookie、Authorization ヘッダ、SQL 引数、`$_SERVER` は読みません。
`request` / `user` などのキーは、アプリケーションが context で渡したものだけが
入ります。

### Privacy

`before_send` は送信直前の event 配列を受け取り、加工後の配列か、破棄する場合は
`null` を返します。個人情報を扱うサービスでは、blacklist ではなく MONICA へ送って
よいキーだけで event を組み直す allowlist 方式を推奨します。

```php
'before_send' => static function (array $event): array {
    unset($event['user']);
    if (isset($event['request'])) {
        unset($event['request']['headers']);
    }

    return $event;
},
```

transport と hook 内で起きた例外はアプリケーションへ投げ返さず、MONICA 自身の失敗を
再収集することもありません。

## 送信結果と診断

ingest が envelope を拒否した場合、`422`（schema 不正）と `401`（key 拒否）は既定で
`error_log()` に 1 行の警告を出します（`bin/monica` は STDERR）。出力先は
`on_diagnostic` で差し替え・無効化できます。

プログラムから読む場合は `Monica::lastResponse()` / `Client::lastResponse()`（spool は
`SpoolFlusher::lastResponse()`）が `status()` / `errorCode()` / `errorMessage()` /
`issues()` を持つ `Monica\Transport\Response` を返します。`401` を受けたあとは
`Client::isStopped()` が `true` になります。

警告の読み方、status ごとの挙動、再送と queue の詳細は
[TROUBLESHOOTING.md](TROUBLESHOOTING.md) を参照してください。

## 制約

- DSN の API キーは secret key（`msk_`）です。public key（`mpk_`）を渡すと初期化の
  時点で例外になります
- Apache + mod_php（`apache2handler`）では `shutdown` の送信がレスポンスをブロック
  します。この構成では `spool` を使ってください
- `shutdown`（直接送信）は再送しません。`flush()` が失敗した event は同じ process の
  中でしか送り直されず、process が終われば失われます。`429` / `5xx` の間の event を
  落としたくない場合は `spool` を使ってください
- envelope 1 通の上限は gzip 後 1 MiB・展開後 8 MiB です。超える envelope は item
  境界で分割して送りますが、item 1 件だけで超える場合はその item を捨てます
- queue は `max_queue_size` 件までで、溢れた分は古い event から捨てます。spool は
  `spool_max_files` 件までです

## ライセンス

Apache-2.0

## 開発者向け

### ビルドとテスト

```sh
composer install
composer test
```

`composer test` は 3 つを順に走らせます。

- `tests/run.php`: SDK 内部の振る舞い（DSN 検証、before_send、spool、PSR-18 経路）
- `tests/fatal-runner.php`: 子プロセスの fatal shutdown で spool に 1 件残ること
- `tests/spec-contract.php`: 送信する envelope が MONICA の公開契約を満たすこと

### 公開契約（spec/）

`spec/` は <https://spec.monica.accelhack.net/v1/> から取り込んだコピーで、
`spec.lock.json` が取り込んだ内容（version、revision、全ファイルの sha256）を持ちます。
どちらも配布物には入りません。手で `spec/` を編集しても次の取り込みで消えます。

```sh
composer spec:sync           # 配信元から取り込み直す
composer spec:check          # 取り込んだコピーが spec.lock.json と一致するか（network 不要）
composer spec:check-remote   # さらに配信元が動いていないか
```

### リリース

1. `src/Client.php` の `Client::SDK_VERSION` を上げる
2. `v<semver>` の tag を打つ（Packagist が push webhook で version を拾います）

配布物には `src/`、`bin/`、`composer.json`、`README.md`、`LICENSE` などが入ります
（`.gitattributes` の `export-ignore`）。CI がタグと `SDK_VERSION` の一致、および
配布物の中身を検査します。
