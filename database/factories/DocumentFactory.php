<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\DocumentStorageKey;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only. A factory-made document has a plausible key and digest but no bytes behind
 * them, which is exactly what a row-level test wants and exactly what a download test must
 * not use: anything that needs real bytes goes through DocumentIntake with a fixture.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicId = (string) Str::ulid();
        $sha256 = hash('sha256', $publicId);
        $key = DocumentStorageKey::for((string) Str::ulid(), $publicId, RevisionKind::Original, $sha256);

        return [
            'public_id' => $publicId,
            'workspace_id' => Workspace::factory(),
            'title' => 'Synthetic '.fake()->word().' agreement',
            'uploaded_by' => User::factory(),
            'original_disk' => (string) config('esign.documents.disk', 'documents'),
            'original_path' => $key->value,
            'original_sha256' => $sha256,
            'original_bytes' => 1_482,
            'original_mime' => 'application/pdf',
            'page_count' => 1,
            'preflight_report' => ['accepted' => true, 'findings' => [], 'pages' => [], 'metrics' => []],
            'status' => DocumentStatus::Ready,
        ];
    }
}
