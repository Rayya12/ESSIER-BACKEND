<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['study_document_id', 'study_id', 'chunk_index', 'content'])]
class StudyChunk extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'study_chunks';

    protected $keyType = 'string';
    public $incrementing = false;

    public function study(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Study::class);
    }

    public function document(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StudyDocument::class, 'study_document_id');
    }
}