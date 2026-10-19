<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\NoModelWritesOutsideActionsRule;

/**
 * @extends RuleTestCase<NoModelWritesOutsideActionsRule>
 */
final class NoModelWritesOutsideActionsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoModelWritesOutsideActionsRule(self::createReflectionProvider());
    }

    public function test_it_reports_writes_made_from_a_controller(): void
    {
        $where = 'not in App\Modules\Projects\Http\Controllers\Web.';

        $this->analyse([__DIR__.'/data/controller_writes.php'], [
            ['Database writes (Illuminate\Database\Eloquent\Model::create) belong in a module Action, '.$where, 14],
            ['Database writes (Illuminate\Database\Eloquent\Model::save) belong in a module Action, '.$where, 15],
            ['Database writes (Illuminate\Database\Query\Builder::insert) belong in a module Action, '.$where, 16],
            ['Database writes (Illuminate\Database\Query\Builder::update) belong in a module Action, '.$where, 17],
        ]);
    }

    public function test_it_allows_writes_in_a_module_action(): void
    {
        $this->analyse([__DIR__.'/data/action_writes.php'], []);
    }

    public function test_it_allows_reads_and_unrelated_push_calls(): void
    {
        $this->analyse([__DIR__.'/data/reads.php'], []);
    }
}
