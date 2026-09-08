<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Service for handling file storage operations with S3.
 * Provides upload, download, and deletion capabilities with signed URLs.
 *
 * Validation: every upload endpoint feeding this service must enforce a strict
 * server-side MIME allowlist in its Form Request — frontend `accept` lists do not
 * gate what reaches storage. For image uploads intended for inline <img> previews,
 * allow only browser-decodable types (never a bare `image` rule, which admits
 * HEIC/HEIF/TIFF that render broken outside Safari):
 *
 *     public function rules(): array
 *     {
 *         return [
 *             'file' => [
 *                 'required',
 *                 'file',
 *                 'max:10240',
 *                 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/avif',
 *             ],
 *         ];
 *     }
 *
 * The matching frontend gate is isBrowserRenderableImage() in
 * resources/js/lib/isBrowserRenderableImage.ts.
 */
class FileStorageService
{
    /**
     * The S3 disk name.
     */
    protected string $disk = 's3';

    /**
     * Maximum file size that can be uploaded directly (50MB).
     * Files larger than this should use signed URL upload.
     */
    public const DIRECT_UPLOAD_MAX_SIZE = 50 * 1024 * 1024;

    /**
     * Get the S3 disk instance.
     */
    protected function storage()
    {
        return Storage::disk($this->disk);
    }

    /**
     * Upload a file directly to S3.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  string  $s3Path  The destination path in S3
     * @return bool Whether the upload was successful
     */
    public function uploadFile(UploadedFile $file, string $s3Path): bool
    {
        return $this->storage()->putFileAs(
            dirname($s3Path),
            $file,
            basename($s3Path)
        ) !== false;
    }

    /**
     * Upload file content directly to S3.
     *
     * @param  string  $content  The file content
     * @param  string  $s3Path  The destination path in S3
     * @return bool Whether the upload was successful
     */
    public function uploadContent(string $content, string $s3Path): bool
    {
        return $this->storage()->put($s3Path, $content);
    }

    /**
     * Generate a signed URL for uploading a large file directly to S3.
     *
     * @param  string  $s3Path  The destination path in S3
     * @param  string  $contentType  The MIME type of the file
     * @param  int  $expiration  URL expiration time in minutes
     * @return string The signed upload URL
     */
    public function getSignedUploadUrl(string $s3Path, string $contentType, int $expiration = 60): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        if (empty($bucket)) {
            throw new \RuntimeException('S3 Bucket is not configured in filesystems.disks.s3.bucket');
        }

        return $this->storage()->temporaryUploadUrl(
            $s3Path,
            now()->addMinutes($expiration),
            [
                'Bucket' => $bucket,
                'ContentType' => $contentType,
            ]
        );
    }

    /**
     * Generate a signed URL for downloading a file from S3.
     *
     * @param  string  $s3Path  The file path in S3
     * @param  string  $downloadFilename  The filename to use for the download
     * @param  int  $expiration  URL expiration time in minutes
     * @return string The signed download URL
     */
    public function getSignedDownloadUrl(string $s3Path, string $downloadFilename, int $expiration = 60): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        if (empty($bucket)) {
            throw new \RuntimeException('S3 Bucket is not configured in filesystems.disks.s3.bucket');
        }

        return $this->storage()->temporaryUrl(
            $s3Path,
            now()->addMinutes($expiration),
            [
                'Bucket' => $bucket,
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($downloadFilename).'"',
            ]
        );
    }

    /**
     * Delete a file from S3.
     *
     * @param  string  $s3Path  The file path in S3
     * @return bool Whether the deletion was successful
     */
    public function deleteFile(string $s3Path): bool
    {
        return $this->storage()->delete($s3Path);
    }

    /**
     * Check if a file exists in S3.
     *
     * @param  string  $s3Path  The file path in S3
     * @return bool Whether the file exists
     */
    public function fileExists(string $s3Path): bool
    {
        return $this->storage()->exists($s3Path);
    }

    /**
     * Get file size from S3.
     *
     * @param  string  $s3Path  The file path in S3
     * @return int|null The file size in bytes, or null if file doesn't exist
     */
    public function getFileSize(string $s3Path): ?int
    {
        try {
            return $this->storage()->size($s3Path);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a file record and upload the file.
     *
     * @param  Model  $fileModel  The file model instance (not yet saved)
     * @param  UploadedFile  $file  The uploaded file
     * @return Model The saved file model
     */
    public function createFileRecord(Model $fileModel, UploadedFile $file): Model
    {
        // Upload to S3
        $uploaded = $this->uploadFile($file, $fileModel->s3_path);

        if (! $uploaded) {
            throw new \RuntimeException('Failed to upload file to S3');
        }

        // Save the model
        $fileModel->save();

        return $fileModel;
    }

    /**
     * Delete a file record and its S3 file.
     *
     * @param  Model  $fileModel  The file model to delete
     * @param  bool  $forceDelete  Whether to hard delete (default: soft delete)
     * @return bool Whether the deletion was successful
     */
    public function deleteFileRecord(Model $fileModel, bool $forceDelete = false): bool
    {
        // Delete from S3
        $this->deleteFile($fileModel->s3_path);

        // Delete the record
        if ($forceDelete) {
            return $fileModel->forceDelete();
        }

        return $fileModel->delete();
    }
}
