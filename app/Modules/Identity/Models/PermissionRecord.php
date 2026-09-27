<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Domain\DomainModel;

final class PermissionRecord extends DomainModel
{
    protected $table = 'permissions';

    protected $fillable = [
        'code',
        'resource',
        'action',
        'description',
    ];
}
