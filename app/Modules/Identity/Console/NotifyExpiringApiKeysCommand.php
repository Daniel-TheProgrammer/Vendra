<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Application\Actions\NotifyExpiringApiKeys;
use Illuminate\Console\Command;

final class NotifyExpiringApiKeysCommand extends Command
{
    protected $signature = 'api-keys:notify-expiring';

    protected $description = 'E-mail creators of API keys that expire soon';

    public function handle(NotifyExpiringApiKeys $notify): int
    {
        $count = $notify();

        $this->components->info(sprintf('Notified creators of %d expiring API key(s).', $count));

        return self::SUCCESS;
    }
}
