<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Data\EnvironmentDefinition;
use App\Modules\Environments\Domain\EnvironmentColor;
use App\Modules\Environments\Domain\EnvironmentTemplate;
use Illuminate\Validation\ValidationException;

/**
 * The environments a new project starts with, and the check that an edited list is acceptable.
 */
final class EnvironmentTemplates
{
    /**
     * @return list<EnvironmentDefinition>
     */
    public function default(): array
    {
        return array_map(
            static fn (array $environment): EnvironmentDefinition => new EnvironmentDefinition($environment['name'], $environment['key'], $environment['color']->value),
            EnvironmentTemplate::default(),
        );
    }

    /**
     * The list to create, checked: one to ten environments, valid names and keys, no repeated key. Null means the template.
     *
     * @param  list<array{name: string, key: string, color?: string|null}>|null  $given
     * @return list<EnvironmentDefinition>
     *
     * @throws ValidationException
     */
    public function normalize(?array $given): array
    {
        if ($given === null) {
            return $this->default();
        }

        if ($given === [] || count($given) > EnvironmentTemplate::MAX_ENVIRONMENTS) {
            throw ValidationException::withMessages(['environments' => 'Use 1 to '.EnvironmentTemplate::MAX_ENVIRONMENTS.' environments.']);
        }

        $seen = [];
        $clean = [];

        foreach ($given as $index => $environment) {
            $name = trim($environment['name']);
            $key = strtolower(trim($environment['key']));

            if ($name === '' || mb_strlen($name) > 40) {
                throw ValidationException::withMessages(["environments.{$index}.name" => 'Enter a name of up to 40 characters.']);
            }

            if (preg_match(EnvironmentTemplate::KEY_PATTERN, $key) !== 1) {
                throw ValidationException::withMessages(["environments.{$index}.key" => 'Start with a letter. Use a–z, 0–9 and -']);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["environments.{$index}.key" => 'Already used in this project']);
            }

            $seen[$key] = true;
            $color = EnvironmentColor::tryFrom((string) ($environment['color'] ?? '')) ?? EnvironmentTemplate::colorFor($key);
            $clean[] = new EnvironmentDefinition($name, $key, $color->value);
        }

        return $clean;
    }
}
