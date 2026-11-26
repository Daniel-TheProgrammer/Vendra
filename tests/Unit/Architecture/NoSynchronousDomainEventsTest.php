<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Phase 7's rule (ADR 0010): domain events go through the outbox, recorded
 * with EventRecorder in the transaction that causes them. Module code never
 * dispatches on the event bus itself; only the relay does. Listener
 * registration (Event::listen in service providers) is fine.
 */
final class NoSynchronousDomainEventsTest extends TestCase
{
    private const FORBIDDEN = [
        '/use Illuminate\\\\Contracts\\\\Events\\\\Dispatcher;/' => 'the event Dispatcher',
        '/(?<![\w>:$])event\s*\(\s*new\b/' => 'the event() helper',
        '/(?<![\w\\\\])Event::dispatch\s*\(/' => 'Event::dispatch()',
        '/->dispatch\s*\(\s*new\s+\w+(?:Placed|Paid|Cancelled|Fulfilled|Refunded|Created|Updated|Archived|Changed|Adjusted|Captured|Failed|Disputed|Issued)\s*\(/' => 'dispatching a domain event',
    ];

    #[Test]
    public function module_code_never_dispatches_a_domain_event_directly(): void
    {
        $offences = [];
        $root = dirname(__DIR__, 3).'/app/Modules';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN as $pattern => $what) {
                if (preg_match($pattern, $source) === 1) {
                    $offences[] = sprintf('%s uses %s', substr($file->getPathname(), strlen($root) + 1), $what);
                }
            }
        }

        self::assertSame([], $offences, 'Record domain events with EventRecorder instead (ADR 0010).');
    }
}
