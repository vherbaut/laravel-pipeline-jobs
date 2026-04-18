<?php

declare(strict_types=1);

namespace Vherbaut\LaravelPipelineJobs\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in Testbench harness that provisions a real `database` queue driver
 * and the Laravel-native `job_batches` table so tests can drive genuine
 * Bus::batch() lifecycles (fan-out → sub-steps run → `then` / `catch`
 * callbacks fire) end-to-end.
 *
 * The harness is deliberately NOT the default base class: running the
 * inline migrations + sqlite setup on every test would pay an unnecessary
 * cost on the 600+ fast-path tests that rely on Bus::fake() or the sync
 * queue driver. Opt in at the file level via
 * `uses(IntegrationTestCase::class)->in('Feature/Integration')` at the top
 * of each integration test file.
 *
 * The `job_batches` / `jobs` / `failed_jobs` schemas are reconstructed
 * inline from Laravel's native stubs (see
 * vendor/laravel/framework/src/Illuminate/Queue/Console/stubs/batches.stub,
 * jobs.stub, failed_jobs.stub) so the package does not ship a migration
 * file that would pollute consumer projects (NFR17 spirit: zero extra
 * dependencies, zero migration-file footprint).
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Configure the Testbench application environment for real-batch integration runs.
     *
     * Registers a sqlite in-memory connection, wires the `database` queue
     * driver against that connection, points Laravel's batch repository at
     * the same connection + the conventional `job_batches` table, and forces
     * the cache driver to `array` so ParallelStepJob's per-sub-step context
     * persistence stays in-process.
     *
     * @param Application $app The Testbench application instance provided by the harness.
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);

        $app['config']->set('queue.batching', [
            'database' => 'testing',
            'table' => 'job_batches',
        ]);

        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);

        $app['config']->set('cache.default', 'array');
    }

    /**
     * Provision the `jobs`, `failed_jobs`, and `job_batches` tables required by the `database` queue driver.
     *
     * Mirrors Laravel's built-in stubs inline so the package carries no
     * migration files (the integration suite is opt-in and must not leak
     * schema into consumer migrations directories). Called by Testbench's
     * template-method hook during test bootstrap.
     *
     * @return void
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    /**
     * Drain the database queue by looping `queue:work --once --stop-when-empty` until idle.
     *
     * Loops against the `jobs` table only — intentionally NOT against the
     * `job_batches.finished_at` column. Laravel's batch lifecycle only sets
     * finished_at when `pending_jobs` hits zero, and pending_jobs is only
     * decremented on successful jobs (recordFailedJob never decrements), so
     * a batch that experiences any failure under `->allowFailures()` will
     * leave its finished_at as null indefinitely. Waiting for finished_at
     * would hang the harness on every SkipAndContinue / StopImmediately /
     * StopAndCompensate test even though the pipeline has otherwise made
     * progress.
     *
     * Each worker pass processes at most one job (or exits immediately when
     * the queue is empty). The `finally` callback registered by
     * PipelineStepJob::dispatchParallelBatch() fires synchronously inside
     * the worker that processes the last sub-step and dispatches the next
     * PipelineStepJob, so new jobs land in the `jobs` table BEFORE the
     * artisan call returns. The outer loop picks them up on the next pass.
     *
     * The iteration cap guards against runaway drains if a test produces a
     * stuck job (e.g. a sub-step that throws from condition evaluation
     * rather than from handle): the harness fails loudly rather than
     * hanging CI.
     *
     * @param int $maxIterations Upper bound on worker passes before aborting with a PHPUnit failure.
     * @return void
     */
    protected function drainQueue(int $maxIterations = 100): void
    {
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $pendingJobs = DB::table('jobs')->count();

            if ($pendingJobs === 0) {
                return;
            }

            $this->artisan('queue:work', [
                '--once' => true,
                '--stop-when-empty' => true,
            ]);

            $iteration++;
        }

        self::fail(sprintf(
            'drainQueue exceeded %d iterations with %d pending jobs still present.',
            $maxIterations,
            DB::table('jobs')->count(),
        ));
    }
}
