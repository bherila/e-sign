<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Trait for models that represent uploaded files.
 * Provides common functionality for download tracking and file metadata.
 */
trait HasFileStorage
{
    /**
     * Get the casts array for file models.
     */
    protected static function getFileCasts(): array
    {
        return [
            'download_history' => 'array',
            'file_size_bytes' => 'integer',
        ];
    }

    /**
     * Record a download event in the download history.
     */
    public function recordDownload(?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();
        $history = $this->download_history ?? [];

        $history[] = [
            'user_id' => $userId,
            'downloaded_at' => now()->toIso8601String(),
        ];

        $this->download_history = $history;
        $this->save();
    }

    /**
     * Get the file size in a human-readable format.
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size_bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Get the download count.
     */
    public function getDownloadCountAttribute(): int
    {
        return count($this->download_history ?? []);
    }

    /**
     * Get the stored filename with date prefix.
     * Format: "yyyy.mm.dd original_filename"
     */
    public static function generateStoredFilename(string $originalFilename): string
    {
        $datePart = now()->format('Y.m.d');

        return $datePart.' '.$originalFilename;
    }

    /**
     * Get the uploader relationship.
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
