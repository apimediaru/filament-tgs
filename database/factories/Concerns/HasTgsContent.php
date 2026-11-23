<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Database\Factories\Concerns;

use ApiMedia\Tgs\Models\Contracts\HasCanvases;
use ApiMedia\Tgs\Services\SeedTgsContent;
use ApiMedia\Tgs\Support\BlockSpec;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** @template TModel of Model */
trait HasTgsContent
{
    /** @var array<string, list<BlockSpec>> */
    private array $tgsContent = [];

    public function withTgs(string $group, BlockSpec ...$blocks): static
    {
        if ($blocks === []) {
            return $this;
        }

        $factory = $this->newInstance();
        $factory->tgsContent[$group] = [...($this->tgsContent[$group] ?? []), ...array_values($blocks)];

        return $factory;
    }

    protected function newInstance(array $arguments = [])
    {
        $factory = parent::newInstance($arguments);
        $factory->tgsContent = $this->tgsContent;

        return $factory;
    }

    /** @return Collection<int, TModel>|TModel */
    public function create($attributes = [], ?Model $parent = null)
    {
        if ($this->tgsContent === [] || ($this->count !== null && $this->count < 1)) {
            return parent::create($attributes, $parent);
        }

        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        if ($this->count === null) {
            return $this->createTgsRecord($parent);
        }

        $single = $this->count(null);
        /** @var Collection<int, TModel> $records */
        $records = $this->newModel()->newCollection();
        for ($i = 0; $i < $this->count; $i++) {
            $records->push($single->createTgsRecord($parent));
        }

        return $records;
    }

    /** @return TModel */
    private function createTgsRecord(?Model $parent): Model
    {
        $connection = DB::connection();
        $writer = app(SeedTgsContent::class);
        $writer->ensureConnection($connection);

        $this->tgsHost($connection, $this->newModel());

        return $connection->transaction(function () use ($connection, $parent, $writer): Model {
            /** @var TModel $record */
            $record = $this->make([], $parent);
            $host = $this->tgsHost($connection, $record);

            $records = new Collection([$record]);
            $this->store($records);
            if (! $record->exists) {
                throw new RuntimeException('The model could not be saved with its TGS content.');
            }

            $writer->persist($host, $this->tgsContent);
            $this->callAfterCreating($records, $parent);

            return $record;
        });
    }

    private function tgsHost(Connection $connection, Model $host): HasCanvases
    {
        if (! $host instanceof HasCanvases) {
            throw new InvalidArgumentException('A factory using withTgs() needs a model implementing HasCanvases.');
        }
        if (DB::connection($this->connection ?? $host->getConnectionName()) !== $connection || $host->getConnection() !== $connection) {
            throw new InvalidArgumentException('Factory content and its host must use the same default database connection.');
        }

        return $host;
    }
}
