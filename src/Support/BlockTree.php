<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function app;
use function array_intersect_key;
use function array_key_last;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Fluent builder for TGS block trees, so tests and seeders can express content as
 * `BlockTree::make()->content('...')->code(...)` instead of hand-rolled
 * `['type' => ..., 'data' => [...]]` arrays fed straight to {@see CanvasPersistence}.
 *
 * Nodes yield a nested `[{type, data}]` snapshot. CanvasPersistence imports it
 * atomically, sharing row creation, ordering and media reconciliation with UI writes.
 *
 * Single-use per instance: build a tree, call {@see persistTo()} once — a second call
 * on the same instance throws (see `$persisted`), rather than silently re-running the
 * snapshot import and re-attaching every queued `withMedia()` file again. Build a fresh
 * {@see make()} instance for another persist.
 */
final class BlockTree
{
    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $nodes = [];

    /** @see persistTo() guard against a second call on the same instance. */
    private bool $persisted = false;

    /**
     * Media queued by {@see withMedia()}, attached to their block only after
     * {@see persistTo()} has upserted the block row (so a real id exists to attach to).
     *
     * Nested builders (container slots via {@see buildSlot()}, and the scoped
     * {@see TabsBuilder}/{@see AccordionBuilder}) each accumulate their own queue and
     * bubble it up into their parent's the moment the parent captures their output —
     * so no matter how deep a `withMedia()` call is nested, it ends up here, on the
     * root instance whose `persistTo()` actually runs.
     *
     * @var list<array{blockId: string, uuid: string, files: list<string>}>
     */
    private array $pendingMedia = [];

    public static function make(): self
    {
        return new self;
    }

    public function content(string $html): static
    {
        return $this->block(ContentBlockType::Content, [
            'content' => $html,
        ]);
    }

    public function code(string $src = '', ?bool $isPure = null): static
    {
        $data = ['code' => $src];

        if ($isPure !== null) {
            $data['is_pure'] = $isPure;
        }

        return $this->block(ContentBlockType::Code, $data);
    }

    public function embedded(string $url): static
    {
        return $this->block(ContentBlockType::Embedded, [
            'source' => $url,
            'ratio' => '16:9',
        ]);
    }

    /**
     * Media-bearing leaf: no file, no uuid, by itself — attaching real media is an
     * explicit opt-in via {@see withMedia()} chained right after this call, since
     * touching the filesystem on every leaf would be wasteful for the (common) case
     * of a picture block that's still a placeholder.
     */
    public function picture(?string $alt = null): static
    {
        $data = $alt !== null ? ['alt' => $alt] : [];

        return $this->block(ContentBlockType::Picture, $data);
    }

    /** @see picture() — same opt-in-media contract, N files instead of one. */
    public function gallery(): static
    {
        return $this->block(ContentBlockType::Gallery);
    }

    /**
     * Attaches one (picture) or several (gallery, in order) real files to the block
     * just added via {@see picture()}/{@see gallery()}. Generates the field uuid that
     * links the block's `data['uuid']` to its media's `custom_properties.uuid`
     * (`PictureData`/`GalleryData` resolve on that match) and stamps the block's own
     * id so the attachment can find the right row once {@see persistTo()} upserts it.
     *
     * Must be called immediately after `picture()`/`gallery()` on this same builder —
     * it mutates the most recently added node.
     *
     * @param  string|list<string>  $files  absolute path(s); attachment order is
     *                                      preserved as gallery image order.
     */
    public function withMedia(string|array $files): static
    {
        $index = array_key_last($this->nodes);

        if ($index === null) {
            throw new InvalidArgumentException('BlockTree::withMedia(): call it right after picture()/gallery() — there is no block to attach media to.');
        }

        $type = $this->nodes[$index]['type'];
        $mediaTypes = [ContentBlockType::Picture->value, ContentBlockType::Gallery->value];

        if (! in_array($type, $mediaTypes, true)) {
            throw new InvalidArgumentException(sprintf(
                'BlockTree::withMedia(): the last block must be picture()/gallery(), got "%s".',
                $type,
            ));
        }

        if (array_key_exists('uuid', $this->nodes[$index]['data'])) {
            throw new InvalidArgumentException('BlockTree::withMedia(): already called on this block — a second call would overwrite the first attachment\'s uuid, orphaning its files. Attach all files in one call instead: withMedia([...]).');
        }

        $fileList = is_array($files) ? $files : [$files];

        if ($fileList === []) {
            throw new InvalidArgumentException('BlockTree::withMedia(): file list is empty — this would stamp a uuid onto the block with no media to match it. Pass at least one file, or omit withMedia() entirely to leave this block without media.');
        }

        $blockId = $this->nodes[$index]['data']['id'] ?? (string) Str::ulid();
        $uuid = (string) Str::uuid();

        $this->nodes[$index]['data']['id'] = $blockId;
        $this->nodes[$index]['data']['uuid'] = $uuid;

        $this->pendingMedia[] = [
            'blockId' => $blockId,
            'uuid' => $uuid,
            'files' => $fileList,
        ];

        return $this;
    }

