<?php
/**
 * Guards that the repository states ONE license.
 *
 * This is a phpBB extension, so it follows phpBB and is GPL-2.0-only. composer.json,
 * the README and the header of every PHP file say so. An org-wide file sync once put
 * the MIT text in LICENSE, so the published repo would have shown two licenses that
 * contradict each other. These tests fail if any of the four places drifts again.
 *
 * Moving this repo to another license is a deliberate decision: change LICENSE,
 * composer.json, the README, every PHP header and this test in the same pull request.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;

class license_consistency_test extends TestCase
{
    private const ROOT = __DIR__ . '/..';
    private const PHP_HEADER = '@license GNU General Public License, version 2 (GPL-2.0)';

    public function test_license_file_is_the_gpl_2_text(): void
    {
        $path = self::ROOT . '/LICENSE';
        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);

        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $text);
        $this->assertStringContainsString('Version 2, June 1991', $text);
        $this->assertStringNotContainsString('MIT License', $text);
        $this->assertStringNotContainsString('Permission is hereby granted, free of charge', $text);
    }

    public function test_composer_json_says_gpl_2_only(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertSame('GPL-2.0-only', $composer['license'] ?? null);
    }

    public function test_readme_license_section_says_gpl_2_only(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        $this->assertMatchesRegularExpression('/^## License\s*\n+GPL-2\.0-only\b/m', $readme);
    }

    public function test_every_php_file_carries_the_gpl_2_header(): void
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f): bool {
                    return !in_array($f->getFilename(), ['vendor', '.git', 'node_modules'], true);
                }
            )
        );
        foreach ($it as $f)
        {
            if ($f->isFile() && $f->getExtension() === 'php')
            {
                $files[] = $f->getPathname();
            }
        }

        $this->assertGreaterThan(20, count($files), 'expected to find the extension PHP files');

        $missing = [];
        foreach ($files as $path)
        {
            if (strpos((string) file_get_contents($path), self::PHP_HEADER) === false)
            {
                $missing[] = substr($path, strlen(self::ROOT) + 1);
            }
        }
        $this->assertSame([], $missing, 'PHP files without the GPL-2.0 @license header');
    }
}
