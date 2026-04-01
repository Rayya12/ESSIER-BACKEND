<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

#[Fillable(["user_id",'title', 'status'])]
class Study extends Model
{
    use HasFactory;
    use HasUuids;

    # The table associated with the model.
    protected $table = "studies";

    # uuid
    protected $keyType = 'string';
    public $incrementing = false;

    public function user(){
        return $this->belongsTo(User::class);
    }
}
