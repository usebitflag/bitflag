<?php

declare(strict_types=1);

use App\Shared\Errors\ResourceNotFound;
use App\Shared\Ids\HasPublicId;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

const SAMPLE_ULID = '01j9x3k7q2m5n8p0r4s6t8v0wy';

it('encodes a ULID with the type prefix', function () {
    expect(PublicId::encode(IdPrefix::Project, SAMPLE_ULID))->toBe('prj_'.SAMPLE_ULID);
});

it('decodes a public ID of the expected type', function () {
    expect(PublicId::decode(IdPrefix::Project, 'prj_'.SAMPLE_ULID))->toBe(SAMPLE_ULID);
});

it('treats a wrong prefix or a malformed ULID as not found', function (string $publicId) {
    PublicId::decode(IdPrefix::Project, $publicId);
})->with([
    'wrong type' => 'env_'.SAMPLE_ULID,
    'no prefix' => SAMPLE_ULID,
    'empty' => '',
    'too short' => 'prj_01j9x3k7q2',
    'too long' => 'prj_'.SAMPLE_ULID.'0',
    'uppercase' => 'prj_'.'01J9X3K7Q2M5N8P0R4S6T8V0WY',
    'excluded letters' => 'prj_01j9x3k7q2m5n8p0r4s6t8v0wi',
    'overflowing first character' => 'prj_81j9x3k7q2m5n8p0r4s6t8v0wy',
])->throws(ResourceNotFound::class);

it('reports whether a value is a well-formed public ID', function () {
    expect(PublicId::isValid(IdPrefix::Project, 'prj_'.SAMPLE_ULID))->toBeTrue()
        ->and(PublicId::isValid(IdPrefix::Project, 'nope'))->toBeFalse();
});

it('refuses to encode something that is not a ULID', function () {
    PublicId::encode(IdPrefix::Project, '12345');
})->throws(InvalidArgumentException::class);

it('uses a unique prefix for every type', function () {
    $values = array_map(static fn (IdPrefix $prefix): string => $prefix->value, IdPrefix::cases());

    expect($values)->toBe(array_values(array_unique($values)));
    foreach ($values as $value) {
        expect($value)->toMatch('/^[a-z]{3,4}_$/');
    }
});

it('accepts the ULIDs Laravel generates', function () {
    $ulid = (new class extends Model
    {
        use HasUlids;
    })->newUniqueId();

    expect(PublicId::isValid(IdPrefix::Flag, 'flg_'.$ulid))->toBeTrue();
});

describe('the HasPublicId trait', function () {
    function probeModel(): Model
    {
        return new class extends Model
        {
            use HasPublicId, HasUlids;

            protected $table = 'public_id_probes';

            protected $guarded = [];

            public $timestamps = false;

            public static function publicIdPrefix(): IdPrefix
            {
                return IdPrefix::Project;
            }
        };
    }

    it('exposes the public ID of a stored record', function () {
        $probe = probeModel()->newQuery()->create(['slug' => 'storefront']);

        expect($probe->public_id)->toBe('prj_'.$probe->getKey());
    });

    it('finds a record by its public ID', function () {
        $model = probeModel();
        $probe = $model->newQuery()->create(['slug' => 'storefront']);

        expect($model->newQuery()->wherePublicId($probe->public_id)->first()?->getKey())->toBe($probe->getKey());
    });

    it('resolves route bindings by public ID and by slug', function () {
        $model = probeModel();
        $probe = $model->newQuery()->create(['slug' => 'storefront']);

        expect($model->resolveRouteBinding($probe->public_id)?->getKey())->toBe($probe->getKey())
            ->and($model->resolveRouteBinding('storefront', 'slug')?->getKey())->toBe($probe->getKey())
            ->and($model->resolveRouteBinding('prj_'.(string) Str::lower((string) Str::ulid())))->toBeNull();
    });

    it('treats a public ID of another type as not found', function () {
        $model = probeModel();
        $probe = $model->newQuery()->create(['slug' => 'storefront']);

        $model->resolveRouteBinding('env_'.$probe->getKey());
    })->throws(ResourceNotFound::class);
});
