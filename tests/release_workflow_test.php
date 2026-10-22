<?php
/**
 * Guards for the beta version and the publish workflow (.github/workflows/release.yml).
 *
 * Once the repo is public, a job that asks for the self-hosted runner from a pull request
 * would run code from anyone's fork on the operator's machine. These tests keep the
 * publish workflow honest:
 *   - composer.json carries a beta version (MAJOR.MINOR.PATCH-beta.N), the image version;
 *   - release.yml has no pull_request / pull_request_target trigger;
 *   - every job uses the one runs-on line that reads RUNS_ON_PUBLISH only for main and
 *     v* tag refs and falls back to hosted ubuntu-latest;
 *   - no image is ever tagged :latest.
 *
 * Cutting a final (non-beta) release is a deliberate decision: change the version test
 * below in the same pull request.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;

class release_workflow_test extends TestCase
{
    private const RUNS_ON = "runs-on: \${{ fromJSON(github.event_name != 'pull_request' && github.event_name != 'pull_request_target' && (github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v') || startsWith(github.ref, 'refs/tags/studio-v')) && vars.RUNS_ON_PUBLISH || '\"ubuntu-latest\"') }}";

    private function workflow(): string
    {
        $path = __DIR__ . '/../.github/workflows/release.yml';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_composer_version_is_a_beta(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+-beta\.[1-9]\d*$/',
            $composer['version'] ?? '',
            'composer.json version must be MAJOR.MINOR.PATCH-beta.N (it is the image version)'
        );
    }

    public function test_release_workflow_has_no_pull_request_trigger(): void
    {
        $wf = $this->workflow();
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*-?\s*pull_request(_target)?\s*:?\s*$/m',
            $wf,
            'release.yml must never run on pull_request or pull_request_target'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^on:.*pull_request/m',
            $wf,
            'release.yml must never run on pull_request or pull_request_target'
        );
    }

    public function test_release_workflow_publishes_on_main_and_v_tags(): void
    {
        $wf = $this->workflow();
        $this->assertMatchesRegularExpression('/^\s+branches: \[main\]$/m', $wf);
        $this->assertMatchesRegularExpression("/^\\s+tags: \\['v\\*'\\]$/m", $wf);
    }

    public function test_every_job_uses_the_guarded_runs_on_line(): void
    {
        $lines = preg_grep('/^\s*runs-on:/', explode("\n", $this->workflow()));
        $this->assertCount(4, $lines, 'expected one runs-on line per job (version, lint-and-test, image, github-release)');
        foreach ($lines as $line)
        {
            $this->assertSame(self::RUNS_ON, trim($line));
        }
    }

    public function test_no_image_is_tagged_latest(): void
    {
        // Full-line comments may name the tag to say it is never used; code may not.
        $code = preg_replace('/^\s*#.*$/m', '', $this->workflow());
        $this->assertStringNotContainsString(':latest', $code);
    }
}
