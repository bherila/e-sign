<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Templates\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The template row only. It has no versions, so it is what a row-level or authorization test
 * wants; anything that needs a version goes through
 * App\Domain\Preparation\Templates\TemplateService, which is the only thing that may create
 * one.
 *
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'workspace_id' => Workspace::factory(),
            'name' => 'Synthetic '.fake()->word().' template',
            'description' => null,
            'current_version_id' => null,
            'retired_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['retired_at' => now()]);
    }
}
