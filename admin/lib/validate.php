<?php
declare(strict_types=1);

const SECTIONS = ['contact', 'social', 'hours', 'price', 'featured', 'menu', 'promos'];
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const DAY_NAMES = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
const IMG_RE = '#^(assets|uploads)/[A-Za-z0-9._%/-]+\.(jpe?g|png|webp)$#iD';

function gen_id(): string { return 'x' . bin2hex(random_bytes(4)); }
function keep_id($v): string { return (is_string($v) && preg_match('/^[a-z0-9]{3,12}$/D', $v)) ? $v : gen_id(); }

function v_str($v, int $max, string $label, array &$errors, bool $required = false): string
{
    $s = is_string($v) ? $v : '';
    if (!mb_check_encoding($s, 'UTF-8')) { $errors[] = "$label contains characters that are not allowed"; return ''; }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $s)) ?? '';
    $s = trim($s);
    if ($required && $s === '') $errors[] = "$label is required";
    elseif (mb_strlen($s) > $max) $errors[] = "$label is too long (max $max characters)";
    return $s;
}
function v_image($v, string $label, array &$errors): string
{
    if ($v === null || $v === '') return '';
    if (!is_string($v) || !preg_match(IMG_RE, $v) || str_contains($v, '..')) { $errors[] = "$label has an invalid photo"; return ''; }
    return $v;
}
function v_date($v, string $label, array &$errors): string
{
    if ($v === null || $v === '') return '';
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $errors[] = "$label is not a valid date"; return '';
    }
    return $v;
}
function v_url($v, string $label, array &$errors): string
{
    $s = is_string($v) ? trim($v) : '';
    if (!preg_match('#^https://#i', $s) || !filter_var($s, FILTER_VALIDATE_URL) || mb_strlen($s) > 300) {
        $errors[] = "$label must be a full link starting with https://"; return '';
    }
    return $s;
}

function v_bool(array $r, string $key, string $label, array &$errors): bool
{
    if (!array_key_exists($key, $r)) return false;
    if (!is_bool($r[$key])) { $errors[] = "$label must be true or false"; return false; }
    return $r[$key];
}

function v_contact($d, array &$e): array
{
    $d = is_array($d) ? $d : [];
    $phone = v_str($d['phone'] ?? '', 20, 'Phone number', $e, true);
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) $e[] = 'Phone number can only have digits, spaces, + ( ) and -';
    $email = v_str($d['email'] ?? '', 120, 'Email', $e, true);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'Email is not a valid address';
    $address = v_str($d['address'] ?? '', 160, 'Address', $e, true);
    if (substr_count($address, "\n") > 2) $e[] = 'Address can have at most 3 lines';
    $short = v_str($d['addressShort'] ?? '', 80, 'Short address', $e, true);
    return ['phone' => $phone, 'email' => $email, 'address' => $address, 'addressShort' => $short];
}

function v_social($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Social links must be a list'; return []; }
    if (count($d) > 6) $e[] = 'At most 6 social links';
    $out = [];
    foreach (array_values($d) as $i => $row) {
        $n = $i + 1;
        $row = is_array($row) ? $row : [];
        $out[] = ['label' => v_str($row['label'] ?? '', 20, "Link $n name", $e, true), 'url' => v_url($row['url'] ?? '', "Link $n", $e)];
    }
    return $out;
}

function v_hours($d, array &$e): array
{
    $by = [];
    foreach (is_array($d) ? $d : [] as $row) {
        if (is_array($row) && isset($row['day']) && in_array($row['day'], DAYS, true)) $by[$row['day']] = $row;
    }
    if (count($by) !== 7) { $e[] = 'Hours must include all seven days'; return []; }
    $out = [];
    foreach (DAYS as $day) {
        $name = DAY_NAMES[$day];
        $r = $by[$day];
        $st = $r['status'] ?? '';
        if (!in_array($st, ['open', 'closed', 'call'], true)) { $e[] = "$name: choose open, closed or call ahead"; continue; }
        if ($st !== 'open') { $out[] = ['day' => $day, 'status' => $st]; continue; }
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/D';
        $f = (string)($r['from'] ?? '');
        $t = (string)($r['to'] ?? '');
        if (!preg_match($re, $f) || !preg_match($re, $t)) { $e[] = "$name: enter opening and closing times"; continue; }
        if ($t <= $f) { $e[] = "$name: closing time must be after opening time"; continue; }
        $out[] = ['day' => $day, 'status' => 'open', 'from' => $f, 'to' => $t];
    }
    return $out;
}

