<?php

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Services\AdoptTgsSchema;
use Illuminate\Console\Command;
use LogicException;

class AdoptTgsSchemaCommand extends Command
{
    protected $signature = 'tgs:adopt-existing';

    protected $description = 'Validate an existing TGS schema and record its fresh migration baseline';

    public function handle(AdoptTgsSchema $adoption): int
    {
        try {
            $created = $adoption->run();
            $this->info($created ? 'Existing TGS schema adopted.' : 'TGS schema was already adopted.');

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
