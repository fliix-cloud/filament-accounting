<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Support\UsesPackageConnection;
use Illuminate\Database\Eloquent\Model;

abstract class AccountingModel extends Model
{
    use UsesPackageConnection;
}
