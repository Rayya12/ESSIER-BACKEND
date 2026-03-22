<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(["content", "study_id"])]
class Mnemonic extends Model
{
    use HasFactory;

    # The table associated with the model.
    protected $table = "mnemonics";

    #uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function study(){
        return $this->belongsTo(Study::class);
    }
}
