<?php

namespace ApiMedia\Tgs\Enums;

/**
 * Slot-container layout hint for the TGS canvas (_node.blade.php): Stack renders a
 * container block's named slots one below another (the long-standing default — every
 * existing multi-slot block, e.g. tabs/accordion, keeps this look); Row renders them
 * side-by-side in a flex row, fixed 50/50 split regardless of how many slots there are
 * or any per-slot width settings the block itself stores in its own `data` (those govern
 * the FRONTEND render, not the editor — deliberately not mirrored here, see
 * docs/superpowers/plans/2026-07-23-tgs-horizontal-columns-plan.md).
 */
enum TgsBlockLayout: string
{
    case Stack = 'stack';
    case Row = 'row';
}
