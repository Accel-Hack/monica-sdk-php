#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MONICA の公開契約バンドルを spec/ へ取り込む。
 *
 *     php scripts/spec-sync.php                 取り込み直して spec.lock.json を書き換える
 *     php scripts/spec-sync.php --check         取り込み済みの spec/ が lock と一致するか（network 不要）
 *     php scripts/spec-sync.php --check-remote  さらに配信元が動いていないか
 *
 * 取り込むのは MONICA が https://spec.monica.accelhack.net/ に出している生成物で、
 * この repository はそのコピーを持つだけ。契約そのものはここでは決めない。
 *
 * 起点は配信元の `index.json` で、他の全ファイルのパスと sha256、バンドル全体の
 * `revision` がそこに並んでいる。だから
 *
 *   - 何を取り込むかは配信元が決める（この repository は宣言を持たない）
 *   - 取得したものが壊れていないかは、索引の digest と照合して分かる
 *   - 上流にファイルが増えたことも分かる
 *
 * が索引 1 本で済む。決定論は 3 つで守る。索引に載っているものだけを取りに行き、
 * 受け取った byte 列をそのまま書き、索引から外れたファイルは消す。配信元の
 * revision が同じなら、何度実行しても同じ byte 列になる。
 */

const LOCK_FILE = 'spec.lock.json';
const MIRROR_DIRECTORY = 'spec';
const INDEX_FILE = 'index.json';

/** 配信元が落ちているときに CI が 10 分待たされないための上限 */
const TIMEOUT_SECONDS = 20;

exit(main(array_slice($argv, 1)));

/**
 * @param list<string> $arguments
 */
function main(array $arguments): int
{
    $mode = 'sync';
    $originOverride = null;
    foreach ($arguments as $argument) {
        if ($argument === '--check' || $argument === '--check-remote') {
            $mode = substr($argument, 2);
            continue;
        }
        if (strpos($argument, '--origin=') === 0) {
            $originOverride = substr($argument, strlen('--origin='));
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            usage();

            return 0;
        }
        fwrite(STDERR, 'unknown argument: ' . $argument . PHP_EOL);
        usage();

        return 2;
    }

    try {
        $lock = readLock();
        // 配信元の差し替えは test で使う。lock に書かれた origin が既定で、
        // 上書きしても lock には書き戻さない（記録は本番の配信元のままにする）。
        $environmentOrigin = getenv('MONICA_SPEC_ORIGIN');
        $origin = $originOverride
            ?? ($environmentOrigin === false || $environmentOrigin === '' ? $lock['origin'] : $environmentOrigin);
        $origin = rtrim($origin, '/');
        requireHttps($origin);

        switch ($mode) {
            case 'check':
                return check($lock);
            case 'check-remote':
                $offline = check($lock);

                return $offline === 0 ? checkRemote($lock, $origin) : $offline;
            default:
                return sync($lock, $origin);
        }
    } catch (RuntimeException $failure) {
        fwrite(STDERR, 'spec-sync: ' . $failure->getMessage() . PHP_EOL);

        return 1;
    }
}

function usage(): void
{
    fwrite(
        STDOUT,
        'usage: php scripts/spec-sync.php [--check | --check-remote] [--origin=URL]' . PHP_EOL
        . '  (no flag)       配信元から取り込み直し、spec/ と ' . LOCK_FILE . ' を書き換える' . PHP_EOL
        . '  --check         spec/ が ' . LOCK_FILE . ' と一致するか。network を使わない' . PHP_EOL
        . '  --check-remote  さらに配信元の revision が変わっていないか' . PHP_EOL
    );
}

// --- 取り込み --------------------------------------------------------------

/**
 * @param array{origin: string, version: string, revision: string, files: array<string, string>} $lock
 */
