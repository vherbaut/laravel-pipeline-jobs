<?php

declare(strict_types=1);

arch('all source files use strict types')
    ->expect('Vherbaut\LaravelPipelineJobs')
    ->toUseStrictTypes();

arch('no debug functions in source')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('exceptions extend PipelineException')
    ->expect('Vherbaut\LaravelPipelineJobs\Exceptions')
    ->toExtend('Vherbaut\LaravelPipelineJobs\Exceptions\PipelineException')
    ->ignoring('Vherbaut\LaravelPipelineJobs\Exceptions\PipelineException');

arch('pipeline event classes are final')
    ->expect('Vherbaut\LaravelPipelineJobs\Events')
    ->toBeFinal();
