<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InventoryTaskEvidence extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_task_evidence';

    protected $fillable = [
        'task_id',
        'uploaded_by',
        'evidence_type',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'title',
        'description',
        'metadata',
        'latitude',
        'longitude',
        'captured_at',
        'is_approved',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'captured_at' => 'datetime',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'file_size' => 'integer',
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(InventoryTask::class, 'task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'approved_by');
    }

    // Scopes
    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('evidence_type', $type);
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeByUploader($query, $userId)
    {
        return $query->where('uploaded_by', $userId);
    }

    public function scopeImages($query)
    {
        return $query->where('evidence_type', 'photo')
                    ->where('mime_type', 'like', 'image/%');
    }

    public function scopeDocuments($query)
    {
        return $query->whereIn('evidence_type', ['document', 'signature'])
                    ->where('mime_type', 'not like', 'image/%');
    }

    public function scopeAudio($query)
    {
        return $query->where('evidence_type', 'audio')
                    ->where('mime_type', 'like', 'audio/%');
    }

    public function scopeVideo($query)
    {
        return $query->where('evidence_type', 'video')
                    ->where('mime_type', 'like', 'video/%');
    }

    // Helper methods
    public function getFileUrl(): string
    {
        return Storage::url($this->file_path);
    }

    public function getFileSizeFormatted(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->file_size;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function hasLocation(): bool
    {
        return !is_null($this->latitude) && !is_null($this->longitude);
    }

    public function getLocationString(): string
    {
        if (!$this->hasLocation()) {
            return 'No location data';
        }

        return "{$this->latitude}, {$this->longitude}";
    }

    public function approve(int $approverId): bool
    {
        if ($this->is_approved) {
            return false;
        }

        return $this->update([
            'is_approved' => true,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    public function isImage(): bool
    {
        return $this->evidence_type === 'photo' && str_starts_with($this->mime_type, 'image/');
    }

    public function isDocument(): bool
    {
        return in_array($this->evidence_type, ['document', 'signature']) &&
               !str_starts_with($this->mime_type, 'image/');
    }

    public function isAudio(): bool
    {
        return $this->evidence_type === 'audio' && str_starts_with($this->mime_type, 'audio/');
    }

    public function isVideo(): bool
    {
        return $this->evidence_type === 'video' && str_starts_with($this->mime_type, 'video/');
    }

    // Evidence type constants
    const TYPE_PHOTO = 'photo';
    const TYPE_DOCUMENT = 'document';
    const TYPE_SIGNATURE = 'signature';
    const TYPE_AUDIO = 'audio';
    const TYPE_VIDEO = 'video';

    public static function getEvidenceTypes(): array
    {
        return [
            self::TYPE_PHOTO,
            self::TYPE_DOCUMENT,
            self::TYPE_SIGNATURE,
            self::TYPE_AUDIO,
            self::TYPE_VIDEO,
        ];
    }

    public static function getAllowedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'audio/mpeg',
            'audio/wav',
            'video/mp4',
            'video/avi',
            'video/quicktime',
        ];
    }
}