function sync(array $lock, string $origin): int
{
    $index = fetchIndex($origin, $lock['version']);

    // 索引が本当に取ってこられる形か。壊れた索引で mirror を消してしまうと、
    // 取り込み直す元が手元から消える。
    $fetched = [];
    foreach ($index['files'] as $path => $digest) {
        $contents = fetch($origin . '/' . $lock['version'] . '/' . $path);
        $actual = hash('sha256', $contents);
        if ($actual !== $digest) {
            throw new RuntimeException(
                $path . ': 取得したものが索引の digest と違います（索引 ' . substr($digest, 0, 12)
                . '、取得 ' . substr($actual, 0, 12) . '）。配信中に更新された可能性があるので、やり直してください。'
            );
        }
        $fetched[$path] = $contents;
    }

    $added = [];
    $changed = [];
    foreach ($fetched as $path => $contents) {
        $target = mirrorPath($lock['version'], $path);
        $before = is_file($target) ? (string) file_get_contents($target) : null;
        if ($before === $contents) {
            continue;
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('cannot create ' . $directory);
        }
        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('cannot write ' . $target);
        }
        if ($before === null) {
            $added[] = $path;
        } else {
            $changed[] = $path;
        }
    }

    $removed = pruneMirror($lock['version'], array_keys($fetched));

    $wasRevision = $lock['revision'];
    $lock['revision'] = $index['revision'];
    $lock['files'] = $index['files'];
    writeLock($lock);

    echo 'spec-sync: ' . count($fetched) . ' ファイルを ' . $origin . '/' . $lock['version']
        . ' から取り込んだ' . PHP_EOL;
    echo '  revision ' . ($wasRevision === $index['revision']
        ? substr($index['revision'], 0, 12) . '（変わらず）'
        : substr($wasRevision, 0, 12) . ' → ' . substr($index['revision'], 0, 12)) . PHP_EOL;
    report('  追加', $added);
    report('  変更', $changed);
    report('  削除', $removed);
    if ($added === [] && $changed === [] && $removed === []) {
        echo '  差分なし' . PHP_EOL;
    }

    return 0;
}

/**
 * 索引に無いものを spec/<version>/ から消す。空になったディレクトリも畳む。
 *
 * @param list<string> $declared
 * @return list<string> 消したパス
 */
function pruneMirror(string $version, array $declared): array
{
    $keep = array_flip($declared);
    $removed = [];
    foreach (mirrorFiles($version) as $path) {
        if (isset($keep[$path])) {
            continue;
        }
        if (!unlink(mirrorPath($version, $path))) {
            throw new RuntimeException('cannot remove ' . mirrorPath($version, $path));
        }
        $removed[] = $path;
    }

    // 深い側から畳まないと、親を消す時点でまだ子が残っている
    foreach (deepestFirstDirectories(versionRoot($version)) as $directory) {
        // 中身が残っていれば rmdir は失敗する。それが望む挙動なので握りつぶす
        @rmdir($directory);
    }

    sort($removed, SORT_STRING);

    return $removed;
}

// --- 検査 ------------------------------------------------------------------

/**
 * 取り込み済みの spec/ が lock どおりか。network を使わないので、
 * 契約テストの直前に毎回走らせられる。
 *
 * @param array{origin: string, version: string, revision: string, files: array<string, string>} $lock
 */
