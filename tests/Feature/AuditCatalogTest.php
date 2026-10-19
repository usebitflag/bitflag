<?php

declare(strict_types=1);

use App\Shared\Audit\AuditCatalog;

it('is a shared singleton', function () {
    expect(app(AuditCatalog::class))->toBe(app(AuditCatalog::class));
});

it('accepts object.verb names and lists them sorted', function () {
    $catalog = new AuditCatalog;
    $catalog->register('revision.published', 'flag.created');

    expect($catalog->has('flag.created'))->toBeTrue()
        ->and($catalog->has('flag.deleted'))->toBeFalse()
        ->and($catalog->all())->toBe(['flag.created', 'revision.published']);
});

it('rejects a name that is not object.verb', function (string $name) {
    (new AuditCatalog)->register($name);
})->with(['flagCreated', 'flag.', '.created', 'Flag.created', 'flag.created.again', 'flag-created'])
    ->throws(InvalidArgumentException::class);
