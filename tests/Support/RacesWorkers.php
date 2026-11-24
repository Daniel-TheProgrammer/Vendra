<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Real concurrency for tests: forked processes, each with its own database
 * connection, released together. Data must be committed for the workers to
 * see it, so a test using this must not use RefreshDatabase; call
 * prepareRace() in setUp and endRace() in tearDown, which truncates every
 * tenant-owned row.
 *
 * Requires the pcntl and posix extensions (present in the app container).
 */
trait RacesWorkers
{
    private string $raceDir;

    protected function prepareRace(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->raceDir = sys_get_temp_dir().'/vendra-race-'.bin2hex(random_bytes(6));
        mkdir($this->raceDir);
    }

    protected function endRace(): void
    {
        // TRUNCATE is not subject to RLS or append-only triggers, and CASCADE
        // clears every tenant-owned row the test committed.
        DB::statement('TRUNCATE tenants CASCADE');

        array_map(unlink(...), glob($this->raceDir.'/*') ?: []);
        rmdir($this->raceDir);
    }

    /**
     * Forks one process per worker, starts them at once and collects what each
     * returned, or the error it died with.
     *
     * @param  callable(int): string  $work
     * @return list<string>
     */
    protected function race(int $workers, callable $work): array
    {
        // Children must not share the parent's socket to the database.
        DB::disconnect();
        $start = $this->raceDir.'/start';
        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                $this->runWorker($worker, $work, $start);
            }

            $pids[] = $pid;
        }

        touch($start);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $file = $this->raceDir.'/'.$worker;
            $results[] = is_file($file) ? (string) file_get_contents($file) : 'no result from worker '.$worker;
        }

        return $results;
    }

    /**
     * @param  list<string>  $results
     * @return array<string, int>
     */
    protected function tally(array $results): array
    {
        $counts = array_count_values($results);
        ksort($counts);

        return $counts;
    }

    /**
     * Never returns. The worker ends with SIGKILL so that no PHPUnit or
     * framework shutdown code runs in the copy of the parent's process.
     *
     * @param  callable(int): string  $work
     */
    private function runWorker(int $worker, callable $work, string $start): never
    {
        try {
            DB::purge();

            while (! is_file($start)) {
                usleep(1_000);
            }

            $result = $work($worker);
        } catch (Throwable $exception) {
            $result = sprintf('error in worker %d: %s: %s', $worker, $exception::class, $exception->getMessage());
        }

        file_put_contents($this->raceDir.'/'.$worker, $result);
        DB::disconnect();
        posix_kill((int) getmypid(), SIGKILL);

        exit(1);
    }
}
