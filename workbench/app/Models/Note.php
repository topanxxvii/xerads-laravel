<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/** A model with no slug column at all, whose route still takes a slug. */
class Note extends Model
{
    protected $table = 'notes';

    protected $guarded = [];
}
