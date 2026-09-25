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
 * What {@see Ref::value()} does: read the node through the facade's immediate read of the marker's type — so
 * it works after boot (the module from the container) and while config/*.php loads (the immediate module,
 * recorded in {@see EagerReads}) — with the absence rules of every other read, then apply the transform.
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
            return Onlineconf::__callStatic('require' . $getter, [$ref->path]);
        }

        try {
            if ($ref->fallback !== null || $ref->type === Ref::TYPE_RAW) {
                return Onlineconf::__callStatic('get' . $getter, [$ref->path, $ref->fallback]);
            }

            // The client's typed getters take a default of their own type, so a null fallback is served here.
            return Onlineconf::__callStatic('require' . $getter, [$ref->path]);
        } catch (NotFoundException|OpenException) {
            return $ref->fallback;
        } catch (FormatException|ParseException $e) {
            self::log('warning', 'onlineconf: ' . $e->getMessage());

            return $ref->fallback;
        } catch (InvalidJsonException $e) {
            self::log('error', sprintf(
                'OnlineConf value at %s is not valid JSON, the marker falls back to its fallback: %s',
                $ref->path,
                $e->getMessage(),
            ));

            return $ref->fallback;
        }
    }

    /**
     * What config() would log for the same node: the client's warning for a value that does not parse, an
     * error for invalid JSON. While config/*.php loads there is no logger yet and the immediate module logs
     * nothing either.
     *
     * @param 'warning'|'error' $level
     */
    private static function log(string $level, string $message): void
    {
        $app = Onlineconf::getFacadeApplication();
        if ($app === null || !$app->bound(Module::class)) {
            return;
        }
        $config = $app->make('config');
        assert($config instanceof Repository);

        ModuleManagerFactory::logger($app, $config->get('onlineconf.log_channel'))->log($level, $message);
    }
}
