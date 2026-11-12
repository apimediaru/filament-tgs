<?php

namespace ApiMedia\Tgs\Tests\Support\Tgs;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

use function array_unique;
use function array_values;
use function in_array;
use function sort;

/** Static App references only; no PHPDoc or dynamically assembled class names. */
final class TgsDependencies
{
    /** @return list<string> */
    public static function rawSqlCalls(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $finder = new NodeFinder;
        $calls = [];
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) as $node) {
            if ((! $node instanceof Node\Expr\StaticCall && ! $node instanceof Node\Expr\MethodCall)
                || ! $node->name instanceof Node\Identifier) {
                continue;
            }
            $method = $node->name->toString();
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Name
                && $node->class->toString() === 'Illuminate\Support\Facades\DB'
                && in_array($method, ['raw', 'select', 'selectOne', 'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement'], true)) {
                $calls[] = 'DB::'.$method;
            } elseif ($node instanceof Node\Expr\MethodCall
                && in_array($method, ['selectRaw', 'whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw', 'orderByRaw', 'groupByRaw', 'statement', 'unprepared', 'affectingStatement'], true)) {
                $calls[] = $method;
            }
        }
        $calls = array_values(array_unique($calls));
        sort($calls);

        return $calls;
    }

    /** @return list<string> */
    public static function names(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $imports = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($nodes, Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $imports[] = $item->name->toString();
            }
        }
        foreach ($finder->findInstanceOf($nodes, GroupUse::class) as $use) {
            foreach ($use->uses as $item) {
                $imports[] = $use->prefix->toString().'\\'.$item->name->toString();
            }
        }

        $traverser = new NodeTraverser(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        $names = [];
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Name\FullyQualified || $node instanceof String_) as $node) {
            if ($node instanceof String_) {
                $names[] = ltrim($node->value, '\\');
            } elseif ($node instanceof Name\FullyQualified) {
                $names[] = $node->toString();
            }
        }

        // A namespace alias is not a class dependency of its own. Its resolved
        // child references are already present (e.g. Models -> Models\Block).
        foreach ($imports as $import) {
            $namespaceAlias = false;
            foreach ($names as $name) {
                if (str_starts_with(strtolower($name), strtolower($import).'\\')) {
                    $namespaceAlias = true;
                    break;
                }
            }
            if (! $namespaceAlias) {
                $names[] = $import;
            }
        }
        $classes = [];
        foreach ($names as $name) {
            if (preg_match('/^App\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?:::[A-Za-z_][A-Za-z0-9_]*)?$/iD', $name)) {
                $classes[] = 'App\\'.substr(explode('::', $name, 2)[0], 4);
            }
        }
        $names = array_values(array_unique($classes));
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $baseline
     * @return array{new: list<string>, stale: list<string>}
     */
    public static function changes(array $actual, array $baseline): array
    {
        $new = array_values(array_diff($actual, $baseline));
        $stale = array_values(array_diff($baseline, $actual));
        sort($new);
        sort($stale);

        return ['new' => $new, 'stale' => $stale];
    }
}
