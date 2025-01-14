<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Wm\WmPackage\Models\User;

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
