<?php
/**
 * Guards for the beta version and the publish workflow (.github/workflows/release.yml).
 *
 * Once the repo is public, a job that asks for the self-hosted runner from a pull request
 * would run code from anyone's fork on the operator's machine. These tests keep the
 * publish workflow honest:
 *   - composer.json carries a beta version (MAJOR.MINOR.PATCH-beta.N), the image version;
 *   - release.yml has no pull_request / pull_request_target trigger;
 *   - version, lint-and-test and github-release use the one runs-on line that reads
 *     RUNS_ON_PUBLISH only for main and v* tag refs and falls back to hosted ubuntu-latest;
 *   - the image job (build, push, sign) and notify-site run on literal ubuntu-latest, never
 *     on a self-hosted runner; the image job signs its pushed DIGEST with cosign keyless and
 *     pushes provenance and SBOM attestations, and only it holds id-token / attestations;
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

    /** @return array<string, string> job name => the job's lines (comments included) */
    private function jobs(): array
    {
        $jobs = [];
        $current = null;
        $inside = false;
        foreach (explode("\n", $this->workflow()) as $line)
        {
            if (preg_match('/^jobs:\s*$/', $line))
            {
                $inside = true;
                continue;
            }
            if (!$inside)
            {
                continue;
            }
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $m))
            {
                $current = $m[1];
                $jobs[$current] = '';
                continue;
            }
            if ($current !== null)
            {
                $jobs[$current] .= $line . "\n";
            }
        }
        return $jobs;
    }

    public function test_every_job_uses_the_guarded_runs_on_line(): void
    {
        $lines = preg_grep('/^\s*runs-on:/', explode("\n", $this->workflow()));
        $this->assertCount(5, $lines, 'expected one runs-on line per job (version, lint-and-test, image, github-release, notify-site)');
        $jobs = $this->jobs();
        $this->assertSame(['version', 'lint-and-test', 'image', 'github-release', 'notify-site'], array_keys($jobs));
        $hosted = ['image' => true, 'notify-site' => true];
        foreach ($jobs as $name => $body)
        {
            $runs = array_values(array_map('trim', preg_grep('/^    runs-on:/', explode("\n", $body))));
            // The signed publish job and notify-site never use the self-hosted runner.
            $want = isset($hosted[$name]) ? 'runs-on: ubuntu-latest' : self::RUNS_ON;
            $this->assertSame([$want], $runs, "job {$name} runs-on line");
        }
    }

    public function test_image_job_signs_and_attests_the_pushed_digest(): void
    {
        $jobs = $this->jobs();
        $image = preg_replace('/^\s*#.*$/m', '', $jobs['image'] ?? '');
        $this->assertMatchesRegularExpression(
            '/^    permissions:\n      contents: read\n      packages: write\n      id-token: write\n      attestations: write\n    [a-z]/m',
            $image,
            'image job permissions are exactly contents read, packages/id-token/attestations write'
        );
        foreach ($jobs as $name => $body)
        {
            if ($name !== 'image')
            {
                $this->assertDoesNotMatchRegularExpression('/^\s+(id-token|attestations):/m', $body, "{$name} must not hold id-token or attestations");
            }
        }
        $build = strpos($image, 'id: build');
        $signref = strpos($image, 'id: signref');
        $this->assertNotFalse($build);
        $this->assertNotFalse($signref);
        $this->assertLessThan($signref, $build, 'the digest is resolved after the build step');
        $after = substr($image, $signref);
        $this->assertStringContainsString('DIGEST: ${{ steps.build.outputs.digest }}', $image);
        $this->assertStringContainsString('sha256:[0-9a-f]{64}', $after);
        $this->assertStringContainsString('"latest" in tags', $after);
        $this->assertStringContainsString('run: cosign sign --yes "$REF"', $after);
        $this->assertStringContainsString('REF: ${{ steps.signref.outputs.repo }}@${{ steps.signref.outputs.digest }}', $after);
        $this->assertStringContainsString('uses: actions/attest-build-provenance@', $after);
        $this->assertStringContainsString('uses: actions/attest-sbom@', $after);
        $this->assertSame(2, substr_count($after, 'subject-digest: ${{ steps.signref.outputs.digest }}'));
        $this->assertSame(2, substr_count($after, 'push-to-registry: true'));
        $this->assertStringNotContainsString('push-to-registry: false', $after);
    }

    public function test_notify_site_has_no_permissions_and_runs_only_for_v_tags(): void
    {
        $note = preg_replace('/^\s*#.*$/m', '', $this->jobs()['notify-site'] ?? '');
        $this->assertMatchesRegularExpression('/^    permissions: \{\}$/m', $note);
        $this->assertDoesNotMatchRegularExpression('/^    permissions:\s*$/m', $note);
        $this->assertStringContainsString("if: startsWith(github.ref, 'refs/tags/v')", $note);
        $this->assertStringContainsString('needs: [github-release]', $note);
    }

    public function test_no_image_is_tagged_latest(): void
    {
        // Full-line comments may name the tag to say it is never used; code may not.
        $code = preg_replace('/^\s*#.*$/m', '', $this->workflow());
        $this->assertStringNotContainsString(':latest', $code);
    }
}
