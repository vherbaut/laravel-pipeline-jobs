<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Exceptions\ContextSerializationFailed;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\NonSerializableContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\OrderContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Contexts\SimpleContext;
use Vherbaut\LaravelPipelineJobs\Tests\Fixtures\Models\TestUser;

it('serializes and restores Eloquent models correctly', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $user = TestUser::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $context = new OrderContext;
    $context->orderId = 'ORD-123';
    $context->user = $user;

    /** @var OrderContext $restored */
    $restored = unserialize(serialize($context));

    expect($restored->orderId)->toBe('ORD-123')
        ->and($restored->user)->toBeInstanceOf(TestUser::class)
        ->and($restored->user->id)->toBe($user->id)
        ->and($restored->user->name)->toBe('Jane Doe')
        ->and($restored->user->email)->toBe('jane@example.com');
});

it('throws ContextSerializationFailed for Closure properties', function () {
    $context = new NonSerializableContext;
    $context->callback = fn () => 'test';

    $context->validateSerializable();
})->throws(ContextSerializationFailed::class);

it('throws ContextSerializationFailed for resource properties', function () {
    $context = new class extends PipelineContext
    {
        /** @var mixed */
        public $handle = null;
    };

    $context->handle = fopen('php://memory', 'r');

    try {
        $context->validateSerializable();
    } finally {
        if (is_resource($context->handle)) {
            fclose($context->handle);
        }
    }
})->throws(ContextSerializationFailed::class);

it('throws ContextSerializationFailed for anonymous class properties', function () {
    $context = new class extends PipelineContext
    {
        public ?object $service = null;
    };

    $context->service = new class {};

    $context->validateSerializable();
})->throws(ContextSerializationFailed::class, 'Anonymous classes are not serializable');

it('identifies the problematic property name in the exception message', function () {
    $context = new NonSerializableContext;
    $context->callback = fn () => 'test';

    try {
        $context->validateSerializable();
    } catch (ContextSerializationFailed $e) {
        expect($e->getMessage())
            ->toContain('callback')
            ->toContain(NonSerializableContext::class)
            ->toContain('not serializable');

        return;
    }

    $this->fail('Expected ContextSerializationFailed was not thrown');
});

it('passes validateSerializable for contexts with only scalar properties', function () {
    $context = new SimpleContext;
    $context->name = 'test';
    $context->count = 5;
    $context->active = true;
    $context->tags = ['a', 'b'];

    $context->validateSerializable();

    expect(true)->toBeTrue();
});

it('passes validateSerializable for contexts with Eloquent model properties', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $user = TestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $context = new OrderContext;
    $context->orderId = 'ORD-456';
    $context->user = $user;

    $context->validateSerializable();

    expect(true)->toBeTrue();
});