function v_price($d, array &$e): array
{
    $d = is_array($d) ? $d : [];
    $lvl = filter_var($d['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
    if ($lvl === false) { $e[] = 'Price level must be 1, 2 or 3'; $lvl = 2; }
    return ['level' => $lvl, 'label' => v_str($d['label'] ?? '', 40, 'Price label', $e, true)];
}

function v_featured($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Dishes must be a list'; return []; }
    if (count($d) > 8) $e[] = 'At most 8 featured dishes';
    $out = [];
    foreach (array_values($d) as $i => $r) {
        $n = 'Dish ' . ($i + 1);
        $r = is_array($r) ? $r : [];
        $out[] = [
            'id' => keep_id($r['id'] ?? null),
            'name' => v_str($r['name'] ?? '', 40, "$n name", $e, true),
            'tag' => v_str($r['tag'] ?? '', 30, "$n label", $e),
            'description' => v_str($r['description'] ?? '', 140, "$n description", $e),
            'image' => v_image($r['image'] ?? '', $n, $e),
            'alt' => v_str($r['alt'] ?? '', 80, "$n photo description", $e),
            'hidden' => v_bool($r, 'hidden', "$n hidden", $e),
        ];
    }
    return $out;
}

function v_menu($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Menu must be a list'; return []; }
    if (count($d) > 8) $e[] = 'At most 8 menu categories';
    $out = [];
    foreach (array_values($d) as $i => $c) {
        $cn = 'Category ' . ($i + 1);
        $c = is_array($c) ? $c : [];
        if (isset($c['items']) && !is_array($c['items'])) $e[] = "$cn items must be a list";
        $items = is_array($c['items'] ?? null) ? $c['items'] : [];
        if (count($items) > 20) $e[] = "$cn can have at most 20 items";
        $cleanItems = [];
        foreach (array_values($items) as $j => $it) {
            $in = "$cn item " . ($j + 1);
            $it = is_array($it) ? $it : [];
            $price = $it['price'] ?? null;
            if ($price === '' || $price === null) $price = null;
            elseif (is_numeric($price) && (float)$price >= 0 && (float)$price <= 1000000) $price = $price + 0;
            else { $e[] = "$in: price must be a number"; $price = null; }
            $st = $it['status'] ?? 'available';
            if (!in_array($st, ['available', 'hidden', 'soldout'], true)) { $e[] = "$in: unknown status"; $st = 'available'; }
            $cleanItems[] = [
                'id' => keep_id($it['id'] ?? null),
                'name' => v_str($it['name'] ?? '', 50, "$in name", $e, true),
                'description' => v_str($it['description'] ?? '', 100, "$in description", $e),
                'price' => $price,
                'status' => $st,
            ];
        }
        $out[] = ['id' => keep_id($c['id'] ?? null), 'name' => v_str($c['name'] ?? '', 30, "$cn name", $e, true), 'items' => $cleanItems];
    }
    return $out;
}

function v_promos($d, array &$e): array
{
    if (!is_array($d)) { $e[] = 'Promos must be a list'; return []; }
    if (count($d) > 6) $e[] = 'At most 6 promos';
    $out = [];
    foreach (array_values($d) as $i => $r) {
        $n = 'Promo ' . ($i + 1);
        $r = is_array($r) ? $r : [];
        $start = v_date($r['start'] ?? '', "$n start date", $e);
        $end = v_date($r['end'] ?? '', "$n end date", $e);
        if ($start !== '' && $end !== '' && $end < $start) $e[] = "$n: the end date is before the start date";
        $out[] = [
            'id' => keep_id($r['id'] ?? null),
            'title' => v_str($r['title'] ?? '', 50, "$n title", $e, true),
            'details' => v_str($r['details'] ?? '', 200, "$n details", $e),
            'image' => v_image($r['image'] ?? '', $n, $e),
            'alt' => v_str($r['alt'] ?? '', 80, "$n photo description", $e),
            'start' => $start,
            'end' => $end,
            'active' => v_bool($r, 'active', "$n active", $e),
        ];
    }
    return $out;
}

/** @return array{0: mixed, 1: string[]} [cleanData, errors] */
function validate_section(string $section, $data): array
{
    if (!in_array($section, SECTIONS, true)) return [null, ['Unknown section']];
    $e = [];
    $fn = 'v_' . $section;
    $clean = $fn($data, $e);
    return [$clean, $e];
}

function validate_content(array $c): array
{
    $all = [];
    foreach (SECTIONS as $s) {
        [, $e] = validate_section($s, $c[$s] ?? []);
        foreach ($e as $msg) $all[] = "$s: $msg";
    }
    return $all;
}
