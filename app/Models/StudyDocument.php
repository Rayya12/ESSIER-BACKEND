<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

#[Fillable(["study_id", "file_path", "file_name","extracted_text"])]
class StudyDocument extends Model
{
    use HasFactory;
    use HasUuids;

    # The table associated with the model.
    protected $table = "study_documents";

    #uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function study(){
        return $this->belongsTo(Study::class);
    }
}
