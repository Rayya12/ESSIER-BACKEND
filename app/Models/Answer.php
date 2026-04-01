<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;


#[Fillable(["attempt_id", "study_id", "answer_text", "answer_embedding", "score"])]
class Answer extends Model
{
    use HasFactory;
    use HasUuids;

    # The table associated with the model.
    protected $table = "answers";

    #uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function quizAttempt(){
        return $this->belongsTo(Quiz_attempt::class, 'attempt_id');
    }

    public function study(){
        return $this->belongsTo(Study::class);
    }
}
