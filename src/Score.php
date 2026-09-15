<?php

namespace Eloquage\Score;

/**
 * Primary entrypoint for eloquage/score.
 *
 * Pure-PHP implementation lives here. Optional TypePHP/native acceleration
 * can be added under native/ later without changing this public API.
 */
final class Score
{
    public function name(): string
    {
        return 'score';
    }
}
