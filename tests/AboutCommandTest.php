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
    }

    public function testUnopenableModuleIsReportedWithoutFailing(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        [$code, $output] = $this->about();

        self::assertSame(0, $code);
        self::assertStringContainsString('TREE.cdb', $output);
        self::assertStringContainsStringIgnoringCase('error', $output);
    }
}
