<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Detect schema probes by resolved receiver provenance, never by method name alone. */
return static function (string $source): array {
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
    $finder = new NodeFinder;
    $returnTypes = [];
    $methodNodes = $nodes;
    // Resolve application parent/trait declarations without loading runtime classes.
    $pending = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\TraitUse);
    $seen = [];
    while ($pending !== []) {
        $declaration = array_pop($pending);
        $names = $declaration instanceof Node\Stmt\TraitUse ? $declaration->traits : [$declaration->extends];
        foreach ($names as $name) {
            if (! $name instanceof Node\Name || ! str_starts_with($name->toString(), 'App\\') || isset($seen[$name->toString()])) {
                continue;
            }
            $seen[$name->toString()] = true;
            $path = dirname(__DIR__, 2).'/app/'.str_replace('\\', '/', substr($name->toString(), 4)).'.php';
            if (! is_file($path)) {
                continue;
            }
            $related = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($path)) ?? [];
            $related = (new NodeTraverser(new NameResolver))->traverse($related);
            $methodNodes = array_merge($related, $methodNodes);
            array_push($pending, ...$finder->find($related, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\TraitUse));
        }
    }
    $typeName = static function ($type): ?string {
        $name = $type instanceof Node\Name ? strtolower($type->toString()) : '';

        return match ($name) {
            'illuminate\database\connection', 'illuminate\database\connectioninterface' => 'connection',
            'illuminate\database\schema\builder' => 'schema',
            default => null,
        };
    };
    foreach ($finder->findInstanceOf($methodNodes, Node\Stmt\ClassMethod::class) as $method) {
        $type = $typeName($method->returnType);
        if ($type === null) {
            foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class) as $return) {
                $expression = $return->expr;
                if ($expression instanceof Node\Expr\StaticCall
                    && $expression->class instanceof Node\Name
                    && in_array(strtolower($expression->class->toString()), ['illuminate\\support\\facades\\db', 'db'], true)
                    && $expression->name instanceof Node\Identifier
                    && strtolower($expression->name->toString()) === 'connection') {
                    $type = 'connection';
                }
            }
        }
        $returnTypes[strtolower($method->name->toString())] = $type;
    }
    $matches = [];
    $walk = function ($node, array &$variables) use (&$walk, &$matches, $returnTypes, $typeName): ?string {
        if (! $node instanceof Node) {
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child, $variables);
                }
            }

            return null;
        }
        if ($node instanceof Node\FunctionLike) {
            $local = $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction ? $variables : [];
            foreach ($node->getParams() as $param) {
                if (is_string($param->var->name)) {
                    $local[$param->var->name] = $typeName($param->type);
                }
            }
            $walk($node instanceof Node\Expr\ArrowFunction ? $node->expr : $node->getStmts(), $local);

            return null;
        }
        if ($node instanceof Node\Expr\Variable) {
            return is_string($node->name) ? ($variables[$node->name] ?? null) : null;
        }
        if ($node instanceof Node\Expr\Assign) {
            $type = $walk($node->expr, $variables);
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $variables[$node->var->name] = $type;
            }

            return $type;
        }
        if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
            $method = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
            if ($node instanceof Node\Expr\StaticCall) {
                $class = $node->class instanceof Node\Name ? strtolower($node->class->toString()) : '';
                $receiver = match ($class) {
                    'illuminate\support\facades\schema', 'schema' => 'schema',
                    'illuminate\support\facades\db', 'db' => 'database',
                    default => null,
                };
            } else {
                $receiver = $walk($node->var, $variables);
            }
            $walk($node->args, $variables);
            if ($receiver === 'schema' && in_array($method, ['hastable', 'hascolumn'], true)) {
                $matches[] = ['line' => $node->getStartLine(), 'name' => $method];
            }
            if ($receiver === 'schema' && $method === 'connection') {
                return 'schema';
            }
            if ($receiver === 'database' && $method === 'connection') {
                return 'connection';
            }
            if (in_array($receiver, ['connection', 'database'], true) && $method === 'getschemabuilder') {
                return 'schema';
            }
            if ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                return $returnTypes[$method] ?? null;
            }

            return null;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $walk($node->$name, $variables);
        }

        return null;
    };
    $variables = [];
    $walk($nodes, $variables);

    return $matches;
};
