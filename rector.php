<?php declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src/Sources',
	])
	->withSkip([
		__DIR__ . '**/vendor/*',
		// Authenticator::authenticate() receives $idMember back from
		// call_integration_hook(..., [$request, &$idMember]) through a
		// by-reference array element. Rector's flow analysis cannot see that
		// write, so it treats $idMember as forever null and would strip the
		// whole key-resolution branch together with loadMember() and
		// applyUserContext(). Keep the dead-code rules off this file.
		RemoveAlwaysTrueIfConditionRector::class => [
			__DIR__ . '/src/Sources/API/Authenticator.php',
		],
		RemoveUnreachableStatementRector::class => [
			__DIR__ . '/src/Sources/API/Authenticator.php',
		],
		RemoveUnusedVariableAssignRector::class => [
			__DIR__ . '/src/Sources/API/Authenticator.php',
		],
		RemoveUnusedPrivateMethodRector::class => [
			__DIR__ . '/src/Sources/API/Authenticator.php',
		],
		// SMF stores and later invokes hook/action handlers in the
		// [ $object, 'method' ] array-callable form; do not rewrite them into
		// first-class callables.
		ArrayToFirstClassCallableRector::class,
		// The two casts this rule flags are deliberate safety nets, not noise:
		// (array) around getallheaders() (documented array|false) and (int)
		// around the $idMember a mod may return from the authenticate hook as a
		// string, which strict_types would otherwise reject.
		RecastingRemovalRector::class,
	])
	->withParallel(360)
	->withIndent(indentChar: "\t")
	->withImportNames(importShortClasses: false, removeUnusedImports: true)
	->withPreparedSets(deadCode: true)
	->withPhpSets();
