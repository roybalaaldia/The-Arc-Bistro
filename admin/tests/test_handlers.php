<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

const U_STAFF = ['id' => 'u1', 'username' => 'staff1', 'role' => 'staff'];
const U_OWNER = ['id' => 'u2', 'username' => 'boss', 'role' => 'owner'];

t('staff cannot save hours, and content stays byte-identical', function () {
    fresh_env();
    $before = file_get_contents(arc_cfg('content'));
    [$st, $body] = handle_content_save(U_STAFF, ['section' => 'hours', 'baseRevision' => 0, 'data' => []]);
    eq($st, 403); eq($body['ok'], false);
    eq(file_get_contents(arc_cfg('content')), $before);
});
t('staff cannot save contact, price or social either', function () {
    fresh_env();
    foreach (['contact', 'price', 'social'] as $s) {
        [$st] = handle_content_save(U_STAFF, ['section' => $s, 'baseRevision' => 0, 'data' => []]);
        eq($st, 403, $s);
    }
});
t('staff can save featured dishes', function () {
    fresh_env();
    $c = store_read();
    $c['featured'][0]['name'] = 'Fettuccine Carbonara';
    [$st, $body] = handle_content_save(U_STAFF, ['section' => 'featured', 'baseRevision' => 0, 'data' => $c['featured']]);
    eq($st, 200, json_encode($body));
    eq(store_read()['featured'][0]['name'], 'Fettuccine Carbonara');
    eq($body['revision'], 1);
});
t('owner can save contact and the response carries cleaned data', function () {
    fresh_env();
    [$st, $body] = handle_content_save(U_OWNER, ['section' => 'contact', 'baseRevision' => 0, 'data' => GOOD_CONTACT]);
    eq($st, 200); eq($body['data']['email'], 'a@b.co');
});
t('invalid data returns 422 with messages and changes nothing', function () {
    fresh_env();
    $before = file_get_contents(arc_cfg('content'));
    [$st, $body] = handle_content_save(U_OWNER, ['section' => 'contact', 'baseRevision' => 0, 'data' => ['phone' => 'x'] + GOOD_CONTACT]);
    eq($st, 422); ok(count($body['errors']) >= 1);
    eq(file_get_contents(arc_cfg('content')), $before);
});
t('second save with a stale revision gets 409 and does not overwrite', function () {
    fresh_env();
    handle_content_save(U_OWNER, ['section' => 'price', 'baseRevision' => 0, 'data' => ['level' => 2, 'label' => 'First']]);
    [$st] = handle_content_save(U_OWNER, ['section' => 'price', 'baseRevision' => 0, 'data' => ['level' => 3, 'label' => 'Second']]);
    eq($st, 409);
    eq(store_read()['price']['label'], 'First');
});
t('missing baseRevision and unknown section are rejected with 400', function () {
    fresh_env();
    [$st] = handle_content_save(U_OWNER, ['section' => 'price', 'data' => ['level' => 2, 'label' => 'x']]);
    eq($st, 400);
    [$st] = handle_content_save(U_OWNER, ['section' => 'users', 'baseRevision' => 0, 'data' => []]);
    eq($st, 400);
});
t('content.get hides owner-only sections from staff', function () {
    fresh_env();
    [, $body] = handle_content_get(U_STAFF, []);
    ok(isset($body['content']['featured']) && !isset($body['content']['contact']) && !isset($body['content']['hours']));
    [, $body] = handle_content_get(U_OWNER, []);
    ok(isset($body['content']['contact']));
});
t('non-numeric baseRevision is rejected with 400', function () {
    fresh_env();
    [$st] = handle_content_save(U_OWNER, ['section' => 'price', 'baseRevision' => 'abc', 'data' => ['level' => 2, 'label' => 'x']]);
    eq($st, 400);
});
