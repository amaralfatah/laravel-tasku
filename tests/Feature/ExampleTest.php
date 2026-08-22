<?php

test('the root path requires signing in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});
