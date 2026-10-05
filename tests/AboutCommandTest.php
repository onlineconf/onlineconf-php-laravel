<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

final class AboutCommandTest extends TestCase
{
    /**
     * @return array{int, string}
     */
    private function about(): array
    {
        $output = new BufferedOutput();
        $code = Artisan::call('about', ['--only' => 'onlineconf'], $output);

        return [$code, $output->fetch()];
    }

    public function testSectionShowsDirectoryModuleAndVersion(): void
    {
        $file = $this->useModule(['/app/name' => 'sdemo']);

        [$code, $output] = $this->about();

        self::assertSame(0, $code);
        self::assertStringContainsString('OnlineConf', $output);
        self::assertStringContainsString($this->tempDir(), $output);
        self::assertStringContainsString($file, $output);
        self::assertMatchesRegularExpression('/\d+:\d+:\d+/', $output, 'version is inode:mtime:size');
        self::assertMatchesRegularExpression('/Mode\s*\.*\s*strict/', $output);
        self::assertMatchesRegularExpression('/State\s*\.*\s*loaded/', $output);
    }

    public function testAMissingOptionalModuleIsShownAsSuch(): void
    {
        $this->optionalModule();
        $this->config()->set('onlineconf.dir', $this->tempDir());

        [$code, $output] = $this->about();

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/Mode\s*\.*\s*tolerant/', $output);
        self::assertMatchesRegularExpression('/State\s*\.*\s*missing, opened once it appears/', $output);
        self::assertMatchesRegularExpression('/Version\s*\.*\s*missing/', $output);
    }

    public function testUnopenableModuleIsReportedWithoutFailing(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        [$code, $output] = $this->about();

        self::assertSame(0, $code);
        self::assertStringContainsString('TREE.cdb', $output);
        self::assertMatchesRegularExpression('/State\s*\.*\s*error/', $output);
        self::assertStringContainsString('ONLINECONF_REQUIRED=false', $output, 'the message says how to start without it');
    }
}
