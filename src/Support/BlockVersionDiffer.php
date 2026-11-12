<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use Jfcherng\Diff\DiffHelper;

/**
 * §13: pure line-diff of two block `data` snapshots. Diffs the pretty-printed JSON textually —
 * no field-aware rendering per block type (11 block types, not worth a bespoke renderer per
 * type). `SideBySide` (two-column: old | new): the full diff renders in a `Width::Screen`
 * Filament modal (viewDiffAction on TgsBlockHistoryDrawer), not the ~640px history drawer
 * itself — the drawer only shows a short per-version summary, so the cramped-width constraint
 * that originally ruled out SideBySide no longer applies. Table headers translated via
 * jfcherng/php-diff's `language` renderer option (accepts a custom translation array, not just
 * a built-in language code).
 */
final class BlockVersionDiffer
{
    private const array RU_LANGUAGE = [
        'old_version' => 'Было',
        'new_version' => 'Стало',
        'differences' => 'Отличия',
    ];

    /**
     * @param  array<array-key, mixed>  $old
     * @param  array<array-key, mixed>  $new
     */
    public function render(array $old, array $new): string
    {
        $oldJson = (string) json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newJson = (string) json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $html = DiffHelper::calculate($oldJson, $newJson, 'SideBySide', [], ['language' => self::RU_LANGUAGE]);

        return $this->withEvenColumnWidths($html);
    }

    /**
     * table-layout:fixed sizes columns from the table's FIRST row of cells (spec algorithm,
     * absent a <colgroup>) — but the renderer's own <thead> row only has 2 cells, each
     * colspan="2" (see SideBySide::renderTableHeader()), one per side. Per-side widths set via
     * CSS on the tbody's th.n-old/n-new/td.old/td.new (EXTRA_STYLES above) are cells in LATER
     * rows, which table-layout:fixed ignores for sizing — so without an explicit <colgroup> the
     * even 50/50 split silently doesn't apply (each colspan="2" header cell just gets an equal
     * share of ITS span, giving four equal ~25% columns instead of the intended
     * 3em/47%/3em/47%). A <colgroup> is the only standards-compliant way to size individual
     * columns under a fixed layout, and the renderer never emits one — inject it ourselves.
     */
    private function withEvenColumnWidths(string $html): string
    {
        $colgroup = '<colgroup>'
            .'<col style="width:3em">'
            .'<col style="width:calc(50% - 3em)">'
            .'<col style="width:3em">'
            .'<col style="width:calc(50% - 3em)">'
            .'</colgroup>';

        return preg_replace('/(<table class="[^"]*diff-side-by-side[^"]*">)/', '$1'.$colgroup, $html, 1) ?? $html;
    }

    /**
     * The library's own stylesheet (see vendor/jfcherng/php-diff/example/diff-table.css) leaves
     * the two content columns (td.old/td.new) unsized — with the default table-layout:auto,
     * each column's width just follows its own longest unwrapped line, so a short "old" value
     * next to a long "new" value renders as a lopsided, barely-readable sliver instead of an
     * even 50/50 split. table-layout:fixed plus explicit widths (line-number columns fixed,
     * content columns splitting the remainder equally) forces the even split regardless of
     * either side's content length.
     */
    private const string EXTRA_STYLES = '
        .diff-wrapper.diff.diff-side-by-side { table-layout: fixed; }
        .diff-wrapper.diff.diff-side-by-side th.n-old,
        .diff-wrapper.diff.diff-side-by-side th.n-new { width: 3em; }
        .diff-wrapper.diff.diff-side-by-side td.old,
        .diff-wrapper.diff.diff-side-by-side td.new { width: calc(50% - 3em); }
    ';

    /**
     * GitHub-style recolor (2026-07-22 UX follow-up): the vendor stylesheet's default look is a
     * plain gray diagonal-striped background with black borders/text, identical in light and
     * dark — jarring against the rest of this (themed) admin panel. Matches GitHub's diff
     * palette (soft red/green line tints, gray line-number gutter, subtle border, no stripes)
     * for light mode, plus a dark-mode variant. Filament's dark mode toggles a `dark` class on
     * `<html>` (not a `prefers-color-scheme` media query), so the dark variant is scoped via
     * `html.dark .diff-wrapper` selectors, matching how the rest of this panel switches themes.
     */
    private const string GITHUB_THEME_STYLES = '
        .diff-wrapper.diff {
            background: #ffffff;
            border: 1px solid #d1d9e0;
            color: #1f2328;
        }
        .diff-wrapper.diff td,
        .diff-wrapper.diff th { background: transparent; }
        .diff-wrapper.diff thead th {
            background: #f6f8fa;
            border-bottom: 1px solid #d1d9e0;
            color: #59636e;
        }
        .diff-wrapper.diff tbody th {
            background: #f6f8fa;
            border-right: 1px solid #d1d9e0;
            color: #8c959f;
        }
        .diff-wrapper.diff.diff-html .change .old { background: #ffebe9; }
        .diff-wrapper.diff.diff-html .change .new { background: #e6ffec; }
        .diff-wrapper.diff.diff-html .change ins {
            background: #abf2bc;
            font-weight: 400;
        }
        .diff-wrapper.diff.diff-html .change del {
            background: #ffcecb;
            font-weight: 400;
        }

        html.dark .diff-wrapper.diff {
            background: #0d1117;
            border-color: #30363d;
            color: #e6edf3;
        }
        html.dark .diff-wrapper.diff thead th {
            background: #161b22;
            border-bottom-color: #30363d;
            color: #8b949e;
        }
        html.dark .diff-wrapper.diff tbody th {
            background: #161b22;
            border-right-color: #30363d;
            color: #6e7681;
        }
        html.dark .diff-wrapper.diff.diff-html .change .old { background: #3d1418; }
        html.dark .diff-wrapper.diff.diff-html .change .new { background: #122117; }
        html.dark .diff-wrapper.diff.diff-html .change ins { background: #2ea04366; }
        html.dark .diff-wrapper.diff.diff-html .change del { background: #f8514966; }
    ';

    public function styleSheet(): string
    {
        return DiffHelper::getStyleSheet().self::EXTRA_STYLES.self::GITHUB_THEME_STYLES;
    }
}
