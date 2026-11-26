<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Event\DomainEvent;
use LogicException;
use UnexpectedValueException;

/**
 * Event name ↔ class, so the relay can rebuild a stored event. Each module
 * lists its events (ModuleServiceProvider::moduleEvents()); every event
 * class declares its name as the NAME constant.
 */
final class EventRegistry
{
    /** @var array<string, class-string<DomainEvent>> */
    private array $classes = [];

    /**
     * @param  class-string<DomainEvent>  ...$classes
     */
    public function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            $name = self::nameOf($class);

            if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
                throw new LogicException(sprintf('Event name "%s" is used by both %s and %s.', $name, $this->classes[$name], $class));
            }

            $this->classes[$name] = $class;
        }
    }

    /**
     * @return class-string<DomainEvent>
     *
     * @throws UnexpectedValueException
     */
    public function classOf(string $name): string
    {
        return $this->classes[$name] ?? throw new UnexpectedValueException(sprintf('No event is registered as "%s".', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /**
     * @return array<string, class-string<DomainEvent>>
     */
    public function all(): array
    {
        ksort($this->classes);

        return $this->classes;
    }

    /**
     * @param  class-string<DomainEvent>  $class
     */
    public static function nameOf(string $class): string
    {
        $constant = $class.'::NAME';
        $name = defined($constant) ? constant($constant) : null;

        return is_string($name) && $name !== '' ? $name : throw new LogicException(sprintf('%s must declare its event name as the NAME constant.', $class));
    }
}
