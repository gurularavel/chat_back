<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A request left through the contact form on the public site; handled in the superadmin panel. */
#[Fillable(['first_name', 'last_name', 'email', 'country', 'phone', 'locale', 'ip', 'handled_at'])]
class ContactRequest extends Model
{
    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
