<?php

use Eloquage\Score\Score;

it('bootstraps the package entrypoint', function () {
    $instance = new Score();

    expect($instance->name())->toBe('score');
});
