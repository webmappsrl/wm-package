<?php

namespace Wm\WmPackage\Models\Abstracts;

use Wm\WmPackage\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;

abstract class Track extends GeometryModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sku',
        'name',
        'description',
        'geometry',
    ];


    /**
     * Alias for the user relation
     */
    public function author()
    {
        return $this->user();
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
