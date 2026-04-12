<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class TestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