    /**
     * Exposes this instance's queued media so a parent container/{@see TabsBuilder}/
     * {@see AccordionBuilder} can bubble it into its own queue — see the class docblock
     * note on {@see $pendingMedia}.
     *
     * @return list<array{blockId: string, uuid: string, files: list<string>}>
     */
    public function exportPendingMedia(): array
    {
        return $this->pendingMedia;
    }

    /**
     * Single-slot container: children are built by a nested {@see BlockTree} and land
     * under the container's implicit `content` slot.
     *
     * @param  Closure(BlockTree): mixed  $content
     */
    public function column(Closure $content): static
    {
        return $this->contentSlotContainer(ContentBlockType::Column, $content);
    }

    /** @param  Closure(BlockTree): mixed  $content */
    public function collapse(Closure $content): static
    {
        return $this->contentSlotContainer(ContentBlockType::Collapse, $content);
    }

    /** @param  Closure(BlockTree): mixed  $content */
    public function popup(Closure $content): static
    {
        return $this->contentSlotContainer(ContentBlockType::Popup, $content);
    }

    /**
     * Two-slot container: `left`/`right` must be named explicitly, unlike the
     * single-slot containers above, since there is no implicit slot to fall back to.
     *
     * @param  Closure(BlockTree): mixed  $left
     * @param  Closure(BlockTree): mixed  $right
     */
    public function doubleColumn(Closure $left, Closure $right): static
    {
        return $this->block(ContentBlockType::DoubleColumn, slots: [
            'left' => $this->buildSlot($left),
            'right' => $this->buildSlot($right),
        ]);
    }

    /**
     * Scoped container: `$fill` receives a {@see TabsBuilder}, the only place
     * `->tabItem()` exists — it is not a method of `BlockTree` itself.
     *
     * @param  Closure(TabsBuilder): mixed  $fill
     */
    public function tabs(Closure $fill): static
    {
        $builder = new TabsBuilder;
        $fill($builder);

        $this->pendingMedia = [...$this->pendingMedia, ...$builder->exportPendingMedia()];

        return $this->block(ContentBlockType::Tabs, slots: [
            'items' => $builder->toArray(),
        ]);
    }

    /**
     * Scoped container: `$fill` receives an {@see AccordionBuilder}, the only place
     * `->accordionItem()` exists — it is not a method of `BlockTree` itself.
     *
     * @param  Closure(AccordionBuilder): mixed  $fill
     */
    public function accordion(Closure $fill): static
    {
        $builder = new AccordionBuilder;
        $fill($builder);

        $this->pendingMedia = [...$this->pendingMedia, ...$builder->exportPendingMedia()];

        return $this->block(ContentBlockType::Accordion, slots: [
            'items' => $builder->toArray(),
        ]);
    }

