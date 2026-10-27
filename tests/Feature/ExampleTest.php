<?php

test('the home page sends to the archive while no election is registered', function () {
    $this->get(route('home'))->assertRedirect(route('elections.index'));

    $this->get(route('elections.index'))->assertOk();
});
