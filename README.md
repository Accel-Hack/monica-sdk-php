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

この repository が PHP SDK の正本です。`Accel-Hack/monica` の `sdk/php/core` は
生成物ではなくなり、変更はここへ入れます。

protocol の仕様は言語に依存しない契約なので、この repository は持ちません。
`Accel-Hack/monica` の `spec/` を submodule として参照します。

```sh
git clone --recurse-submodules git@github.com:Accel-Hack/monica-sdk-php.git
# 既に clone している場合
git submodule update --init --depth 1
# spec/ 以外は要らないので絞る（任意）
git -C .spec-src sparse-checkout init --cone
git -C .spec-src sparse-checkout set spec
```

`Accel-Hack/monica` は private なので、submodule の取得には同 repository への
read 権限が必要です。CI では repository secret `SPEC_READ_TOKEN` を使います。

```sh
composer install
composer test
```

`composer test` は 3 つを順に走らせます。

- `tests/run.php`: SDK 内部の振る舞い（DSN 検証、before_send、spool、PSR-18 経路）
- `tests/fatal-runner.php`: 子プロセスの fatal shutdown で spool に 1 件残ること
- `tests/spec-contract.php`: 送信する envelope が `spec/event-schema.json` を満たすこと

契約テストは spec が見つからないと skip せず失敗します。契約が変わったときに
PHP だけ気付けない状態を作らないためです。

release は tag を打つだけです。Packagist が push webhook で version を拾います。
