<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Config\Repository;
use LogicException;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\ParseException;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Module;
use RuntimeException;
use WeakMap;

/**
 * What {@see Ref::value()} does: read the node with the client's getter of the marker's type from the module
 * the facade would use — the container's after boot, the immediate module (recorded in {@see EagerReads})
 * while config/*.php loads — with the absence rules of every other read, then apply the transform.
 *
 * @internal
 */
final class MarkerReader
{
    /** @var array<string, string> declared type → the suffix of the client's getter */
    private const GETTERS = [
        Ref::TYPE_STRING => 'String',
        Ref::TYPE_INT => 'Int',
        Ref::TYPE_FLOAT => 'Float',
        Ref::TYPE_BOOL => 'Bool',
        Ref::TYPE_DURATION => 'Duration',
        Ref::TYPE_DURATION_MS => 'DurationMs',
        Ref::TYPE_STRINGS => 'Strings',
        Ref::TYPE_ARRAY => 'Array',
        Ref::TYPE_RAW => '',
    ];

    /** @var WeakMap<Ref, callable>|null a marker's transform, decoded on its first read */
    private static ?WeakMap $transforms = null;

    /**
     * @throws NotFoundException|OpenException              for a required marker whose node or module is missing
     * @throws FormatException|ParseException|InvalidJsonException for a required marker whose node does not parse
     * @throws LogicException                               when the stored transform is not a callable here
     * @throws RuntimeException                             when the transform throws
     */
    public static function read(Ref $ref): mixed
    {
        $value = self::node($ref);
        $transform = self::transform($ref);

        return $transform === null ? $value : Transform::apply($transform, $value, null, $ref->path);
    }

    /**
     * The marker's transform, decoded once per marker; the value itself is never kept.
     *
     * @throws LogicException when the stored transform is not a callable here
     */
    public static function transform(Ref $ref): ?callable
    {
        if ($ref->transform === null) {
            return null;
        }
        $transforms = self::$transforms ??= new WeakMap();

        return $transforms[$ref] ??= Transform::decode($ref->transform, null, $ref->path);
    }

    private static function node(Ref $ref): mixed
    {
        $getter = self::GETTERS[$ref->type] ?? '';
        if ($ref->required) {
            return self::call(self::module($ref), 'require' . $getter, [$ref->path]);
        }

        // The client's get* take the fallback as it is, null included: a node the tree does not have gives it,
        // and so does a value that does not parse, after the client's warning.
        try {
            return self::call(self::module($ref), 'get' . $getter, [$ref->path, $ref->fallback]);
        } catch (OpenException) {
            return $ref->fallback;
        } catch (InvalidJsonException $e) {
            self::error(sprintf(
                'OnlineConf value at %s is not valid JSON, the marker falls back to its fallback: %s',
                $ref->path,
                $e->getMessage(),
            ));

            return $ref->fallback;
        }
    }

    /**
     * The module the facade would read: the container's once the package's provider has registered, the
     * immediate module of the process environment before — where the read is recorded, as the marker is
     * (optional or required), not as the getter that serves it.
     *
     * @throws OpenException when there is no module file
     */
    private static function module(Ref $ref): Module
    {
        $app = Onlineconf::getFacadeApplication();
        if ($app !== null && $app->bound(Module::class)) {
            $module = $app->make(Module::class);
            assert($module instanceof Module);

            return $module;
        }
        EagerReads::record($ref->path, $ref->type, $ref->required ? null : $ref->fallback, $ref->required);

        return ImmediateModule::module();
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function call(Module $module, string $method, array $arguments): mixed
    {
        $getter = [$module, $method];
        assert(is_callable($getter));

        return $getter(...$arguments);
    }

    /**
     * The error config() would log for the same node. While config/*.php loads there is no logger yet and the
     * immediate module logs nothing either.
     */
    private static function error(string $message): void
    {
        $app = Onlineconf::getFacadeApplication();
        if ($app === null || !$app->bound(Module::class)) {
            return;
        }
        $config = $app->make('config');
        assert($config instanceof Repository);

        ModuleManagerFactory::logger($app, $config->get('onlineconf.log_channel'))->error($message);
    }
}