function check(array $lock): int
{
    $problems = [];
    foreach ($lock['files'] as $path => $expected) {
        $target = mirrorPath($lock['version'], $path);
        if (!is_file($target)) {
            $problems[] = $path . ': 取り込まれていない';
            continue;
        }
        $actual = hash('sha256', (string) file_get_contents($target));
        if ($actual !== $expected) {
            $problems[] = $path . ': 中身が ' . LOCK_FILE . ' と違う（手で直したものは次の取り込みで消える）';
        }
    }
    foreach (mirrorFiles($lock['version']) as $path) {
        if (!isset($lock['files'][$path])) {
            $problems[] = $path . ': ' . LOCK_FILE . ' が宣言していない';
        }
    }

    // lock 自身の整合性。digest を書き換えて mirror と揃えただけの改竄は、
    // revision を再計算すると合わなくなる。
    $recomputed = revisionOf($lock['files']);
    if ($recomputed !== $lock['revision']) {
        $problems[] = LOCK_FILE . ': revision が files から再計算した値（'
            . substr($recomputed, 0, 12) . '）と違う';
    }

    if ($problems !== []) {
        fwrite(
            STDERR,
            '取り込んだ spec が ' . LOCK_FILE . ' と一致しません。' . PHP_EOL
            . '  - ' . implode(PHP_EOL . '  - ', $problems) . PHP_EOL
            . 'php scripts/spec-sync.php で取り込み直してください。' . PHP_EOL
        );

        return 1;
    }

    echo 'spec-sync: 取り込んだ ' . count($lock['files']) . ' ファイルは ' . LOCK_FILE
        . ' と一致（revision ' . substr($lock['revision'], 0, 12) . '）' . PHP_EOL;

    return 0;
}

/**
 * 配信元が動いたかどうか。SDK が古い契約に従い続けるのを止める。
 *
 * revision はバンドル全体の指紋なので、まず 1 個だけ比べれば足りる。違っていた
 * ときだけ、どのファイルがどう動いたかを索引から出す。
 *
 * @param array{origin: string, version: string, revision: string, files: array<string, string>} $lock
 */
function checkRemote(array $lock, string $origin): int
{
    $index = fetchIndex($origin, $lock['version']);
    if ($index['revision'] === $lock['revision']) {
        echo 'spec-sync: ' . $origin . '/' . $lock['version'] . ' の revision は取り込み済みのものと一致（'
            . substr($lock['revision'], 0, 12) . '）' . PHP_EOL;

        return 0;
    }

    $moved = [];
    foreach ($index['files'] as $path => $digest) {
        if (!isset($lock['files'][$path])) {
            $moved[] = '追加 ' . $path;
        } elseif ($lock['files'][$path] !== $digest) {
            $moved[] = '変更 ' . $path;
        }
    }
    foreach ($lock['files'] as $path => $ignored) {
        if (!isset($index['files'][$path])) {
            $moved[] = '削除 ' . $path;
        }
    }
    sort($moved, SORT_STRING);

    fwrite(
        STDERR,
        $origin . '/' . $lock['version'] . ' の公開契約が動いています。' . PHP_EOL
        . '  revision ' . substr($lock['revision'], 0, 12) . ' → ' . substr($index['revision'], 0, 12) . PHP_EOL
        . '  - ' . implode(PHP_EOL . '  - ', $moved) . PHP_EOL
        . 'php scripts/spec-sync.php で取り込み直し、契約テストを通してから commit してください。' . PHP_EOL
    );

    return 1;
}

// --- 索引 ------------------------------------------------------------------

/**
 * 配信元の索引。ここが取り込みの唯一の起点。
 *
 * @return array{revision: string, files: array<string, string>}
 */
