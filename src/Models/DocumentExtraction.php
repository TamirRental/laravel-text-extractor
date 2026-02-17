<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use TamirRental\DocumentExtraction\Database\Factories\DocumentExtractionFactory;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;

class DocumentExtraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'filename',
        'identifier',
        'extracted_data',
        'metadata',
        'status',
        'error_message',
        'external_task_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_data' => 'object',
            'metadata' => 'array',
            'status' => DocumentExtractionStatusEnum::class,
        ];
    }

    protected static function newFactory(): DocumentExtractionFactory
    {
        return DocumentExtractionFactory::new();
    }

    /**
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentExtractionStatusEnum::Pending);
    }

    /**
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DocumentExtractionStatusEnum::Completed);
    }

    /**
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DocumentExtractionStatusEnum::Failed);
    }

    /**
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopeForFile(Builder $query, string $filename): Builder
    {
        return $query->where('filename', $filename);
    }
}
