<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** @return list<array{line:int,name:string}> */
return static function (string $source, array $functions, array $classes = []): array {
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
    $matches = [];
    foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name)
        || $node instanceof Node\Name
    ) as $node) {
        if ($node instanceof Node\Expr\FuncCall) {
            $name = strtolower($node->name->toString());
            if (in_array($name, $functions, true)) {
                $matches[] = ['line' => $node->getStartLine(), 'name' => $name];
            }
        } elseif (in_array(strtolower($node->getLast()), $classes, true)) {
            $matches[] = ['line' => $node->getStartLine(), 'name' => $node->toString()];
        }
    }

    return $matches;
};
