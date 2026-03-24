<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class GeneratedFile extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'processing_job_id',
        'variant',
        'file_name',
        'file_path',
        'mime_type',
    ];

    /**
     * @return BelongsTo<ProcessingJob, $this>
     */
    public function processingJob(): BelongsTo
    {
        return $this->belongsTo(ProcessingJob::class);
    }
}
