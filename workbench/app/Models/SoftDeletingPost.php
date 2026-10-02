<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** The same posts table, for a site whose post model soft-deletes. */
class SoftDeletingPost extends Post
{
    use SoftDeletes;
}