    /**
     * Escape hatch: any block type, arbitrary data. `$slots` merges in named child
     * lists (e.g. `['content' => [...]]`) already shaped as `{type,data}` node arrays —
     * CanvasPersistence imports those slots as nested child placements.
     *
     * `$data` and `$slots` are disjoint namespaces (plain fields vs. child lists); a
     * key present in both is almost certainly a caller mistake (e.g. naming a data
     * field the same as a slot), so it's rejected rather than silently letting the
     * slot clobber the data value.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $slots
     */
    public function block(ContentBlockType $type, array $data = [], array $slots = []): static
    {
        $overlap = array_intersect_key($data, $slots);

        if ($overlap !== []) {
            throw new InvalidArgumentException(sprintf(
                'BlockTree::block(): $data and $slots keys must be disjoint, got overlapping key(s): %s',
                implode(', ', array_keys($overlap)),
            ));
        }

        $this->nodes[] = [
            'type' => $type->value,
            'data' => [...$data, ...$slots],
        ];

        return $this;
    }

    /**
     * Builds one slot's children with a fresh nested {@see BlockTree}.
     *
     * @param  Closure(BlockTree): mixed  $fill
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function buildSlot(Closure $fill): array
    {
        $child = new self;
        $fill($child);

        $this->pendingMedia = [...$this->pendingMedia, ...$child->exportPendingMedia()];

        return $child->toArray();
    }

    /** @param  Closure(BlockTree): mixed  $content */
    private function contentSlotContainer(ContentBlockType $type, Closure $content): static
    {
        return $this->block($type, slots: [
            'content' => $this->buildSlot($content),
        ]);
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    public function toArray(): array
    {
        return $this->nodes;
    }

    /**
     * `$host` is typically a HasCanvases implementor (Page/News/Article/Collection/TgsDemo).
     * During the "canvases as entity" migration (Слайс B), `App\Models\Virtual` and
     * `App\Models\TgsHostItem` still sit on the legacy HasTgsGroups trait but expose the same
     * `canvasDescriptor()` method as a small bridge (see their own doc-blocks) — untyped here
     * on purpose so both can be passed without either implementing the other's contract.
     */
    public function persistTo(object $host, string $groupKey): void
    {
        $this->persistToDescriptor($host->canvasDescriptor($groupKey));
    }

    /**
     * Named/embedded canvases (ApiMedia\Tgs\Models\Canvas, kind='named'/'embedded') have no `group` to
     * key off of — the row itself already fully resolves its own HostDescriptor via
     * `Canvas::hostDescriptor()` — so seeders/callers persist straight against that descriptor
     * instead of going through `persistTo()`'s host+group indirection.
     */
    public function persistToDescriptor(HostDescriptor $descriptor): void
    {
        if ($this->persisted) {
            throw new InvalidArgumentException('BlockTree::persistTo(): already called on this instance — a second call would re-run the snapshot import and re-attach every queued withMedia() file again. Build a fresh BlockTree::make() instance for another persist.');
        }

        if ($this->nodes === []) {
            throw new InvalidArgumentException('BlockTree::persistTo(): the tree has no nodes — persisting an empty tree would delete any existing content in this host+slot and create nothing. This is almost certainly a mistake; add at least one node before persisting.');
        }

        $this->persisted = true;

        app(CanvasPersistence::class)->replaceTree($descriptor, $this->toArray());

        $this->attachPendingMedia();
    }

    /**
     * Runs after the snapshot import has upserted the blocks (and pruned their media down
     * to `data['uuid']`, which is empty at that point — nothing to collide with), so
     * it's safe to attach the queued files now with their matching uuid.
     */
    private function attachPendingMedia(): void
    {
        foreach ($this->pendingMedia as $pending) {
            $block = TgsModels::block()::query()->whereKey($pending['blockId'])->firstOrFail();

            foreach ($pending['files'] as $file) {
                $block->addMedia($file)
                    ->preservingOriginal()
                    ->withCustomProperties(['uuid' => $pending['uuid']])
                    ->toMediaCollection($block::getContentMediaCollectionName());
            }
        }
    }
}