function fetchIndex(string $origin, string $version): array
{
    $url = $origin . '/' . $version . '/' . INDEX_FILE;
    try {
        $raw = fetch($url);
    } catch (RuntimeException $failure) {
        throw new RuntimeException(
            $url . ' が取れません（' . $failure->getMessage() . '）。' . PHP_EOL
            . '  索引はバンドルの生成物なので、配信元がまだ更新されていない場合もここで止まります。'
        );
    }

    $index = json_decode($raw, true);
    if (!is_array($index) || !isset($index['version'], $index['revision'], $index['files'])) {
        throw new RuntimeException($url . ': version、revision、files のどれかがありません');
    }
    // 索引の `base` は「このバンドルを生成した配信元」の記録で、取得先の設定では
    // ない。mirror や手元に立てたものから取り込むと origin と食い違うのが正常な
    // ので、検証には使わない。信じるのはこちらが設定した origin と各 digest。
    if ($index['version'] !== $version) {
        throw new RuntimeException(
            $url . ': 索引の version が ' . json_encode($index['version']) . ' で、要求した '
            . $version . ' と違います'
        );
    }
    if (!is_array($index['files']) || $index['files'] === []) {
        throw new RuntimeException($url . ': files が空です');
    }

    $files = [];
    $order = [];
    foreach ($index['files'] as $entry) {
        if (!is_array($entry) || !isset($entry['path'], $entry['sha256'])) {
            throw new RuntimeException($url . ': files の要素に path と sha256 がありません');
        }
        $path = (string) $entry['path'];
        requireSafePath($path);
        if ($path === INDEX_FILE) {
            // 索引は自分の digest を持てないので、自分を載せない
            throw new RuntimeException($url . ': 索引が自分自身を載せています');
        }
        if (isset($files[$path])) {
            throw new RuntimeException($url . ': ' . $path . ' が索引に 2 回出ています');
        }
        if (preg_match('~^[0-9a-f]{64}$~', (string) $entry['sha256']) !== 1) {
            throw new RuntimeException($url . ': ' . $path . ' の sha256 が 64 桁の hex ではありません');
        }
        $files[$path] = (string) $entry['sha256'];
        $order[] = $path;
    }

    // files が path の byte 順に並ぶことは索引側の保証（バンドルの README に
    // 明記されている）。revision をこちらが lock から再計算するので、並びが
    // 変わると一致しなくなる。保証が崩れたことに気付けるよう、黙って
    // 並べ替えずに拒否する。
    $sorted = $order;
    sort($sorted, SORT_STRING);
    if ($sorted !== $order) {
        throw new RuntimeException($url . ': files が path の byte 順に並んでいません');
    }
    $recomputed = revisionOf($files);
    if ($recomputed !== $index['revision']) {
        throw new RuntimeException(
            $url . ': revision が files から再計算した値と違います（索引 '
            . substr((string) $index['revision'], 0, 12) . '、再計算 ' . substr($recomputed, 0, 12) . '）'
        );
    }

    return ['revision' => (string) $index['revision'], 'files' => $files];
}

/**
 * バンドル全体の指紋。`"<sha256>  <path>"` を path の byte 順に改行で繋いだ
 * 文字列の sha256（末尾に改行を付けない）。MONICA 側の生成器と同じ定義。
 *
 * @param array<string, string> $files
 */
function revisionOf(array $files): string
{
    ksort($files, SORT_STRING);
    $lines = [];
    foreach ($files as $path => $digest) {
        $lines[] = $digest . '  ' . $path;
    }

    return hash('sha256', implode("\n", $lines));
}

// --- lock ------------------------------------------------------------------

/**
 * @return array{origin: string, version: string, revision: string, files: array<string, string>}
 */
function readLock(): array
{
    $path = repositoryRoot() . '/' . LOCK_FILE;
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException(LOCK_FILE . ' が読めません: ' . $path);
    }
    $lock = json_decode($raw, true);
    if (!is_array($lock) || !isset($lock['origin'], $lock['version'], $lock['revision'], $lock['files'])) {
        throw new RuntimeException(LOCK_FILE . ' に origin、version、revision、files のどれかがありません');
    }
    if (!is_array($lock['files'])) {
        throw new RuntimeException(LOCK_FILE . ' の files が object ではありません');
    }
    $version = (string) $lock['version'];
    if (preg_match('~^v[0-9]+$~', $version) !== 1) {
        throw new RuntimeException(LOCK_FILE . ': version は v1 のような形にしてください');
    }

    $files = [];
    foreach ($lock['files'] as $path => $digest) {
        requireSafePath((string) $path);
        $files[(string) $path] = is_string($digest) ? $digest : '';
    }
    ksort($files, SORT_STRING);

    return [
        'origin' => rtrim((string) $lock['origin'], '/'),
        'version' => $version,
        'revision' => (string) $lock['revision'],
        'files' => $files,
    ];
}

