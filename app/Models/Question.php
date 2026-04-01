<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

#[Fillable(["question_text","ideal_answer","ideal_embedding", "study_id","order_index"])]
class Question extends Model
{
    use HasFactory;
    use HasUuids;

    # The table associated with the model.
    protected $table = "questions";

    #uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function study(){
        return $this->belongsTo(Study::class);
    }
}
