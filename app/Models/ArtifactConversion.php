<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ArtifactConversion Model
 *
 * Tracks async pandoc conversions of artifacts to various formats.
 * Stores conversion status, generated assets, and error information.
 *
 * @property int $id
 * @property int $artifact_id
 * @property int|null $asset_id Generated asset after conversion
 * @property int $created_by User who requested conversion
 * @property string $output_format (pdf, docx, odt, latex)
 * @property string|null $template (eisvogel, elegant, academic)
 * @property string $status (pending, processing, completed, failed)
 * @property string|null $error_message
 * @property int|null $file_size Size in bytes
 */
class ArtifactConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'artifact_id',
        'asset_id',
        'created_by',
        'output_format',
        'template',
        'status',
        'error_message',
        'file_size',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(int $assetId, int $fileSize): void
    {
        $this->update([
            'status' => 'completed',
            'asset_id' => $assetId,
            'file_size' => $fileSize,
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->file_size) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->file_size;
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2).' '.$units[$unitIndex];
    }
}