/**
 * @param array{origin: string, version: string, revision: string, files: array<string, string>} $lock
 */
function writeLock(array $lock): void
{
    ksort($lock['files'], SORT_STRING);
    $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('cannot encode ' . LOCK_FILE);
    }
    if (file_put_contents(repositoryRoot() . '/' . LOCK_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException('cannot write ' . LOCK_FILE);
    }
}

// --- 取得 ------------------------------------------------------------------

/**
 * 配信元から 1 ファイル。200 以外と、JSON として壊れている .json は失敗にする。
 * 半端な取り込みを commit させないため、呼び出し側は 1 つでも失敗したら止まる。
 */
function fetch(string $url): string
{
    $contents = function_exists('curl_init') ? fetchWithCurl($url) : fetchWithStream($url);

    if (substr($url, -5) === '.json' && json_decode($contents) === null) {
        throw new RuntimeException($url . ': JSON として読めません');
    }
    if ($contents === '') {
        throw new RuntimeException($url . ': 本文が空です');
    }

    return $contents;
}

function fetchWithCurl(string $url): string
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('cannot initialize cURL');
    }
    try {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            throw new RuntimeException($url . ': ' . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            throw new RuntimeException($url . ': HTTP ' . $status);
        }

        return (string) $body;
    } finally {
        curl_close($handle);
    }
}

function fetchWithStream(string $url): string
{
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => TIMEOUT_SECONDS, 'ignore_errors' => true, 'follow_location' => 0],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException($url . ': 取得できません');
    }
    // $http_response_header は file_get_contents が local scope に生やす
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    if ($status !== 200) {
        throw new RuntimeException($url . ': HTTP ' . $status);
    }

    return $body;
}

// --- パス ------------------------------------------------------------------

function repositoryRoot(): string
{
    return dirname(__DIR__);
}

function versionRoot(string $version): string
{
    return repositoryRoot() . '/' . MIRROR_DIRECTORY . '/' . $version;
}

/**
 * 索引が指すパスが spec/ の外を指さないことを、書く前に確かめる。索引は
 * network から来るので、`..` が入っていないことを自分で見る必要がある。
 */
function requireSafePath(string $path): void
{
    foreach (explode('/', $path) as $segment) {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~', $segment) !== 1 || strpos($segment, '..') !== false) {
            throw new RuntimeException($path . ': 使えないパスです');
        }
    }
}

function mirrorPath(string $version, string $path): string
{
    requireSafePath($path);

    return versionRoot($version) . '/' . $path;
}

/**
 * spec/<version>/ にある全ファイル。索引との差を両方向で見るために使う。
 *
 * @return list<string>
 */
function mirrorFiles(string $version): array
{
    $root = versionRoot($version);
    if (!is_dir($root)) {
        return [];
    }
    $found = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($entries as $entry) {
        if ($entry->isFile()) {
            $found[] = substr($entry->getPathname(), strlen($root . '/'));
        }
    }
    sort($found, SORT_STRING);

    return $found;
}

/**
 * $root 配下のディレクトリを、深いものから先に。空になったものを畳むのに使う。
 *
 * @return list<string>
 */
function deepestFirstDirectories(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }
    $found = [];
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) {
            continue;
        }
        $found = array_merge($found, deepestFirstDirectories($entry->getPathname()), [$entry->getPathname()]);
    }

    return $found;
}

function requireHttps(string $origin): void
{
    // 契約を平文で取ってくると、取り込んだ内容を誰でも差し替えられる。
    if (strpos($origin, 'https://') !== 0 && strpos($origin, 'http://127.0.0.1') !== 0) {
        throw new RuntimeException('配信元は https にしてください: ' . $origin);
    }
}

/**
 * @param list<string> $paths
 */
function report(string $label, array $paths): void
{
    foreach ($paths as $path) {
        echo $label . ' ' . $path . PHP_EOL;
    }
}
