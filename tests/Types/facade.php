<?php

declare(strict_types=1);

/*
 * Type inference of the facade's typed getters, checked by PHPStan (assertType() fails the analysis on a
 * mismatch). Never executed: PHPUnit loads only *Test.php.
 */

namespace Onlineconf\Laravel\Tests\Types;

use Onlineconf\Laravel\Facades\Onlineconf;

use function PHPStan\Testing\assertType;

function facadeGetters(?string $maybe): void
{
    assertType('int', Onlineconf::getInt('/p', '3306')); // a non-empty string reads as an int or throws
    assertType('int|null', Onlineconf::getInt('/p', $maybe));
    assertType('int|null', Onlineconf::getInt('/p', ''));
    assertType('float', Onlineconf::getFloat('/p', 1));
    assertType('bool', Onlineconf::getBool('/p', '1'));
    assertType('bool|null', Onlineconf::getBool('/p', $maybe));
    assertType('float', Onlineconf::getDuration('/p', '1m'));
    assertType('int', Onlineconf::getDurationMs('/p', '1s'));
    assertType('list<string>', Onlineconf::getStrings('/p', 'a,b'));
    assertType('array<mixed>', Onlineconf::getArray('/p', '{"a":1}'));
    assertType('string', Onlineconf::getString('/p', 'd'));
    assertType('string|null', Onlineconf::getString('/p'));
    assertType('string|null', Onlineconf::getString('/p', null));
    assertType('string|null', Onlineconf::getString('/p', $maybe));
    assertType('int', Onlineconf::getInt('/p', 1));
    assertType('int|null', Onlineconf::getInt('/p', null));
    assertType('float', Onlineconf::getFloat('/p', 1.0));
    assertType('float|null', Onlineconf::getFloat('/p'));
    assertType('bool', Onlineconf::getBool('/p', false));
    assertType('bool|null', Onlineconf::getBool('/p'));
    assertType('float', Onlineconf::getDuration('/p', 1.0));
    assertType('float|null', Onlineconf::getDuration('/p'));
    assertType('int', Onlineconf::getDurationMs('/p', 1));
    assertType('int|null', Onlineconf::getDurationMs('/p'));
    assertType('list<string>', Onlineconf::getStrings('/p', []));
    assertType('list<string>|null', Onlineconf::getStrings('/p'));
    assertType('array<mixed>', Onlineconf::getArray('/p', []));
    assertType('array<mixed>|null', Onlineconf::getArray('/p'));
}
