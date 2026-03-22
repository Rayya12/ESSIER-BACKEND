<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(["study_id", "total_score", "started_at","submitted_at"])]
class Quiz_attempt extends Model
{
    use HasFactory;

    # The table associated with the model.
    protected $table = "quiz_attempts";

    #uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function study(){
        return $this->belongsTo(Study::class);
    }
}
