<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
    ];

    /**
     * @return HasMany<ProcessingJob, $this>
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class);
    }
}
