<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Models\Contracts\HasCanvases;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\Media\SeedBlockFileAdder;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @internal
 *
 * @phpstan-type SeedMedia array{blockId: string, disk: string, uuid: string, files: list<string>}
 */
final class SeedTgsContent
{
    public function __construct(
        private CanvasPersistence $persistence,
        private TgsBlockRegistry $registry,
        private TgsInsertionPolicy $policy,
    ) {}

    public function ensureConnection(Connection $connection): void
    {
        foreach ([TgsModels::canvas(), TgsModels::block(), TgsModels::placement(), TgsModels::block_media()] as $class) {
            if ((new $class)->getConnection() !== $connection) {
                throw new InvalidArgumentException('Factory content and its host must use the same default database connection.');
            }
        }
    }

    /** @param array<string, list<BlockSpec>> $groups */
    public function persist(HasCanvases $host, array $groups): void
    {
        $trees = [];
        $media = [];
        foreach ($groups as $group => $specs) {
            $descriptor = $host->canvasDescriptor($group);
            $trees[$group] = [$descriptor, $this->buildTree($descriptor, $specs, $media)];
        }

        foreach ($trees as $group => [$descriptor, $tree]) {
            if ($tree !== []) {
                $host->canvas($group);
                $this->persistence->replaceTree($descriptor, $tree);
            }
        }

        foreach ($media as $attachment) {
            $block = TgsModels::block()::query()->findOrFail($attachment['blockId']);
            foreach ($attachment['files'] as $index => $file) {
                app(SeedBlockFileAdder::class)
                    ->setSubject($block)
                    ->setFile($file)
                    ->setOrder($index + 1)
                    ->preservingOriginal()
                    ->withCustomProperties(['uuid' => $attachment['uuid']])
                    ->toMediaCollection($block::getContentMediaCollectionName(), $attachment['disk']);
            }
        }
    }

    /**
     * @param  list<BlockSpec>  $specs
     * @param  list<SeedMedia>  $media
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function buildTree(HostDescriptor $host, array $specs, array &$media, ?string $parent = null, ?string $slot = null): array
    {
        $tree = [];
        foreach ($specs as $spec) {
            for ($i = 0; $i < $spec->quantity; $i++) {
                $tree[] = ['type' => $spec->type, 'data' => $this->buildNode($host, $spec, $media, $parent, $slot)];
            }
        }

        return $tree;
    }

    /**
     * @param  list<SeedMedia>  $media
     * @return array<string, mixed>
     */
    private function buildNode(HostDescriptor $host, BlockSpec $spec, array &$media, ?string $parent, ?string $slot): array
    {
        $block = $this->registry->get($spec->type);
        if ($block === null || ! $this->policy->allows($spec->type, $parent, $slot, $host)) {
            throw new InvalidArgumentException(sprintf(
                'Block [%s] cannot be placed in canvas [%s] at [%s].',
                $spec->type,
                $host->slot,
                $parent === null ? 'root' : "{$parent}.{$slot}",
            ));
        }

        $data = $spec->data();
        $this->persistence->assertSeedFields($data);
        $allowedSlots = $block->blockSlots();
        foreach ($spec->slots as $name => $children) {
            if (! in_array($name, $allowedSlots, true)) {
                throw new InvalidArgumentException("Unknown slot [{$name}] on block [{$spec->type}].");
            }
            if (array_key_exists($name, $data)) {
                throw new InvalidArgumentException("Block [{$spec->type}] uses [{$name}] for both data and a slot.");
            }
            $data[$name] = $this->buildTree($host, $children instanceof BlockSpec ? [$children] : $children, $media, $spec->type, $name);
        }

        if ($spec->files !== []) {
            $data['id'] = (string) Str::ulid();
            $data['uuid'] = (string) Str::uuid();
            $media[] = [
                'blockId' => $data['id'],
                'disk' => $block::contentMediaDisk() ?? '',
                'uuid' => $data['uuid'],
                'files' => $spec->files,
            ];
        }

        return $data;
    }
}
