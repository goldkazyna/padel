<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Группа контактов клуба: персонал, поставщики и что заведут сами. */
class ContactGroup extends Model
{
    protected $fillable = ['club_id', 'name', 'sort_order'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }
}
