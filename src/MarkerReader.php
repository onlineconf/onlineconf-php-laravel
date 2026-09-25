<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Config\Repository;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\ParseException;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Module;

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

    /**
     * @throws \Onlineconf\Exception\OnlineconfException for a required marker whose node or module is missing
     * @throws \RuntimeException                          when the transform throws
     */
    public static function read(Ref $ref): mixed
    {
        $value = self::node($ref);
        if ($ref->transform === null) {
            return $value;
        }

        return Transform::apply(Transform::decode($ref->transform, null, $ref->path), $value, null, $ref->path);
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
            self::warn($e->getMessage());

            return $ref->fallback;
        }
    }

    /**
     * The warning the client's get* would log. While config/*.php loads there is no logger yet and the
     * immediate module logs nothing either.
     */
    private static function warn(string $message): void
    {
        $app = Onlineconf::getFacadeApplication();
        if ($app === null || !$app->bound(Module::class)) {
            return;
        }
        $config = $app->make('config');
        assert($config instanceof Repository);

        ModuleManagerFactory::logger($app, $config->get('onlineconf.log_channel'))->warning('onlineconf: ' . $message);
    }
}
