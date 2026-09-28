<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'gateway', 'token', 'masked_pan', 'brand', 'expiry', 'is_default'])]
#[Hidden(['token'])]
class PaymentMethod extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'is_default' => 'boolean'];
    }
}
