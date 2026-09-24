<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Console\GetCommand;
use Onlineconf\Laravel\Console\MapCommand;
use Onlineconf\Laravel\Console\SetCommand;

/**
 * Laravel 10's `Illuminate\Console\Parser::parameters()` matches option-like tokens with an unanchored
 * regex (`/-{2,}(.*)/`), so a `--flag` mentioned anywhere in an argument's description — not just at its
 * start — turns the whole `{...}` token into an option and silently drops the argument. Laravel 11+
 * anchors the regex, so this only breaks on Laravel 10. These tests inspect the parsed definition
 * directly, which fails the same way on every supported Laravel version.
 */
final class SignatureTest extends TestCase
{
    public function testSetCommandSignatureKeepsBothArguments(): void
    {
        $command = $this->application()->make(SetCommand::class);
        assert($command instanceof SetCommand);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('path'));
        self::assertTrue($definition->hasArgument('value'));
        self::assertSame(2, $definition->getArgumentCount());
        self::assertTrue($definition->hasOption('json'));
        self::assertTrue($definition->hasOption('delete'));
        self::assertTrue($definition->hasOption('module'));
    }

    public function testGetCommandSignatureKeepsItsArgument(): void
    {
        $command = $this->application()->make(GetCommand::class);
        assert($command instanceof GetCommand);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('path'));
        self::assertSame(1, $definition->getArgumentCount());
        self::assertTrue($definition->hasOption('json'));
        self::assertTrue($definition->hasOption('tree'));
        self::assertTrue($definition->hasOption('module'));
    }

    public function testMapCommandSignatureHasOnlyTheJsonOption(): void
    {
        $command = $this->application()->make(MapCommand::class);
        assert($command instanceof MapCommand);
        $definition = $command->getDefinition();

        self::assertSame(0, $definition->getArgumentCount());
        self::assertTrue($definition->hasOption('json'));
    }
}
