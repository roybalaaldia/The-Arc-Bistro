<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/validate.php';

$GLOBALS['t_fails'] = 0;
$GLOBALS['t_count'] = 0;

function t(string $name, callable $fn): void
{
    $GLOBALS['t_count']++;
    try { $fn(); echo "ok   $name\n"; }
    catch (Throwable $e) { $GLOBALS['t_fails']++; echo "FAIL $name\n     " . $e->getMessage() . "\n"; }
}
function eq($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}
function ok($cond, string $msg = 'assertion failed'): void { if (!$cond) throw new Exception($msg); }
function throws(callable $fn, string $class = Throwable::class): Throwable
{
    try { $fn(); }
    catch (Throwable $e) {
        if ($e instanceof $class) return $e;
        throw new Exception('wrong exception ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new Exception("expected $class to be thrown");
}
/** Fresh temp site dir with a copy of the real default content.json; points arc_cfg() at it. */
function fresh_env(): string
{
    $d = sys_get_temp_dir() . '/arc-' . bin2hex(random_bytes(4));
    mkdir($d . '/uploads', 0775, true);
    mkdir($d . '/data', 0775, true);
    copy(dirname(__DIR__, 2) . '/content.json', $d . '/content.json');
    $GLOBALS['ARC_CFG'] = ['root' => $d, 'content' => $d . '/content.json', 'uploads' => $d . '/uploads', 'data' => $d . '/data'];
    return $d;
}
function done(): void
{
    echo "\n{$GLOBALS['t_count']} tests, {$GLOBALS['t_fails']} failed\n";
    exit($GLOBALS['t_fails'] ? 1 : 0);
}

const GOOD_CONTACT = ['phone' => '+63 995 109 1503', 'email' => 'a@b.co', 'address' => "1 St\nCity", 'addressShort' => '1 St, City'];
function week(string $status = 'open'): array
{
    return array_map(
        fn($d) => $status === 'open' ? ['day' => $d, 'status' => 'open', 'from' => '10:00', 'to' => '21:00'] : ['day' => $d, 'status' => $status],
        DAYS
    );
}
