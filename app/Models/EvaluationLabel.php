<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// An independent person's "correct category" for one real report (the evaluation set).
class EvaluationLabel extends Model
{
    protected $table = 'EVALUATION_LABEL';

    protected $primaryKey = 'report_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['report_id', 'category_id', 'labelled_by', 'labelled_at', 'ai_category_id', 'ai_evaluated_at'];

    protected $casts = [
        'labelled_at' => 'datetime',
        'ai_evaluated_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IssueReport::class, 'report_id', 'report_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function aiCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'ai_category_id', 'category_id');
    }
}
